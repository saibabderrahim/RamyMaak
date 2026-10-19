import { screen, waitFor, within } from '@testing-library/react'
import { onlineManager } from '@tanstack/react-query'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import type { ClientToday, Order } from '../../../shared/api/types'
import { purgeSessionStorage } from '../../../shared/storage'
import { json, problem } from '../../../test/utils'
import {
  CANCEL_URL,
  closedOrder,
  DAY,
  lbenLine,
  makeOrder,
  makeProduct,
  makeSummary,
  makeToday,
  ORDER_ID,
  ORDER_NUMBER,
  ORDER_URL,
  orangeLine,
  ordersApi,
  pomme,
  pommeLine,
  renderOrders,
  storedCart,
  testCart,
  TODAY_URL,
} from './testing'

afterEach(() => purgeSessionStorage())

const PATH = `/client/commandes/${ORDER_ID}`
const dialog = () => screen.getByRole('dialog')

/** Détail d'une commande servi par l'API simulée (`order` relu à chaque lecture), journée proposée `today`. */
async function renderDetail(order: () => Order, routes: Parameters<typeof ordersApi>[0] = {}) {
  const api = ordersApi({ [ORDER_URL]: () => json(order()), ...routes })
  renderOrders(PATH)
  await screen.findByRole('heading', { level: 1, name: `Commande ${ORDER_NUMBER}` })
  return api
}

/** Panier d'une autre commande en cours : 3 Jus pomme 1 L. */
function fillCart() {
  testCart().setQuantity(DAY, pomme.id, 3)
}

describe('modify an order (RG-C05)', () => {
  it('loads the order into the cart and opens the catalogue', async () => {
    await renderDetail(() => makeOrder({ version: 2, comment: 'Livraison avant 10 h' }))

    await userEvent.click(screen.getByRole('button', { name: 'Modifier la commande' }))

    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/client/catalogue'))
    expect(storedCart()).toMatchObject({
      day: DAY,
      lines: [
        { product_id: 42, quantity: 40 },
        { product_id: 57, quantity: 25 },
        { product_id: 61, quantity: 10 },
      ],
      comment: 'Livraison avant 10 h',
      editing: { orderId: ORDER_ID, number: ORDER_NUMBER, version: 2 },
      pending: null,
    })
  })

  it('replaces another cart only after confirmation', async () => {
    fillCart()
    await renderDetail(() => makeOrder())

    await userEvent.click(screen.getByRole('button', { name: 'Modifier la commande' }))
    expect(dialog()).toHaveTextContent('Votre panier contient 1 produit')
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Annuler' }))
    expect(storedCart()?.lines).toEqual([{ product_id: pomme.id, quantity: 3 }])
    expect(screen.getByTestId('where')).toHaveTextContent(PATH)

    await userEvent.click(screen.getByRole('button', { name: 'Modifier la commande' }))
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Remplacer mon panier' }))
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/client/catalogue'))
    expect(storedCart()?.editing?.orderId).toBe(ORDER_ID)
    expect(storedCart()?.lines).toHaveLength(3)
  })

  it('asks before abandoning the modification of another order, even with no line left', async () => {
    testCart().startEditing({ id: '01JA2Z6K8QW3X9M4T7RBN5OTHR', number: 'RMK-261008-00007', version: 1, day: DAY, comment: null, lines: [] })
    await renderDetail(() => makeOrder())

    await userEvent.click(screen.getByRole('button', { name: 'Modifier la commande' }))
    expect(dialog()).toHaveTextContent(
      `La modification en cours de la commande RMK-261008-00007 sera abandonnée : le panier prendra le contenu de la commande ${ORDER_NUMBER}.`,
    )
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Remplacer mon panier' }))
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/client/catalogue'))
    expect(storedCart()?.editing?.orderId).toBe(ORDER_ID)
  })
})

