import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { DecisionViolation, RegionalOrder } from '../../../../shared/api/types'
import { disconnectRealtime } from '../../../../shared/realtime'
import { createQueryClient } from '../../../../shared/api/queryClient'
import { json, problem } from '../../../../test/utils'
import { DAY, decidable, makeOrder, ORDER_ID, orderUrl, regional, rejectUrl, renderOrders, validateUrl } from '../testing'
import { draftStore } from './drafts'

// Temps réel simulé (même principe que shared/realtime/realtime.test.tsx).
const fake = vi.hoisted(() => ({
  stateChange: null as null | ((change: { current: string }) => void),
  listeners: new Map<string, Set<(payload: unknown) => void>>(),
}))

vi.mock('pusher-js', () => ({
  default: class {
    connection = { bind: (_: string, callback: (change: { current: string }) => void) => (fake.stateChange = callback) }
  },
}))

vi.mock('laravel-echo', () => ({
  default: class {
    private(channel: string) {
      return {
        listen: (event: string, listener: (payload: unknown) => void) => {
          const key = `${channel}:${event}`
          fake.listeners.set(key, (fake.listeners.get(key) ?? new Set()).add(listener))
        },
        stopListening: (event: string, listener: (payload: unknown) => void) => fake.listeners.get(`${channel}:${event}`)?.delete(listener),
      }
    }
    leave() {}
    disconnect() {}
  },
}))

afterEach(() => {
  vi.restoreAllMocks()
  // Le gestionnaire de connexion de TanStack Query garde l'état « hors ligne » d'un test à l'autre.
  window.dispatchEvent(new Event('online'))
  vi.useRealTimers()
  disconnectRealtime()
  fake.stateChange = null
  fake.listeners.clear()
})

const DETAIL = `/regional/commandes/${ORDER_ID}`
const VALIDATE = validateUrl(ORDER_ID)

/** Commande dont le maximum validable est le demandé : rien ne limite la validation. */
const roomy = (overrides: Partial<RegionalOrder> = {}): RegionalOrder => {
  const order = decidable(overrides)
  return {
    ...order,
    lines: order.lines.map((line) => ({ ...line, context: { ...line.context, max_validatable: line.requested_qty, region_remaining: 5000 } })),
  }
}

/** Commande telle que le serveur la renvoie après la validation, quantités validées par produit. */
const validatedWith = (order: RegionalOrder, quantities: Record<number, number>, extra: Partial<RegionalOrder> = {}): RegionalOrder => ({
  ...order,
  status: 'VALIDATED',
  status_label: 'Validée',
  version: order.version + 1,
  decided_at: '2026-10-08T10:15:00+00:00',
  decided_by: { id: 7, name: 'Karim Régional' },
  can_validate: false,
  can_reject: false,
  is_adjusted: order.lines.some((line) => (quantities[line.product_id] ?? line.requested_qty) < line.requested_qty),
  lines: order.lines.map((line) => ({ ...line, validated_qty: quantities[line.product_id] ?? line.requested_qty })),
  ...extra,
})

const findPanel = async () => {
  await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })
  return screen.findByRole('group', { name: 'Décision sur la commande' })
}
const card = (name: string) => screen.getByRole('article', { name })
const stepper = (name: string) => screen.getByRole('textbox', { name: `Quantité de ${name}` })
const reasonOf = (name: string) => within(card(name)).getByRole('combobox', { name: /Motif d’ajustement/ })
const commentOf = (name: string) => within(card(name)).getByRole('textbox', { name: /Commentaire/ })
const validate = (label: string | RegExp = /^Valider/) => screen.getByRole('button', { name: label })

/** Saisit une quantité (validée à la touche Entrée). */
async function typeQuantity(user: ReturnType<typeof userEvent.setup>, name: string, value: number) {
  await user.clear(stepper(name))
  await user.type(stepper(name), `${value}{Enter}`)
}

async function chooseReason(user: ReturnType<typeof userEvent.setup>, name: string, label: string) {
  await waitFor(() => expect(reasonOf(name)).toBeEnabled())
  await user.selectOptions(reasonOf(name), label)
}

// Espaces insécables (milliers, unités) ramenés à des espaces.
const itemsOf = (list: HTMLElement) =>
  within(list)
    .getAllByRole('listitem')
    .map((item) => item.textContent?.replace(/\u00a0/g, ' '))
const factsOf = (name: string) => itemsOf(screen.getByRole('list', { name: `Quantités de « ${name} »` }))
const afterOf = (name: string) => itemsOf(screen.getByRole('list', { name: `Reste après validation de « ${name} »` }))

