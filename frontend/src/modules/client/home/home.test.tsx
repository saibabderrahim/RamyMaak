import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ClientToday } from '../../../shared/api/types'
import { purgeSessionStorage } from '../../../shared/storage'
import { json } from '../../../test/utils'
import {
  type ApiHandler,
  clientApi,
  DAY,
  makeOrder,
  makeSummary,
  makeToday,
  ORDER_ID,
  ORDER_NUMBER,
  orange,
  pomme,
  renderClient,
  storedCart,
  testCart,
} from '../cart/testing'

afterEach(() => {
  vi.useRealTimers()
  // Paniers partagés en mémoire entre les tests : vidés avec le stockage.
  purgeSessionStorage()
})

const homeApi = (today: ClientToday | ((call: number, url: URL) => ClientToday), routes: Record<string, ApiHandler> = {}) => {
  let call = 0
  return clientApi((url) => json(typeof today === 'function' ? today(++call, url) : today), routes)
}

// Journée lue pour modifier la commande (`?order=`) : disponible hors de ses quantités (I-62).
const editingDay = (base: ClientToday) => (_: number, url: URL) =>
  url.searchParams.has('order') ? { ...base, products: base.products.map((product) => (product.id === orange.id ? { ...product, engaged: 0, available: 50 } : product)) } : base

const dayStatus = () => screen.getByTestId('day-status')