describe('modify an order offline', () => {
  afterEach(() => onlineManager.setOnline(true))

  it('fails at once with a message and never replaces the cart when the network comes back', async () => {
    fillCart()
    let offline = false
    const api = await renderDetail(() => {
      if (offline) throw new TypeError('Failed to fetch')
      return makeOrder()
    })
    offline = true
    onlineManager.setOnline(false)
    const reads = api.requests(`GET /api/v1/client/orders/${ORDER_ID}`).length

    await userEvent.click(screen.getByRole('button', { name: 'Modifier la commande' }))
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Remplacer mon panier' }))

    // Lecture tentée tout de suite (pas suspendue) ; l'échec ferme la confirmation.
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(api.requests(`GET /api/v1/client/orders/${ORDER_ID}`).length).toBeGreaterThan(reads)
    offline = false
    onlineManager.setOnline(true)
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(storedCart()?.lines).toEqual([{ product_id: pomme.id, quantity: 3 }])
    expect(storedCart()?.editing).toBeNull()
    expect(screen.getByTestId('where')).toHaveTextContent(PATH)
  })
})

describe('modify an order no longer modifiable', () => {
  it('leaves the cart alone when the order read again cannot be modified any more', async () => {
    let order = makeOrder()
    await renderDetail(() => order)
    order = closedOrder({ status: 'VALIDATED' })

    await userEvent.click(screen.getByRole('button', { name: 'Modifier la commande' }))

    await waitFor(() => expect(screen.queryByRole('button', { name: 'Modifier la commande' })).not.toBeInTheDocument())
    expect(within(screen.getByRole('region', { name: 'Commande du jeudi 08/10/2026' })).getByText('Validée')).toBeInTheDocument()
    expect(screen.getByTestId('where')).toHaveTextContent(PATH)
    expect(storedCart()).toBeNull()
    // Le message reste, même si le bouton disparaît avec le droit de modifier.
    expect(screen.getByText('Cette commande ne peut plus être modifiée.')).toBeInTheDocument()
  })
})