describe('regional order — decision panel', () => {
  it('shows each line to decide with its context, the quantity to validate and what remains after the validation', async () => {
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(decidable()) } })
    await findPanel()

    // Les lignes en lecture seule du lot 5 cèdent la place aux lignes à décider.
    const lines = within(screen.getByRole('list', { name: 'Produits commandés' })).getAllByRole('article')
    expect(lines.map((line) => line.getAttribute('aria-label'))).toEqual(['Jus orange 1 L', 'Lben 1 L'])
    expect(card('Jus orange 1 L')).toHaveTextContent('P0042 · Fardeau 6 × 1 L · 900,00 DA HT l’unité de vente')

    // Demandé, borne, déjà validé pour ce client, reste région, maximum validable.
    expect(factsOf('Jus orange 1 L')).toEqual(['Demandé : 40', 'Borne : 50', 'Déjà validé pour ce client : 0', 'Reste région : 1 500', 'Maximum validable : 40'])
    expect(factsOf('Lben 1 L')).toEqual(['Demandé : 1 250', 'Pas de borne', 'Déjà validé pour ce client : 30', 'Reste région : 720', 'Maximum validable : 720'])

    // Reste après validation : région, et borne quand il y en a une.
    expect(afterOf('Jus orange 1 L')).toEqual(['Reste région après validation : 1 460', 'Reste borne après validation : 10'])
    expect(afterOf('Lben 1 L')).toEqual(['Reste région après validation : -530'])

    expect(stepper('Jus orange 1 L')).toHaveValue('40')
    expect(stepper('Lben 1 L')).toHaveValue('1250')
    // Ligne entière : ni motif ni commentaire.
    expect(within(card('Jus orange 1 L')).queryByRole('combobox')).not.toBeInTheDocument()
    expect(screen.getByText('Validation avant 15:00')).toBeInTheDocument()

    expect(validate('Valider')).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Rejeter' })).toBeEnabled()
  })

  it('flags a line above its maximum validable and offers to apply it', async () => {
    const user = userEvent.setup()
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(decidable()) } })
    await findPanel()

    expect(within(card('Lben 1 L')).getByText('Au-dessus du maximum validable : 720 au plus.')).toBeInTheDocument()
    expect(within(card('Jus orange 1 L')).queryByText(/Au-dessus du maximum/)).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Appliquer 720 à « Lben 1 L »' }))
    expect(stepper('Lben 1 L')).toHaveValue('720')
    expect(within(card('Lben 1 L')).queryByText(/Au-dessus du maximum/)).not.toBeInTheDocument()
    expect(afterOf('Lben 1 L')).toEqual(['Reste région après validation : 0'])
    // La ligne est réduite : motif et commentaire (visible du client) à renseigner.
    expect(reasonOf('Lben 1 L')).toBeInTheDocument()
    expect(within(card('Lben 1 L')).getByText('Visible par le client · 0 / 200 caractères')).toBeInTheDocument()
  })

  it('validates the order as requested, with every line and the version read', async () => {
    const user = userEvent.setup()
    const order = roomy()
    const { calls, queryClient } = renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(order),
        [VALIDATE]: () => json(validatedWith(order, {})),
      },
    })
    await findPanel()
    await user.click(validate('Valider'))

    const status = await screen.findByText('Commande RMK-261008-00042 validée.')
    // Le bouton a disparu avec le panneau : le focus passe au compte rendu.
    expect(status).toHaveFocus()
    expect(window.scrollTo).toHaveBeenCalledWith({ top: 0 })
    const post = calls.find((call) => call.method === 'POST')
    expect(post?.path).toBe(`/api/v1/regional/orders/${ORDER_ID}/validate`)
    expect(post?.body).toEqual({
      version: 2,
      lines: [
        { product_id: 42, validated_qty: 40 },
        { product_id: 57, validated_qty: 1250 },
      ],
    })

    // La commande renvoyée remplace celle du cache : décision visible, plus de panneau, brouillon effacé.
    expect(await screen.findByRole('heading', { name: 'Décision' })).toBeInTheDocument()
    expect(screen.getByText('Validée le 08/10/2026 11:15 par Karim Régional')).toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Décision sur la commande' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rejeter' })).not.toBeInTheDocument()
    expect(draftStore(queryClient).has(ORDER_ID)).toBe(false)
    expect(screen.getByRole('link', { name: 'Retour à la liste' })).toHaveAttribute('href', `/regional/commandes?day=${DAY}`)
  })

  it('needs a reason on a reduced line, announces the adjustment and sends the reason and the comment', async () => {
    const user = userEvent.setup()
    const order = roomy()
    const { calls } = renderOrders({
      path: DETAIL,
      routes: { [orderUrl(ORDER_ID)]: () => json(order), [VALIDATE]: () => json(validatedWith(order, { 57: 700 })) },
    })
    await findPanel()

    await typeQuantity(user, 'Lben 1 L', 700)
    expect(stepper('Lben 1 L')).toHaveValue('700')
    expect(validate('Valider avec ajustement (1)')).toBeEnabled()

    // Sans motif : rien n'est envoyé, le motif manquant est annoncé et reçoit le focus.
    await waitFor(() => expect(reasonOf('Lben 1 L')).toBeEnabled())
    await user.click(validate('Valider avec ajustement (1)'))
    expect(await within(card('Lben 1 L')).findByText('Choisissez un motif d’ajustement.')).toBeInTheDocument()
    expect(reasonOf('Lben 1 L')).toHaveFocus()
    expect(reasonOf('Lben 1 L')).toBeInvalid()
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0)

    await chooseReason(user, 'Lben 1 L', 'Quota insuffisant')
    expect(within(card('Lben 1 L')).queryByText('Choisissez un motif d’ajustement.')).not.toBeInTheDocument()
    await user.type(commentOf('Lben 1 L'), 'Reste région limité')
    expect(within(card('Lben 1 L')).getByText('Visible par le client · 19 / 200 caractères')).toBeInTheDocument()
    await user.click(validate('Valider avec ajustement (1)'))

    expect(await screen.findByText('Commande RMK-261008-00042 validée avec 1 ligne ajustée.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      version: 2,
      lines: [
        { product_id: 42, validated_qty: 40 },
        { product_id: 57, validated_qty: 700, reason_code: 'QUOTA_INSUFFISANT', comment: 'Reste région limité' },
      ],
    })
  })

  it('counts the reduced lines and sends no comment when it is blank', async () => {
    const user = userEvent.setup()
    const order = roomy()
    const { calls } = renderOrders({
      path: DETAIL,
      routes: { [orderUrl(ORDER_ID)]: () => json(order), [VALIDATE]: () => json(validatedWith(order, { 42: 30, 57: 0 })) },
    })
    await findPanel()

    await typeQuantity(user, 'Jus orange 1 L', 30)
    await typeQuantity(user, 'Lben 1 L', 0)
    expect(validate('Valider avec ajustement (2)')).toBeEnabled()
    await chooseReason(user, 'Jus orange 1 L', 'Borne atteinte')
    await chooseReason(user, 'Lben 1 L', 'Quota insuffisant')
    await user.click(validate('Valider avec ajustement (2)'))

    expect(await screen.findByText('Commande RMK-261008-00042 validée avec 2 lignes ajustées.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      version: 2,
      lines: [
        { product_id: 42, validated_qty: 30, reason_code: 'BORNE_ATTEINTE', comment: null },
        { product_id: 57, validated_qty: 0, reason_code: 'QUOTA_INSUFFISANT', comment: null },
      ],
    })
  })

  it('requires a comment with a reason which asks for one (AUTRE)', async () => {
    const user = userEvent.setup()
    const order = roomy()
    const { calls } = renderOrders({
      path: DETAIL,
      routes: { [orderUrl(ORDER_ID)]: () => json(order), [VALIDATE]: () => json(validatedWith(order, { 42: 10 })) },
    })
    await findPanel()

    await typeQuantity(user, 'Jus orange 1 L', 10)
    await chooseReason(user, 'Jus orange 1 L', 'Autre')
    await user.click(validate('Valider avec ajustement (1)'))
    expect(await within(card('Jus orange 1 L')).findByText('Un commentaire est obligatoire avec ce motif.')).toBeInTheDocument()
    expect(commentOf('Jus orange 1 L')).toHaveFocus()
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0)

    await user.type(commentOf('Jus orange 1 L'), 'Produit abîmé')
    await user.click(validate('Valider avec ajustement (1)'))
    await screen.findByText('Commande RMK-261008-00042 validée avec 1 ligne ajustée.')
    expect(calls.find((call) => call.method === 'POST')?.body).toMatchObject({
      lines: [{ product_id: 42, validated_qty: 10, reason_code: 'AUTRE', comment: 'Produit abîmé' }, { product_id: 57 }],
    })
  })

  it('removes the reason and the comment of a line brought back to the requested quantity', async () => {
    const user = userEvent.setup()
    const order = roomy()
    const { calls } = renderOrders({
      path: DETAIL,
      routes: { [orderUrl(ORDER_ID)]: () => json(order), [VALIDATE]: () => json(validatedWith(order, {})) },
    })
    await findPanel()

    await typeQuantity(user, 'Jus orange 1 L', 30)
    await chooseReason(user, 'Jus orange 1 L', 'Autre')
    await user.type(commentOf('Jus orange 1 L'), 'Produit abîmé')
    await user.click(screen.getByRole('button', { name: 'Augmenter la quantité de Jus orange 1 L' }))
    await typeQuantity(user, 'Jus orange 1 L', 40)
    expect(within(card('Jus orange 1 L')).queryByRole('combobox')).not.toBeInTheDocument()
    expect(validate('Valider')).toBeEnabled()

    await user.click(validate('Valider'))
    await screen.findByText('Commande RMK-261008-00042 validée.')
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      version: 2,
      lines: [
        { product_id: 42, validated_qty: 40 },
        { product_id: 57, validated_qty: 1250 },
      ],
    })
  })

  it('adjusts every line to its maximum possible, the reasons staying to choose', async () => {
    const user = userEvent.setup()
    const order = decidable()
    const { calls } = renderOrders({
      path: DETAIL,
      routes: { [orderUrl(ORDER_ID)]: () => json(order), [VALIDATE]: () => json(validatedWith(order, { 57: 720 })) },
    })
    await findPanel()

    await user.click(screen.getByRole('button', { name: 'Ajuster au maximum possible' }))
    expect(stepper('Jus orange 1 L')).toHaveValue('40')
    expect(stepper('Lben 1 L')).toHaveValue('720')
    // Plus rien à ajuster : le bouton disparaît.
    expect(screen.queryByRole('button', { name: 'Ajuster au maximum possible' })).not.toBeInTheDocument()
    expect(validate('Valider avec ajustement (1)')).toBeEnabled()

    await user.click(validate('Valider avec ajustement (1)'))
    expect(await within(card('Lben 1 L')).findByText('Choisissez un motif d’ajustement.')).toBeInTheDocument()
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0)

    await chooseReason(user, 'Lben 1 L', 'Quota insuffisant')
    await user.click(validate('Valider avec ajustement (1)'))
    await screen.findByText('Commande RMK-261008-00042 validée avec 1 ligne ajustée.')
    expect(calls.find((call) => call.method === 'POST')?.body).toMatchObject({
      lines: [{ product_id: 42, validated_qty: 40 }, { product_id: 57, validated_qty: 720, reason_code: 'QUOTA_INSUFFISANT' }],
    })
  })

  it('disables Valider when every line is at 0 and suggests to reject the order', async () => {
    const user = userEvent.setup()
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(roomy()) } })
    await findPanel()

    await typeQuantity(user, 'Jus orange 1 L', 0)
    expect(validate('Valider avec ajustement (1)')).toBeEnabled()
    expect(screen.queryByText('Toutes les lignes sont à 0 : rejetez la commande.')).not.toBeInTheDocument()

    await typeQuantity(user, 'Lben 1 L', 0)
    expect(validate('Valider avec ajustement (2)')).toBeDisabled()
    expect(screen.getByText('Toutes les lignes sont à 0 : rejetez la commande.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Rejeter la commande' }))
    expect(await screen.findByRole('dialog', { name: 'Rejeter la commande RMK-261008-00042' })).toBeInTheDocument()
  })

  it('disables the decision offline and says so', async () => {
    const online = vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(true)
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(decidable()) } })
    await findPanel()
    expect(validate('Valider')).toBeEnabled()

    online.mockReturnValue(false)
    act(() => window.dispatchEvent(new Event('offline')))
    expect(validate('Valider')).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Rejeter' })).toBeDisabled()
    expect(screen.getByText('Hors ligne : la décision est impossible pour le moment.')).toBeInTheDocument()

    online.mockReturnValue(true)
    act(() => window.dispatchEvent(new Event('online')))
    expect(validate('Valider')).toBeEnabled()
    expect(screen.queryByText('Hors ligne : la décision est impossible pour le moment.')).not.toBeInTheDocument()
  })

  it('keeps the input and says there is no network when the decision does not leave', async () => {
    const user = userEvent.setup()
    let attempts = 0
    const order = roomy()
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(order),
        [VALIDATE]: () => {
          attempts += 1
          if (attempts === 1) throw new TypeError('Failed to fetch')
          return json(validatedWith(order, { 57: 700 }))
        },
      },
    })
    await findPanel()
    await typeQuantity(user, 'Lben 1 L', 700)
    await chooseReason(user, 'Lben 1 L', 'Quota insuffisant')
    await user.click(validate('Valider avec ajustement (1)'))

    expect(await screen.findByText('Pas de réseau : la décision n’a pas été enregistrée. Votre saisie est conservée, réessayez.')).toBeInTheDocument()
    expect(stepper('Lben 1 L')).toHaveValue('700')
    expect(reasonOf('Lben 1 L')).toHaveValue('QUOTA_INSUFFISANT')

    await user.click(validate('Valider avec ajustement (1)'))
    expect(await screen.findByText('Commande RMK-261008-00042 validée avec 1 ligne ajustée.')).toBeInTheDocument()
  })

  it('locks the input while the decision is being sent', async () => {
    const user = userEvent.setup()
    let release: () => void = () => undefined
    const order = roomy()
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(order),
        [VALIDATE]: async () => {
          await new Promise<void>((resolve) => (release = resolve))
          return json(validatedWith(order, {}))
        },
      },
    })
    await findPanel()
    await user.click(validate('Valider'))

    expect(await screen.findByRole('button', { name: 'Validation…' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Rejeter' })).toBeDisabled()
    expect(stepper('Lben 1 L')).toBeDisabled()
    act(() => release())
    expect(await screen.findByText('Commande RMK-261008-00042 validée.')).toBeInTheDocument()
  })
})