describe('client home (EF-CLT-01)', () => {
  it.each<[string, Partial<ClientToday>, string]>([
    ['waiting for the quotas', { status: 'WAITING_QUOTAS', can_submit: false, submit_blocker: 'DAY_NOT_OPEN', products: [] }, 'En attente des quotas du jour'],
    ['open until the cutoff of the region', {}, 'Commandes ouvertes jusqu’à 13:00'],
    [
      'open with an extended cutoff',
      { ordering_cutoff: '13:30', ordering_cutoff_at: '2026-10-08T12:30:00+00:00', ordering_cutoff_extended: true },
      'Commandes ouvertes jusqu’à 13:30 (prolongée)',
    ],
    ['closed after the cutoff', { status: 'ORDERING_CLOSED', can_submit: false, submit_blocker: 'CUTOFF_PASSED' }, 'Commandes clôturées'],
    ['closed with the day', { status: 'DAY_CLOSED', can_submit: false, submit_blocker: 'DAY_NOT_OPEN', products: [] }, 'Commandes clôturées'],
  ])('shows the day %s', async (_, overrides, banner) => {
    homeApi(makeToday(overrides))

    renderClient('/client')

    expect(await screen.findByRole('heading', { name: 'Commande du jeudi 08/10/2026' })).toBeInTheDocument()
    expect(dayStatus()).toHaveTextContent(new RegExp(`^${banner.replace(/[()]/g, '\\$&')}$`))
    expect(screen.getByText('Livraison le dimanche 11/10/2026')).toBeInTheDocument()
    expect(screen.getByText('C0012 · Superette El Baraka')).toBeInTheDocument()
    expect(screen.queryByText('Commande suspendue')).not.toBeInTheDocument()
  })

  it('offers « Commander » only when an order can be sent', async () => {
    homeApi(makeToday())
    const { unmount } = renderClient('/client')

    const order = await screen.findByRole('link', { name: 'Commander' })
    expect(order).toHaveAttribute('href', '/client/catalogue')
    expect(screen.queryByRole('button', { name: 'Modifier ma commande' })).not.toBeInTheDocument()
    unmount()

    // Heure limite passée, journée pas encore ouverte : aucun bouton.
    for (const overrides of [
      { status: 'ORDERING_CLOSED', can_submit: false, submit_blocker: 'CUTOFF_PASSED', orders: [makeSummary({ can_modify: false, can_cancel: false })] },
      { status: 'WAITING_QUOTAS', can_submit: false, submit_blocker: 'DAY_NOT_OPEN', delivery_date: null, ordering_cutoff: null, ordering_cutoff_at: null },
    ] satisfies Partial<ClientToday>[]) {
      homeApi(makeToday(overrides))
      const view = renderClient('/client')
      await screen.findByTestId('day-status')
      expect(screen.queryByRole('link', { name: 'Commander' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Modifier ma commande' })).not.toBeInTheDocument()
      view.unmount()
    }
    // Journée non ouverte : pas de date de livraison.
    expect(screen.queryByText(/^Livraison le/)).not.toBeInTheDocument()
  })

  it('shows « Commande suspendue » to a blocked client, without the reason and without any button', async () => {
    homeApi(
      makeToday({
        client: { id: 12, code: 'C0012', name: 'Superette El Baraka', status: 'BLOCKED' },
        can_submit: false,
        submit_blocker: 'CLIENT_BLOCKED',
        orders: [makeSummary({ can_modify: false })],
      }),
    )

    renderClient('/client')

    expect(await screen.findByText('Commande suspendue')).toBeInTheDocument()
    expect(dayStatus()).toHaveTextContent('Commandes ouvertes jusqu’à 13:00')
    expect(screen.queryByRole('link', { name: 'Commander' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Modifier ma commande' })).not.toBeInTheDocument()
  })

  it('lists the orders of the day with their status and total, linked to their detail', async () => {
    homeApi(
      makeToday({
        max_orders_per_day: 2,
        orders: [
          makeSummary(),
          makeSummary({ id: '01JA2Z6K8QW3X9M4T7RBN5CDEG', number: 'RMK-261008-00007', status: 'CANCELLED', status_label: 'Annulée', lines_count: 1, total_ttc: 1071, can_modify: false, can_cancel: false }),
        ],
      }),
    )

    renderClient('/client')

    const list = await screen.findByRole('list')
    const [first, second] = within(list).getAllByRole('link') as [HTMLElement, HTMLElement]
    expect(first).toHaveAttribute('href', `/client/commandes/${ORDER_ID}`)
    expect(first).toHaveTextContent(ORDER_NUMBER)
    expect(first).toHaveTextContent('2 produits · 69 615,00 DA TTC')
    expect(within(first).getByText('Envoyée')).toBeInTheDocument()
    expect(second).toHaveTextContent('RMK-261008-00007')
    expect(second).toHaveTextContent('1 produit · 1 071,00 DA TTC')
    expect(within(second).getByText('Annulée')).toBeInTheDocument()
  })

  it('says when there is no order for the day', async () => {
    homeApi(makeToday())
    renderClient('/client')
    expect(await screen.findByText('Aucune commande pour cette journée.')).toBeInTheDocument()
  })

  it('loads the order of the day for « Modifier ma commande » once the maximum is reached, then opens the catalogue', async () => {
    const user = userEvent.setup()
    const { requests, todayQueries } = homeApi(editingDay(makeToday({ can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED', orders: [makeSummary({ version: 2 })] })), {
      [`GET /api/v1/client/orders/${ORDER_ID}`]: () => json(makeOrder({ version: 2, comment: 'Livrer avant 10 h' })),
    })

    renderClient('/client')

    expect(screen.queryByRole('link', { name: 'Commander' })).not.toBeInTheDocument()
    await user.click(await screen.findByRole('button', { name: 'Modifier ma commande' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(/^\/client\/catalogue$/))
    expect(requests(`GET /api/v1/client/orders/${ORDER_ID}`)).toHaveLength(1)
    // Le catalogue lit la journée de la commande, hors de ses quantités.
    await waitFor(() => expect(todayQueries().at(-1)).toEqual({ day: DAY, order: ORDER_ID }))
    expect(storedCart()).toEqual({
      v: 1,
      day: DAY,
      lines: [{ product_id: orange.id, quantity: 45 }],
      comment: 'Livrer avant 10 h',
      editing: expect.objectContaining({ orderId: ORDER_ID, number: ORDER_NUMBER, version: 2 }),
      pending: null,
    })
  })

  it('asks before replacing a cart that holds other products', async () => {
    const user = userEvent.setup()
    testCart().setQuantity(DAY, pomme.id, 3)
    homeApi(editingDay(makeToday({ can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED', orders: [makeSummary()] })), {
      [`GET /api/v1/client/orders/${ORDER_ID}`]: () => json(makeOrder()),
    })

    renderClient('/client')

    expect(await screen.findByText('Votre panier : 1 produit')).toBeInTheDocument()
    await user.click(await screen.findByRole('button', { name: 'Modifier ma commande' }))
    const dialog = screen.getByRole('dialog', { name: `Modifier la commande ${ORDER_NUMBER}` })
    expect(dialog).toHaveTextContent(`Votre panier contient 1 produit : il sera remplacé par le contenu de la commande ${ORDER_NUMBER}.`)

    // Annuler : le panier reste tel quel.
    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    expect(testCart().get().lines).toEqual([{ product_id: pomme.id, quantity: 3 }])

    await user.click(screen.getByRole('button', { name: 'Modifier ma commande' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Remplacer mon panier' }))
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(/^\/client\/catalogue$/))
    expect(testCart().get().lines).toEqual([{ product_id: orange.id, quantity: 45 }])
    expect(testCart().get().editing?.orderId).toBe(ORDER_ID)
  })

  it('says when the order can no longer be modified', async () => {
    const user = userEvent.setup()
    homeApi(makeToday({ can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED', orders: [makeSummary()] }), {
      [`GET /api/v1/client/orders/${ORDER_ID}`]: () => json(makeOrder({ status: 'VALIDATED', status_label: 'Validée', can_modify: false, can_cancel: false })),
    })

    renderClient('/client')
    await user.click(await screen.findByRole('button', { name: 'Modifier ma commande' }))

    expect(await screen.findByText('Cette commande ne peut plus être modifiée.')).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/client$/)
    expect(testCart().get().editing).toBeNull()
  })

  it('reminds the cart saved on the device, or the modification in progress', async () => {
    testCart().setQuantity(DAY, orange.id, 3)
    testCart().setQuantity(DAY, pomme.id, 2)
    homeApi(makeToday())
    const { unmount } = renderClient('/client')

    expect(await screen.findByText('Votre panier : 2 produits')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Voir le panier' })).toHaveAttribute('href', '/client/panier')
    unmount()

    testCart().startEditing(makeOrder())
    renderClient('/client')
    expect(await screen.findByText(`Modification de la commande ${ORDER_NUMBER} en cours : 1 produit`)).toBeInTheDocument()
  })

  it('empties a cart left on another day, with a notice', async () => {
    testCart().setQuantity('2026-10-07', orange.id, 3)
    homeApi(makeToday())

    renderClient('/client')

    expect(await screen.findByText('Votre panier du mercredi 07/10/2026 a été vidé : la journée de commande a changé.')).toBeInTheDocument()
    expect(storedCart()).toBeNull()
    expect(screen.queryByText(/^Votre panier :/)).not.toBeInTheDocument()
  })

  it('reads the day again at the cutoff, on the clock of the server', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    // Horloge de l'appareil fausse (dix jours d'avance) : le serveur dit 11:59:50 UTC, heure limite 12:00 UTC.
    const open = makeToday({ server_time: '2026-10-08T11:59:50+00:00' })
    const closed = makeToday({ server_time: '2026-10-08T12:00:01+00:00', status: 'ORDERING_CLOSED', can_submit: false, submit_blocker: 'CUTOFF_PASSED' })
    const { todayQueries } = homeApi((call) => (call === 1 ? open : closed))

    renderClient('/client')

    await screen.findByRole('link', { name: 'Commander' })
    expect(todayQueries()).toHaveLength(1)
    await act(() => vi.advanceTimersByTimeAsync(5_000))
    expect(todayQueries()).toHaveLength(1)
    await act(() => vi.advanceTimersByTimeAsync(7_000))
    await waitFor(() => expect(todayQueries()).toHaveLength(2))
    await waitFor(() => expect(dayStatus()).toHaveTextContent('Commandes clôturées'))
    expect(screen.queryByRole('link', { name: 'Commander' })).not.toBeInTheDocument()
  })

  it('offers to retry when the day cannot be read', async () => {
    let fail = true
    const user = userEvent.setup()
    clientApi(() => (fail ? new Response(null, { status: 500 }) : json(makeToday())))

    renderClient('/client')

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    fail = false
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByRole('link', { name: 'Commander' })).toBeInTheDocument()
  })
})