describe('cancel an order (I-52)', () => {
  it('cancels after confirmation with the version read, then shows the cancelled order', async () => {
    let order = makeOrder({ version: 3 })
    const api = await renderDetail(() => order, {
      [CANCEL_URL]: () => {
        order = closedOrder({
          status: 'CANCELLED',
          status_label: 'Annulée',
          version: 4,
          history: [...order.history, { from: 'SUBMITTED', to: 'CANCELLED', version: 4, at: '2026-10-08T09:30:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0012', comment: null }],
        })
        return json(order)
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Annuler la commande' }))
    expect(dialog()).toHaveAccessibleName(`Annuler la commande ${ORDER_NUMBER} ?`)
    expect(dialog()).toHaveTextContent('La commande sera annulée et ses quantités libérées. Cette action est définitive.')
    // Le choix sûr a le focus.
    expect(within(dialog()).getByRole('button', { name: 'Garder la commande' })).toHaveFocus()
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Oui, annuler la commande' }))

    expect(await screen.findByText(`Commande ${ORDER_NUMBER} annulée.`)).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.requests(CANCEL_URL).map((call) => call.body)).toEqual([{ version: 3 }])
    const overview = screen.getByRole('region', { name: 'Commande du jeudi 08/10/2026' })
    expect(within(overview).getByText('Annulée')).toHaveAttribute('data-status', 'CANCELLED')
    expect(within(overview).getAllByRole('button').map((button) => button.textContent)).toEqual(['Recommander'])
  })

  it('keeps the order when the cancellation is not confirmed', async () => {
    const api = await renderDetail(() => makeOrder())
    await userEvent.click(screen.getByRole('button', { name: 'Annuler la commande' }))
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Garder la commande' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.requests(CANCEL_URL)).toHaveLength(0)
  })

  it('drops the modification of this order in progress in the cart', async () => {
    testCart().startEditing(makeOrder())
    let order = makeOrder()
    await renderDetail(() => order, {
      [CANCEL_URL]: () => {
        order = closedOrder({ status: 'CANCELLED', version: 2 })
        return json(order)
      },
    })

    expect(screen.getByText('Cette commande est en cours de modification dans votre panier.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Annuler la commande' }))
    expect(dialog()).toHaveTextContent('La modification de cette commande en cours dans votre panier sera abandonnée.')
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Oui, annuler la commande' }))

    expect(await screen.findByText(`Commande ${ORDER_NUMBER} annulée.`)).toBeInTheDocument()
    expect(storedCart()).toBeNull()
  })

  it('reloads the order changed meanwhile (VERSION_CONFLICT) and says so', async () => {
    let order = makeOrder({ version: 1 })
    const api = await renderDetail(() => order, {
      [CANCEL_URL]: () => {
        order = makeOrder({ version: 2, lines: [orangeLine], lines_count: 1 })
        return problem(409, 'VERSION_CONFLICT', 'La commande a été modifiée entre-temps : rechargez-la.', { version: 2 })
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Annuler la commande' }))
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Oui, annuler la commande' }))

    expect(
      await screen.findByText(
        `La commande ${ORDER_NUMBER} a été modifiée entre-temps, depuis un autre appareil ou un autre compte : elle a été rechargée. Vérifiez-la, puis recommencez si besoin.`,
      ),
    ).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('article', { name: 'Jus pomme 1 L' })).not.toBeInTheDocument())
    expect(api.requests(ORDER_URL).length).toBeGreaterThanOrEqual(2)

    // Nouvelle tentative avec la version relue.
    await userEvent.click(screen.getByRole('button', { name: 'Annuler la commande' }))
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Oui, annuler la commande' }))
    await waitFor(() => expect(api.requests(CANCEL_URL).map((call) => call.body)).toEqual([{ version: 1 }, { version: 2 }]))
  })

  it.each([
    ['CUTOFF_PASSED', 'L’heure limite de commande (13:00) est dépassée.'],
    ['INVALID_TRANSITION', 'Commande « Validée » : impossible de passer à « Annulée ».'],
  ])('shows the refusal %s from the server and reloads the order', async (code, detail) => {
    let order = makeOrder()
    const api = await renderDetail(() => order, {
      [CANCEL_URL]: () => {
        order = closedOrder({ status: code === 'CUTOFF_PASSED' ? 'SUBMITTED' : 'VALIDATED' })
        return problem(409, code, detail)
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Annuler la commande' }))
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Oui, annuler la commande' }))

    expect(await screen.findByText(detail)).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Annuler la commande' })).not.toBeInTheDocument())
    expect(api.requests(ORDER_URL).length).toBeGreaterThanOrEqual(2)
  })

  it('keeps the confirmation open on a network error, to try again', async () => {
    let offline = true
    const api = await renderDetail(() => makeOrder(), {
      [CANCEL_URL]: () => {
        if (offline) throw new TypeError('Failed to fetch')
        return json(closedOrder({ status: 'CANCELLED', version: 2 }))
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Annuler la commande' }))
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Oui, annuler la commande' }))
    expect(await within(dialog()).findByText('Connexion impossible au serveur. Vérifiez votre réseau.')).toBeInTheDocument()

    offline = false
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Oui, annuler la commande' }))
    expect(await screen.findByText(`Commande ${ORDER_NUMBER} annulée.`)).toBeInTheDocument()
    expect(api.requests(CANCEL_URL)).toHaveLength(2)
  })
})

describe('Recommander (EF-CLT-05)', () => {
  /** Commande passée de la veille, validée : 40 Jus orange 1 L, 25 Jus pomme 1 L, 10 Lben 1 L. */
  const pastOrder = () => closedOrder({ status: 'VALIDATED', day: '2026-10-07' })

  const reorder = async (today: ClientToday, order: () => Order = pastOrder) => {
    const api = await renderDetail(order, { [TODAY_URL]: () => json(today) })
    await userEvent.click(screen.getByRole('button', { name: 'Recommander' }))
    return api
  }

  it('summarises the skipped and capped products, then rebuilds the cart on the proposed day and opens it', async () => {
    const api = await reorder(makeToday())

    expect(dialog()).toHaveAccessibleName(`Recommander la commande ${ORDER_NUMBER}`)
    expect(dialog()).toHaveTextContent('Votre panier du jeudi 08/10/2026 sera rempli avec les produits de cette commande')
    expect(dialog()).toHaveTextContent('2 produits repris.')
    const changes = within(dialog()).getAllByRole('listitem').map((item) => item.textContent)
    expect(changes).toEqual([
      '« Lben 1 L » ignoré : il n’est pas disponible pour vous ce jour-là.',
      '« Jus orange 1 L » : quantité ramenée de 40 à 30, le maximum disponible.',
    ])
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Remplir mon panier' }))

    expect(screen.getByTestId('where')).toHaveTextContent('/client/panier')
    expect(screen.getByTestId('notice')).toHaveTextContent(
      `Panier rempli à partir de la commande ${ORDER_NUMBER} : 2 produits repris. « Lben 1 L » ignoré : il n’est pas disponible pour vous ce jour-là. « Jus orange 1 L » : quantité ramenée de 40 à 30, le maximum disponible.`,
    )
    expect(storedCart()).toEqual({
      v: 1,
      day: DAY,
      lines: [
        { product_id: 42, quantity: 30 },
        { product_id: 57, quantity: 25 },
      ],
      comment: '',
      editing: null,
      pending: null,
    })
    // Journée proposée relue sur le serveur, sans paramètre (I-42).
    expect(api.queries('/api/v1/client/today')).toEqual([{}])
  })

  it('skips the sold-out products', async () => {
    await reorder(makeToday({ products: [makeProduct({ id: 42, name: 'Jus orange 1 L', limit: 50, engaged: 50, available: 0, sold_out: true }), pomme] }))

    expect(dialog()).toHaveTextContent('1 produit repris.')
    expect(dialog()).toHaveTextContent('« Jus orange 1 L » ignoré : il est épuisé.')
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Remplir mon panier' }))
    expect(storedCart()?.lines).toEqual([{ product_id: 57, quantity: 25 }])
  })

  it('opens the cart straight away when the cart is empty and nothing changes', async () => {
    const order = () => closedOrder({ status: 'EXPIRED', lines: [orangeLine, pommeLine] })
    await reorder(makeToday({ products: [makeProduct({ id: 42, name: 'Jus orange 1 L', limit: 50, available: 45 }), pomme] }), order)

    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/client/panier'))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByTestId('notice')).toHaveTextContent(`Panier rempli à partir de la commande ${ORDER_NUMBER} : 2 produits repris.`)
    expect(storedCart()?.lines).toEqual([
      { product_id: 42, quantity: 40 },
      { product_id: 57, quantity: 25 },
    ])
  })

  it('replaces a non-empty cart only after confirmation', async () => {
    fillCart()
    testCart().setComment('Ancien commentaire')
    const order = () => closedOrder({ status: 'VALIDATED', lines: [pommeLine] })
    await reorder(makeToday(), order)

    expect(dialog()).toHaveTextContent('Votre panier contient 1 produit : il sera remplacé.')
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Annuler' }))
    expect(storedCart()).toMatchObject({ lines: [{ product_id: pomme.id, quantity: 3 }], comment: 'Ancien commentaire' })
    expect(screen.getByTestId('where')).toHaveTextContent(PATH)

    await userEvent.click(screen.getByRole('button', { name: 'Recommander' }))
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Remplir mon panier' }))
    expect(storedCart()).toMatchObject({ lines: [{ product_id: pomme.id, quantity: 25 }], comment: '', editing: null })
  })

  it('abandons the modification of an order in progress, after confirmation', async () => {
    const other = makeOrder({ id: '01JA2Z6K8QW3X9M4T7RBN5CDEZ', number: 'RMK-261008-00051', lines: [lbenLine] })
    testCart().startEditing(other)
    testCart().beginSend()
    await reorder(makeToday(), () => closedOrder({ status: 'VALIDATED', lines: [pommeLine] }))

    expect(dialog()).toHaveTextContent('La modification en cours de la commande RMK-261008-00051 sera abandonnée.')
    await userEvent.click(within(dialog()).getByRole('button', { name: 'Remplir mon panier' }))
    expect(storedCart()).toMatchObject({ day: DAY, lines: [{ product_id: pomme.id, quantity: 25 }], editing: null, pending: null })
  })

  it.each([
    [
      'waiting for the quotas',
      makeToday({ status: 'WAITING_QUOTAS', ordering_cutoff: null, ordering_cutoff_at: null, can_submit: false, submit_blocker: 'DAY_NOT_OPEN', products: [] }),
      'Vos quantités du jeudi 08/10/2026 ne sont pas encore disponibles : réessayez dès l’ouverture des commandes.',
    ],
    [
      'after the deadline',
      makeToday({ status: 'ORDERING_CLOSED', can_submit: false, submit_blocker: 'CUTOFF_PASSED' }),
      'L’heure limite de commande du jeudi 08/10/2026 est passée.',
    ],
    ['on a closed day', makeToday({ status: 'DAY_CLOSED', can_submit: false, submit_blocker: 'DAY_NOT_OPEN', products: [] }), 'La journée du jeudi 08/10/2026 est clôturée.'],
    [
      'for a blocked client',
      makeToday({ client: { id: 12, code: 'C0012', name: 'Superette El Baraka', status: 'BLOCKED' }, can_submit: false, submit_blocker: 'CLIENT_BLOCKED' }),
      'Commande suspendue : votre compte client est bloqué. Contactez votre commercial régional.',
    ],
  ])('explains why it cannot reorder %s, and leaves the cart alone', async (_, today, reason) => {
    fillCart()
    await reorder(today)

    expect(await screen.findByText('Impossible de recommander pour le moment')).toBeInTheDocument()
    expect(screen.getByText(reason)).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(storedCart()?.lines).toEqual([{ product_id: pomme.id, quantity: 3 }])
    expect(screen.getByTestId('where')).toHaveTextContent(PATH)
  })

  it('points to the order of the day still modifiable when the maximum is reached', async () => {
    const todays = makeSummary({ id: '01JA2Z6K8QW3X9M4T7RBN5CDEZ', number: 'RMK-261008-00051' })
    const today = makeToday({ max_orders_per_day: 1, can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED', orders: [todays] })
    await renderDetail(pastOrder, {
      [TODAY_URL]: () => json(today),
      [`GET /api/v1/client/orders/${todays.id}`]: () => json(makeOrder({ id: todays.id, number: todays.number })),
    })
    await userEvent.click(screen.getByRole('button', { name: 'Recommander' }))

    expect(
      await screen.findByText('Vous avez déjà envoyé votre commande du jeudi 08/10/2026 : vous pouvez encore la modifier tant que les commandes sont ouvertes.'),
    ).toBeInTheDocument()
    await userEvent.click(screen.getByRole('link', { name: 'Voir la commande RMK-261008-00051' }))
    expect(screen.getByTestId('where')).toHaveTextContent('/client/commandes/01JA2Z6K8QW3X9M4T7RBN5CDEZ')
    // L'autre commande s'affiche sans l'explication restée de la précédente.
    expect(await screen.findByRole('heading', { level: 1, name: 'Commande RMK-261008-00051' })).toBeInTheDocument()
    expect(screen.queryByText('Impossible de recommander pour le moment')).not.toBeInTheDocument()
  })

  it('says so when none of the products can be ordered on the proposed day', async () => {
    fillCart()
    await reorder(makeToday({ products: [makeProduct({ id: 99, name: 'Autre produit' })] }))

    expect(await screen.findByText('Aucun produit de cette commande n’est disponible pour vous le jeudi 08/10/2026 : votre panier n’a pas été modifié.')).toBeInTheDocument()
    expect(storedCart()?.lines).toEqual([{ product_id: pomme.id, quantity: 3 }])
  })

  it('reports an error while reading the proposed day', async () => {
    await renderDetail(pastOrder, { [TODAY_URL]: () => problem(403, 'FORBIDDEN', 'Accès refusé.') })
    await userEvent.click(screen.getByRole('button', { name: 'Recommander' }))
    expect(await screen.findByText('Accès refusé.')).toBeInTheDocument()
    expect(storedCart()).toBeNull()
  })
})