describe('regional order — refusals of the validation', () => {
  const violation = (extra: Partial<DecisionViolation> = {}): DecisionViolation => ({
    line: 1,
    product_id: 57,
    sku: 'P0057',
    requested: 1250,
    quota: 800,
    validated: 290,
    limit: null,
    client_validated: 30,
    max_allowed: 510,
    cause: 'REGION_QUOTA',
    ...extra,
  })

  it('explains a quota refusal on each line with the maximum allowed, and applies it in one click', async () => {
    const user = userEvent.setup()
    // Le contexte lu est périmé : il annonce 1 500 de reste, la validation en refuse.
    const order = roomy()
    let refused = true
    const { calls } = renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(order),
        [VALIDATE]: () =>
          refused
            ? problem(409, 'REGION_QUOTA_EXCEEDED', 'La validation dépasserait le quota de la région pour 2 produits.', {
                violations: [violation(), violation({ line: 0, product_id: 42, sku: 'P0042', requested: 40, limit: 50, max_allowed: 25, cause: 'CLIENT_LIMIT' })],
              })
            : json(validatedWith(order, { 42: 25, 57: 510 })),
      },
    })
    await findPanel()
    await user.click(validate('Valider'))

    expect(
      await screen.findByText('Validation refusée : le quota de la région ou la borne du client ne permet pas ces quantités. Appliquez les maximums signalés sur les lignes concernées.'),
    ).toBeInTheDocument()
    expect(within(card('Lben 1 L')).getByText('Quota de la région insuffisant : 510 au plus.')).toBeInTheDocument()
    expect(within(card('Jus orange 1 L')).getByText('Borne du client atteinte : 25 au plus.')).toBeInTheDocument()
    // La saisie est gardée.
    expect(stepper('Lben 1 L')).toHaveValue('1250')

    await user.click(screen.getByRole('button', { name: 'Appliquer 510 à « Lben 1 L »' }))
    await user.click(screen.getByRole('button', { name: 'Appliquer 25 à « Jus orange 1 L »' }))
    expect(stepper('Lben 1 L')).toHaveValue('510')
    expect(stepper('Jus orange 1 L')).toHaveValue('25')
    // L'explication ne vaut que pour la quantité envoyée.
    expect(within(card('Lben 1 L')).queryByText(/Quota de la région insuffisant/)).not.toBeInTheDocument()

    refused = false
    await chooseReason(user, 'Jus orange 1 L', 'Borne atteinte')
    await chooseReason(user, 'Lben 1 L', 'Quota insuffisant')
    await user.click(validate('Valider avec ajustement (2)'))
    await screen.findByText('Commande RMK-261008-00042 validée avec 2 lignes ajustées.')
    expect(calls.filter((call) => call.method === 'POST').at(-1)?.body).toMatchObject({
      lines: [{ product_id: 42, validated_qty: 25, reason_code: 'BORNE_ATTEINTE' }, { product_id: 57, validated_qty: 510, reason_code: 'QUOTA_INSUFFISANT' }],
    })
  })

  it('rereads the order after a refusal, so that the maxima follow', async () => {
    const user = userEvent.setup()
    let reread = false
    const { requests } = renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () =>
          json(
            reread
              ? decidable({ lines: roomy().lines.map((line) => ({ ...line, context: { ...line.context, max_validatable: line.product_id === 57 ? 510 : 40 } })) })
              : roomy(),
          ),
        [VALIDATE]: () => {
          reread = true
          return problem(409, 'CLIENT_LIMIT_EXCEEDED', 'Borne dépassée.', { violations: [violation({ cause: 'CLIENT_LIMIT', limit: 540, max_allowed: 510 })] })
        },
      },
    })
    await findPanel()
    expect(within(card('Lben 1 L')).queryByText(/Au-dessus du maximum/)).not.toBeInTheDocument()
    await user.click(validate('Valider'))

    expect(await within(card('Lben 1 L')).findByText('Borne du client atteinte : 510 au plus.')).toBeInTheDocument()
    await waitFor(() => expect(requests(orderUrl(ORDER_ID))).toHaveLength(2))
    // Maximum relu à 510 : la ligne reste signalée une fois l'explication du serveur périmée.
    await user.clear(stepper('Lben 1 L'))
    await user.type(stepper('Lben 1 L'), '900{Enter}')
    expect(within(card('Lben 1 L')).getByText('Au-dessus du maximum validable : 510 au plus.')).toBeInTheDocument()
  })

  it('shows the refusals of the sense of the lines (422) on the line concerned', async () => {
    const user = userEvent.setup()
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(roomy()),
        [VALIDATE]: () =>
          problem(422, 'VALIDATION_FAILED', 'Données invalides.', {
            errors: { 'lines.1.reason_code': ['Le motif d’ajustement n’est plus disponible.'], lines: ['Les lignes ne correspondent pas à la commande.'] },
          }),
      },
    })
    await findPanel()
    await user.click(validate('Valider'))

    expect(await within(card('Lben 1 L')).findByText('Le motif d’ajustement n’est plus disponible.')).toBeInTheDocument()
    expect(screen.getByText('Validation refusée : corrigez les lignes signalées.')).toBeInTheDocument()
    expect(screen.getByText('Les lignes ne correspondent pas à la commande.')).toBeInTheDocument()
    expect(within(card('Jus orange 1 L')).queryByText(/n’est plus disponible/)).not.toBeInTheDocument()
  })

  it('says the order was already processed, reloads it and drops the draft', async () => {
    const user = userEvent.setup()
    const order = roomy()
    let processed = false
    const { queryClient } = renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(processed ? validatedWith(order, {}, { version: 3 }) : order),
        [VALIDATE]: () => {
          processed = true
          return problem(409, 'INVALID_TRANSITION', 'Commande « Validée » : impossible de passer à « Validée ».')
        },
      },
    })
    await findPanel()
    await user.click(validate('Valider'))

    expect(await screen.findByText('Cette commande a déjà été traitée, par un autre compte ou depuis un autre appareil : elle a été rechargée.')).toBeInTheDocument()
    expect(await screen.findByRole('heading', { name: 'Décision' })).toBeInTheDocument()
    expect(screen.getByText('Validée', { selector: 'span' })).toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Décision sur la commande' })).not.toBeInTheDocument()
    expect(draftStore(queryClient).has(ORDER_ID)).toBe(false)
  })

  it('keeps only the rejection when the validation cutoff has passed', async () => {
    const user = userEvent.setup()
    let blocked = false
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () =>
          json(
            blocked
              ? decidable({ can_validate: false, blocked_reason: 'CUTOFF_PASSED', validation: { cutoff: '15:00', cutoff_at: '2026-10-08T14:00:00+00:00', extended: false, blocking: true, passed: true } })
              : roomy(),
          ),
        [VALIDATE]: () => {
          blocked = true
          return problem(409, 'CUTOFF_PASSED', 'L’heure limite de validation est passée.', { cutoff: '15:00' })
        },
      },
    })
    await findPanel()
    await user.click(validate('Valider'))

    expect(await screen.findByText('L’heure limite de validation (15:00) est passée : la commande ne peut plus être validée.')).toBeInTheDocument()
    expect(screen.getByText('Vous pouvez encore la rejeter.')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('button', { name: /^Valider/ })).not.toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Rejeter' })).toBeEnabled()
    // Lignes en lecture seule : plus de quantité à saisir.
    expect(screen.queryByRole('textbox', { name: /Quantité de/ })).not.toBeInTheDocument()
    expect(screen.getByRole('list', { name: 'Produits commandés' })).toHaveTextContent('Demandé : 1 250')
  })

  it('keeps only the rejection for a client blocked', async () => {
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () =>
          json(decidable({ can_validate: false, blocked_reason: 'CLIENT_BLOCKED', client: { ...makeOrder().client, status: 'BLOCKED' } })),
      },
    })
    await findPanel()

    expect(screen.getByText('Client bloqué : la commande ne peut pas être validée.')).toBeInTheDocument()
    expect(screen.getByText('Vous pouvez encore la rejeter.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Valider/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Rejeter' })).toBeEnabled()
  })

  it('learns that a client is blocked from the refusal itself, before the order is reread', async () => {
    const user = userEvent.setup()
    let release: () => void = () => undefined
    let reread = false
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: async () => {
          if (reread) await new Promise<void>((resolve) => (release = resolve))
          return json(roomy())
        },
        [VALIDATE]: () => {
          reread = true
          return problem(403, 'CLIENT_BLOCKED', 'Client bloqué.')
        },
      },
    })
    await findPanel()
    await user.click(validate('Valider'))

    expect(await screen.findByText('Client bloqué : la commande ne peut pas être validée.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Valider/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Rejeter' })).toBeInTheDocument()
    act(() => release())
  })

  it('explains an order which cannot be decided any more (day closed): no action', async () => {
    renderOrders({
      path: DETAIL,
      routes: { [orderUrl(ORDER_ID)]: () => json(makeOrder({ blocked_reason: 'DAY_NOT_OPEN' })) },
    })
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })

    expect(await screen.findByText('La journée est clôturée : la commande ne peut plus être décidée.')).toBeInTheDocument()
    expect(screen.queryByText('Vous pouvez encore la rejeter.')).not.toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(screen.getByRole('list', { name: 'Produits commandés' })).toHaveTextContent('Demandé : 40')
  })
})

describe('regional order — draft and live updates', () => {
  const live = () => regional({ realtime: { key: 'app-key' } })
  const channel = `region-day.3.${DAY}`
  const broadcast = (event: string, payload: unknown) => act(() => fake.listeners.get(`${channel}:.${event}`)?.forEach((listener) => listener(payload)))
  const connect = async () => {
    await waitFor(() => expect(fake.listeners.get(`${channel}:.OrderUpdated`)?.size).toBe(1))
    act(() => fake.stateChange?.({ current: 'connected' }))
  }

  it('listens to the decisions and to the processing of the orders of its region', async () => {
    renderOrders({ path: DETAIL, user: live(), routes: { [orderUrl(ORDER_ID)]: () => json(decidable()) } })
    await findPanel()
    for (const event of ['OrderValidated', 'OrderRejected', 'OrdersProcessed', 'OrderCancelledByAdv', 'QuotaPublished', 'DistributionPublished']) {
      await waitFor(() => expect(fake.listeners.get(`${channel}:.${event}`)?.size).toBe(1))
    }
  })

  it('never overwrites the draft on a live reread: the maxima follow, the lines above are flagged', async () => {
    const user = userEvent.setup()
    let maximum = 720
    const { requests } = renderOrders({
      path: DETAIL,
      user: live(),
      routes: {
        [orderUrl(ORDER_ID)]: () =>
          json(decidable({ lines: roomy().lines.map((line) => ({ ...line, context: { ...line.context, max_validatable: line.product_id === 57 ? maximum : 40 } })) })),
      },
    })
    await findPanel()
    await connect()

    await typeQuantity(user, 'Lben 1 L', 700)
    await chooseReason(user, 'Lben 1 L', 'Quota insuffisant')
    await user.type(commentOf('Lben 1 L'), 'Reste limité')
    await typeQuantity(user, 'Jus orange 1 L', 12)
    expect(within(card('Lben 1 L')).queryByText(/Au-dessus du maximum/)).not.toBeInTheDocument()

    // Un autre régional valide : le reste région baisse, le maximum de Lben passe à 650.
    maximum = 650
    broadcast('OrderValidated', { id: 'autre', number: 'RMK-261008-00099', status: 'VALIDATED', version: 2, day: DAY, client_id: 90 })
    await waitFor(() => expect(requests(orderUrl(ORDER_ID))).toHaveLength(2))
    expect(await within(card('Lben 1 L')).findByText('Au-dessus du maximum validable : 650 au plus.')).toBeInTheDocument()
    expect(factsOf('Lben 1 L')).toContain('Maximum validable : 650')

    // La saisie est intacte.
    expect(stepper('Lben 1 L')).toHaveValue('700')
    expect(reasonOf('Lben 1 L')).toHaveValue('QUOTA_INSUFFISANT')
    expect(commentOf('Lben 1 L')).toHaveValue('Reste limité')
    expect(stepper('Jus orange 1 L')).toHaveValue('12')

    await user.click(screen.getByRole('button', { name: 'Appliquer 650 à « Lben 1 L »' }))
    expect(stepper('Lben 1 L')).toHaveValue('650')
    expect(reasonOf('Lben 1 L')).toHaveValue('QUOTA_INSUFFISANT')
  })

  it('announces an order modified by the client and starts again from the order, after confirmation', async () => {
    const user = userEvent.setup()
    let version = 2
    const order = () =>
      roomy({ version, lines: roomy().lines.map((line) => ({ ...line, requested_qty: line.product_id === 57 ? 900 + version * 100 : line.requested_qty })) })
    const { requests, calls } = renderOrders({
      path: DETAIL,
      user: live(),
      routes: { [orderUrl(ORDER_ID)]: () => json(order()), [VALIDATE]: () => json(validatedWith(order(), {})) },
    })
    await findPanel()
    await connect()
    await typeQuantity(user, 'Lben 1 L', 700)
    expect(card('Lben 1 L')).toHaveTextContent('Demandé : 1 100')

    version = 3
    broadcast('OrderUpdated', { id: ORDER_ID, number: 'RMK-261008-00042', status: 'SUBMITTED', version: 3, day: DAY, client_id: 12 })
    await waitFor(() => expect(requests(orderUrl(ORDER_ID))).toHaveLength(2))

    // Bandeau, saisie suspendue : ni validation ni rejet avant la reprise.
    expect(await screen.findByText('Commande modifiée par le client')).toBeInTheDocument()
    expect(screen.getByText(/Le client a modifié cette commande \(version 3\)/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Valider/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rejeter' })).not.toBeInTheDocument()
    expect(screen.queryByRole('textbox', { name: /Quantité de/ })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Reprendre avec la commande à jour' }))
    expect(screen.queryByText('Commande modifiée par le client')).not.toBeInTheDocument()
    expect(stepper('Lben 1 L')).toHaveValue('1200')
    expect(card('Lben 1 L')).toHaveTextContent('Demandé : 1 200')

    await user.click(validate('Valider'))
    await screen.findByText('Commande RMK-261008-00042 validée.')
    expect(calls.find((call) => call.method === 'POST')?.body).toMatchObject({ version: 3 })
  })

  it('rejects with the version the regional had in front of them: a modification by the client meanwhile is a conflict', async () => {
    const user = userEvent.setup()
    let version = 2
    const { requests, calls } = renderOrders({
      path: DETAIL,
      user: live(),
      routes: {
        [orderUrl(ORDER_ID)]: () => json(roomy({ version })),
        [rejectUrl(ORDER_ID)]: () => problem(409, 'VERSION_CONFLICT', 'La commande a été modifiée.', { version: 3 }),
      },
    })
    await findPanel()
    await connect()
    await user.click(screen.getByRole('button', { name: 'Rejeter' }))
    const dialog = await screen.findByRole('dialog', { name: 'Rejeter la commande RMK-261008-00042' })
    await waitFor(() => expect(within(dialog).getByRole('combobox', { name: 'Motif du rejet' })).toBeEnabled())
    await user.selectOptions(within(dialog).getByRole('combobox', { name: 'Motif du rejet' }), 'Doublon')

    // Le client modifie la commande pendant que le motif est choisi.
    version = 3
    broadcast('OrderUpdated', { id: ORDER_ID, number: 'RMK-261008-00042', status: 'SUBMITTED', version: 3, day: DAY, client_id: 12 })
    await waitFor(() => expect(requests(orderUrl(ORDER_ID))).toHaveLength(2))
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ version: 2, reason_code: 'DOUBLON', comment: null })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByText('Commande modifiée par le client')).toBeInTheDocument()
  })

  it('keeps the draft on a version conflict and shows the banner once the order is reread', async () => {
    const user = userEvent.setup()
    let version = 2
    const { queryClient, requests } = renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(roomy({ version })),
        [VALIDATE]: () => {
          version = 3
          return problem(409, 'VERSION_CONFLICT', 'La commande a été modifiée.', { version: 3 })
        },
      },
    })
    await findPanel()
    await typeQuantity(user, 'Lben 1 L', 700)
    await chooseReason(user, 'Lben 1 L', 'Quota insuffisant')
    await user.click(validate('Valider avec ajustement (1)'))

    expect(await screen.findByText('Commande modifiée par le client')).toBeInTheDocument()
    await waitFor(() => expect(requests(orderUrl(ORDER_ID))).toHaveLength(2))
    // Le brouillon (version 2) est toujours là, il ne repart qu'à la confirmation du régional.
    expect(draftStore(queryClient).get(ORDER_ID)).toMatchObject({ version: 2, lines: { 57: { quantity: 700, reason: 'QUOTA_INSUFFISANT' } } })
    expect(screen.queryByText(/La commande a été modifiée entre-temps/)).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Reprendre avec la commande à jour' }))
    expect(stepper('Lben 1 L')).toHaveValue('1250')
    // Brouillon vierge : rien à garder.
    expect(draftStore(queryClient).has(ORDER_ID)).toBe(false)
  })

  it('says the order was modified when the conflict is not followed by a new version yet', async () => {
    const user = userEvent.setup()
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () => json(roomy()),
        [VALIDATE]: () => problem(409, 'VERSION_CONFLICT', 'La commande a été modifiée.', { version: 3 }),
      },
    })
    await findPanel()
    await user.click(validate('Valider'))

    expect(await screen.findByText('La commande a été modifiée entre-temps : elle est relue, votre saisie est conservée.')).toBeInTheDocument()
  })

  it('starts again from the order, without banner, when it comes back after a modification and nothing was entered', async () => {
    const queryClient = createQueryClient()
    draftStore(queryClient).set(ORDER_ID, { version: 2, touched: false, lines: {} })
    renderOrders({ path: DETAIL, queryClient, routes: { [orderUrl(ORDER_ID)]: () => json(roomy({ version: 3 })) } })
    await findPanel()

    expect(screen.queryByText('Commande modifiée par le client')).not.toBeInTheDocument()
    expect(stepper('Lben 1 L')).toHaveValue('1250')
    expect(validate('Valider')).toBeEnabled()
  })

  it('announces the modification when it comes back to a draft where something was entered', async () => {
    const queryClient = createQueryClient()
    draftStore(queryClient).set(ORDER_ID, { version: 2, touched: true, lines: { 57: { quantity: 700, reason: 'QUOTA_INSUFFISANT', comment: '' } } })
    renderOrders({ path: DETAIL, queryClient, routes: { [orderUrl(ORDER_ID)]: () => json(roomy({ version: 3 })) } })
    await findPanel().catch(() => undefined)

    expect(await screen.findByText('Commande modifiée par le client')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Valider/ })).not.toBeInTheDocument()
  })

  it('gets the draft back when the regional comes back to the order', async () => {
    const user = userEvent.setup()
    const first = renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(roomy()) } })
    await findPanel()
    await typeQuantity(user, 'Lben 1 L', 700)
    await chooseReason(user, 'Lben 1 L', 'Quota insuffisant')

    // Sortie vers la fiche du client, puis retour : même client de requêtes, même brouillon.
    const store = draftStore(first.queryClient)
    expect(store.get(ORDER_ID)).toMatchObject({ version: 2 })
    first.unmount()
    expect(store.get(ORDER_ID)?.lines[57]).toMatchObject({ quantity: 700, reason: 'QUOTA_INSUFFISANT' })
  })

  it('shows the reason of an adjusted line by its label, a reason deactivated since included', async () => {
    renderOrders({
      path: DETAIL,
      routes: {
        [orderUrl(ORDER_ID)]: () =>
          json(
            makeOrder({
              ...validatedWith(roomy(), { 57: 700 }),
              lines: validatedWith(roomy(), { 57: 700 }).lines.map((line) =>
                line.product_id === 57
                  ? { ...line, adjustment_reason_code: 'ANCIEN_MOTIF', adjustment_reason: { code: 'ANCIEN_MOTIF', label: 'Ancien motif' }, adjustment_comment: 'Reste limité' }
                  : line,
              ),
            }),
          ),
      },
    })
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })
    expect(screen.getByText(/Quantité réduite de 1 250 à 700/)).toHaveTextContent('Quantité réduite de 1 250 à 700 · Motif : Ancien motif')
    expect(screen.getByText('« Reste limité »')).toBeInTheDocument()
  })
})
