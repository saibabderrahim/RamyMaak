import { onlineManager } from '@tanstack/react-query'
import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent, { type UserEvent } from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ClientToday, Order } from '../../../shared/api/types'
import { formatAmount } from '../../../shared/format'
import { totals } from '../../../shared/money'
import type { OutboxEntry } from '../../../shared/offline/db'
import { enqueue, getEntry, listEntries } from '../../../shared/offline/outbox'
import { sendPendingEntries } from '../../../shared/offline/useOutbox'
import { purgeSessionStorage } from '../../../shared/storage'
import { json, problem } from '../../../test/utils'
import {
  type ApiHandler,
  CLIENT_ID,
  clientApi,
  DAY,
  lben,
  makeOrder,
  makeSummary,
  makeToday,
  ORDER_ID,
  ORDER_NUMBER,
  ORDERS_URL,
  orange,
  orderOf,
  plain,
  pomme,
  renderClient,
  storedCart,
  testCart,
  USER_ID,
} from './testing'
import type { Cart } from './store'

afterEach(() => {
  purgeSessionStorage()
  vi.restoreAllMocks()
})

const cartApi = (routes: Record<string, ApiHandler> = {}, today: ClientToday | ((url: URL) => ClientToday) = makeToday()) =>
  clientApi((url) => json(typeof today === 'function' ? today(url) : today), routes)

/** Panier de départ : 3 Jus orange 1 L, 7 Lben 1 L. */
const FILLED = [
  { product_id: orange.id, quantity: 3 },
  { product_id: lben.id, quantity: 7 },
]

function fillCart(comment = '') {
  FILLED.forEach((filled) => testCart().setQuantity(DAY, filled.product_id, filled.quantity))
  if (comment) testCart().setComment(comment)
}

const line = (name: string) => screen.getByRole('article', { name })
/** Nouvelle commande (201), enregistrée telle que le panier de départ. */
const created = (overrides: Partial<Order> = {}) => json(orderOf(FILLED, overrides), 201)
/** Renvoi d'un identifiant déjà reçu (200) : la commande enregistrée par le premier envoi. */
const replayed = (overrides: Partial<Order> = {}) => json(orderOf(FILLED, overrides), 200)

/** « Envoyer la commande » puis confirmation. */
async function sendOrder(user: UserEvent, button = 'Envoyer la commande', confirm = 'Confirmer l’envoi') {
  await user.click(await screen.findByRole('button', { name: button }))
  await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: confirm }))
}

/** Bloc « Commandes en attente d'envoi » (boîte d'envoi, lot 8). */
const outboxBlock = () => screen.findByRole('region', { name: 'Commandes en attente d’envoi' })

/** « Reprendre dans le panier » de la commande refusée. */
async function resumeRefused(user: UserEvent) {
  const block = await outboxBlock()
  await user.click(await within(block).findByRole('button', { name: 'Reprendre dans le panier la commande du jeudi 08/10/2026' }))
}

/** Panier rendu, puis envoyé avec la réponse `answer` de POST /client/orders. */
async function renderSent(user: UserEvent, answer: ApiHandler) {
  const api = cartApi({ [ORDERS_URL]: answer })
  const rendered = renderClient('/client/panier')
  await sendOrder(user)
  return { ...api, ...rendered }
}

describe('cart (EF-CLT-03)', () => {
  it('lists the lines with their amount and the indicative totals, computed like the server', async () => {
    fillCart()
    cartApi()
    renderClient('/client/panier')

    const orangeLine = await screen.findByRole('article', { name: 'Jus orange 1 L' })
    expect(orangeLine).toHaveTextContent(plain(`Fardeau 6 × 1 L · ${formatAmount(900)} HT l’unité de vente`))
    expect(orangeLine).toHaveTextContent('Max : 40')
    expect(orangeLine).toHaveTextContent(plain(`Montant HT : ${formatAmount(2700)}`))
    expect(within(orangeLine).getByRole('textbox', { name: 'Quantité de Jus orange 1 L' })).toHaveValue('3')
    expect(line('Lben 1 L')).toHaveTextContent(plain(`Montant HT : ${formatAmount(913.5)}`))
    expect(line('Lben 1 L')).not.toHaveTextContent('Max')

    const expected = totals([
      { unit_price_ht: 900, vat_rate: 19, quantity: 3 },
      { unit_price_ht: 130.5, vat_rate: 9, quantity: 7 },
    ])
    // TVA par ligne au centime, demi supérieur : 513,00 + 82,215 → 82,22.
    expect(expected).toEqual({ ht: 3613.5, vat: 595.22, ttc: 4208.72 })
    const sums = screen.getByLabelText('Totaux')
    expect(within(sums).getByText('Total HT').nextSibling).toHaveTextContent(plain(formatAmount(expected.ht)))
    expect(within(sums).getByText('TVA').nextSibling).toHaveTextContent(plain(formatAmount(expected.vat)))
    expect(within(sums).getByText('Total TTC').nextSibling).toHaveTextContent(plain(formatAmount(expected.ttc)))
    expect(screen.getByText('Montants indicatifs : la facture fait foi.')).toBeInTheDocument()
    expect(screen.getByText('Commande du jeudi 08/10/2026')).toBeInTheDocument()
  })

  it('changes and removes lines, and keeps the comment on the device (500 characters at most)', async () => {
    const user = userEvent.setup()
    fillCart()
    cartApi()
    renderClient('/client/panier')

    await user.click(within(await screen.findByRole('article', { name: 'Jus orange 1 L' })).getByRole('button', { name: 'Augmenter la quantité de Jus orange 1 L' }))
    expect(line('Jus orange 1 L')).toHaveTextContent(plain(`Montant HT : ${formatAmount(3600)}`))
    await user.click(screen.getByRole('button', { name: 'Retirer Lben 1 L du panier' }))
    expect(screen.queryByRole('article', { name: 'Lben 1 L' })).not.toBeInTheDocument()

    const comment = screen.getByRole('textbox', { name: 'Commentaire (facultatif)' })
    await user.type(comment, 'Livrer avant 10 h')
    expect(screen.getByText('17 / 500 caractères')).toBeInTheDocument()
    expect(storedCart()).toMatchObject({ lines: [{ product_id: orange.id, quantity: 4 }], comment: 'Livrer avant 10 h' })
    expect(comment).toHaveAttribute('maxlength', '500')
  })

  it('shows the summary handed over by « Recommander »', async () => {
    cartApi()
    fillCart()
    renderClient('/client/panier', undefined, { notice: 'Panier rempli à partir de la commande RMK-261007-00003 : 2 produits repris.' })
    const shown = await screen.findAllByText('Panier rempli à partir de la commande RMK-261007-00003 : 2 produits repris.')
    // Hors de la sonde de test, qui affiche aussi `state.notice`.
    expect(shown.filter((element) => !element.closest('[data-testid="flash"]'))).toHaveLength(1)
  })

  it('says when the cart is empty', async () => {
    cartApi()
    renderClient('/client/panier')
    expect(await screen.findByText('Votre panier est vide.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Voir le catalogue' })).toHaveAttribute('href', '/client/catalogue')
  })

  it('sends the order through the outbox after confirmation, the request id saved before the request, then opens it', async () => {
    const user = userEvent.setup()
    fillCart('  Livrer avant 10 h ')
    let atRequest: { entry: OutboxEntry | undefined; cart: Cart | null } | null = null
    const { requests } = cartApi({
      [ORDERS_URL]: async ({ body }) => {
        // La commande est dans la boîte d'envoi, en cours d'envoi, et le panier déjà vidé quand la requête part (I-102).
        atRequest = { entry: await getEntry((body as { client_request_id: string }).client_request_id), cart: storedCart() }
        return created({ comment: 'Livrer avant 10 h' })
      },
    })
    renderClient('/client/panier')

    await user.click(await screen.findByRole('button', { name: 'Envoyer la commande' }))
    const dialog = screen.getByRole('dialog', { name: 'Envoyer la commande ?' })
    expect(dialog).toHaveTextContent('2 produits')
    expect(dialog).toHaveTextContent(plain(`Total TTC${formatAmount(4208.72)}`))
    expect(dialog).toHaveTextContent('Commentaire : Livrer avant 10 h')
    expect(requests(ORDERS_URL)).toHaveLength(0)
    await user.click(within(dialog).getByRole('button', { name: 'Confirmer l’envoi' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(`/client/commandes/${ORDER_ID}`))
    expect(screen.getByTestId('flash')).toHaveTextContent(`Commande ${ORDER_NUMBER} envoyée.`)
    const [sent] = requests(ORDERS_URL)
    const id = (sent?.body as { client_request_id: string }).client_request_id
    expect(id).toMatch(/^[0-9a-f-]{36}$/)
    expect(sent?.body).toEqual({
      client_request_id: id,
      day: DAY,
      comment: 'Livrer avant 10 h',
      lines: [
        { product_id: orange.id, quantity: 3 },
        { product_id: lben.id, quantity: 7 },
      ],
    })
    expect(sent?.headers.get('X-XSRF-TOKEN')).toBe('token=1')
    expect(atRequest).toMatchObject({ entry: { id, user_id: USER_ID, client_id: CLIENT_ID, day: DAY, status: 'sending', attempts: 1 }, cart: null })
    // Commande envoyée : gardée dans la boîte jusqu'à la relecture de la liste des commandes.
    await waitFor(async () => expect(await getEntry(id)).toMatchObject({ status: 'sent', order: { id: ORDER_ID, number: ORDER_NUMBER } }))
    expect(testCart().get().lines).toEqual([])
  })

  it('keeps the order in the outbox after a network or server error, and resends it with the same request id', async () => {
    const user = userEvent.setup()
    fillCart()
    let attempt = 0
    const { requests, queryClient } = await renderSent(user, () => {
      attempt += 1
      if (attempt === 1) throw new TypeError('Failed to fetch')
      if (attempt === 2) return problem(503, 'SERVER_ERROR', 'Service indisponible')
      if (attempt === 3) return problem(429, 'TOO_MANY_REQUESTS', 'Trop de requêtes')
      // L'envoi précédent avait abouti : renvoi idempotent (200), la commande telle qu'enregistrée.
      return replayed()
    })

    expect(await screen.findByText('Commande enregistrée sur cet appareil : elle partira dès le retour du réseau.')).toBeInTheDocument()
    const block = await outboxBlock()
    expect(block).toHaveTextContent('Commande du jeudi 08/10/2026 · 2 produits')
    expect(block).toHaveTextContent('En attente d’envoi')
    expect(block).toHaveTextContent('Elle partira d’elle-même dès que le réseau le permettra.')
    // Panier vidé : la commande n'existe plus que dans la boîte d'envoi.
    expect(storedCart()).toBeNull()
    expect(screen.getByText('Votre panier est vide.')).toBeInTheDocument()
    const [entry] = await listEntries(USER_ID)
    expect(entry).toMatchObject({ status: 'pending', attempts: 1, error: null })

    for (const expected of [2, 3]) {
      await act(() => sendPendingEntries(queryClient, USER_ID))
      expect(requests(ORDERS_URL)).toHaveLength(expected)
      expect(await getEntry(entry!.id)).toMatchObject({ status: 'pending', attempts: expected })
    }
    await act(() => sendPendingEntries(queryClient, USER_ID))
    expect(within(await outboxBlock()).getByText(`Envoyée — ${ORDER_NUMBER}`)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: `Voir la commande ${ORDER_NUMBER}` })).toHaveAttribute('href', `/client/commandes/${ORDER_ID}`)
    expect(requests(ORDERS_URL).map((call) => (call.body as { client_request_id: string }).client_request_id)).toEqual([entry!.id, entry!.id, entry!.id, entry!.id])
  })

  it('gives the cart back and starts afresh when the interrupted sending had created an order cancelled since', async () => {
    const user = userEvent.setup()
    fillCart()
    // Envoi interrompu avant la boîte d'envoi (lot 5) : identifiant gardé dans le panier.
    const first = testCart().beginSend()
    let attempt = 0
    const { requests } = cartApi({
      [ORDERS_URL]: () => {
        attempt += 1
        // Le premier envoi avait abouti ; la commande a été annulée depuis (« Mes commandes », autre compte).
        if (attempt === 1) return replayed({ status: 'CANCELLED', status_label: 'Annulée', can_modify: false, can_cancel: false })
        return created()
      },
    })
    renderClient('/client/panier')

    await sendOrder(user)
    expect(
      await screen.findByText(`L’envoi interrompu avait créé la commande ${ORDER_NUMBER}, depuis annulée : envoyez à nouveau pour créer une nouvelle commande.`),
    ).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/client/panier')
    await waitFor(() => expect(storedCart()?.lines).toHaveLength(2))
    expect(storedCart()?.pending).toBeNull()
    await waitFor(async () => expect(await listEntries(USER_ID)).toEqual([]))

    await sendOrder(user)
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(`/client/commandes/${ORDER_ID}`))
    const ids = requests(ORDERS_URL).map((call) => (call.body as { client_request_id: string }).client_request_id)
    expect(ids[0]).toBe(first)
    expect(ids[1]).not.toBe(first)
  })

  it('warns when a resent order was already saved without the changes made since the interruption', async () => {
    const user = userEvent.setup()
    fillCart()
    testCart().beginSend()
    // Premier envoi reçu par le serveur, réponse perdue ; le renvoi rend la commande enregistrée (I-54).
    cartApi({ [ORDERS_URL]: () => replayed() })
    renderClient('/client/panier')

    await user.click(within(await screen.findByRole('article', { name: 'Jus orange 1 L' })).getByRole('button', { name: 'Augmenter la quantité de Jus orange 1 L' }))
    await sendOrder(user)
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(`/client/commandes/${ORDER_ID}`))
    expect(screen.getByTestId('flash')).toHaveTextContent(
      `Commande ${ORDER_NUMBER} déjà envoyée avant la coupure : vos derniers changements n’y figurent pas. Modifiez-la si besoin.`,
    )
    expect(storedCart()).toBeNull()
  })

  it('resends an interrupted sending although the day now counts it, without capping its lines', async () => {
    const user = userEvent.setup()
    const lines = [
      { product_id: orange.id, quantity: 40 },
      { product_id: lben.id, quantity: 7 },
    ]
    lines.forEach((filled) => testCart().setQuantity(DAY, filled.product_id, filled.quantity))
    // Produit sorti du catalogue entre-temps : ni affiché ni envoyé.
    testCart().setQuantity(DAY, 99, 4)
    const id = testCart().beginSend()
    // Le premier envoi a abouti : maximum atteint, et ses 40 Jus orange comptés dans l'engagé (épuisé).
    const { requests } = cartApi(
      { [ORDERS_URL]: () => json(orderOf(lines), 200) },
      makeToday({
        can_submit: false,
        submit_blocker: 'MAX_ORDERS_REACHED',
        orders: [makeSummary()],
        products: [{ ...orange, engaged: 50, available: 0, sold_out: true }, pomme, lben],
      }),
    )
    renderClient('/client/panier')

    expect(await screen.findByText('Un envoi a été interrompu : renvoyez la commande, elle ne sera pas créée deux fois.')).toBeInTheDocument()
    expect(screen.queryByText('Votre panier a été mis à jour')).not.toBeInTheDocument()
    expect(screen.queryByText('Vous avez déjà envoyé votre commande pour cette journée.')).not.toBeInTheDocument()
    expect(within(line('Jus orange 1 L')).getByRole('textbox', { name: 'Quantité de Jus orange 1 L' })).toHaveValue('40')
    expect(storedCart()?.lines).toHaveLength(3)

    await sendOrder(user)
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(`/client/commandes/${ORDER_ID}`))
    expect(requests(ORDERS_URL)[0]?.body).toEqual({ client_request_id: id, day: DAY, comment: null, lines })
    expect(screen.getByTestId('flash')).toHaveTextContent(`Commande ${ORDER_NUMBER} envoyée.`)
    expect(storedCart()).toBeNull()
  })

  it('says an interrupted sending can be resumed without creating a second order', async () => {
    fillCart()
    const id = testCart().beginSend()
    const { requests } = cartApi({ [ORDERS_URL]: () => created() })
    const user = userEvent.setup()
    renderClient('/client/panier')

    expect(await screen.findByText('Un envoi a été interrompu : renvoyez la commande, elle ne sera pas créée deux fois.')).toBeInTheDocument()
    await sendOrder(user)
    await waitFor(() => expect(requests(ORDERS_URL)).toHaveLength(1))
    const [call] = requests(ORDERS_URL)
    expect((call?.body as { client_request_id: string } | undefined)?.client_request_id).toBe(id)
  })

  it('shows a refusal in the outbox, then reports the refused quantities on their lines once resumed in the cart', async () => {
    const user = userEvent.setup()
    testCart().setQuantity(DAY, orange.id, 38)
    testCart().setQuantity(DAY, pomme.id, 300)
    testCart().setQuantity(DAY, lben.id, 7)
    testCart().setComment('Livrer tôt')
    const { requests } = cartApi({
      [ORDERS_URL]: () =>
        problem(409, 'CLIENT_LIMIT_EXCEEDED', '2 lignes dépassent la quantité disponible.', {
          violations: [
            { line: 0, product_id: orange.id, requested: 38, max_allowed: 35 },
            { line: 1, product_id: pomme.id, requested: 300, max_allowed: null },
          ],
        }),
    })
    renderClient('/client/panier')

    await sendOrder(user)
    const block = await outboxBlock()
    expect(await within(block).findByText('Refusée : 2 lignes dépassent la quantité disponible.')).toBeInTheDocument()
    expect(storedCart()).toBeNull()
    expect((await listEntries(USER_ID))[0]).toMatchObject({ status: 'refused', error: { code: 'CLIENT_LIMIT_EXCEEDED', detail: '2 lignes dépassent la quantité disponible.' } })

    await user.click(within(block).getByRole('button', { name: 'Reprendre dans le panier la commande du jeudi 08/10/2026' }))
    expect(await screen.findByText('2 lignes dépassent la quantité disponible.')).toBeInTheDocument()
    expect(screen.getByText('Corrigez les lignes signalées, puis renvoyez la commande.')).toBeInTheDocument()
    expect((await screen.findAllByText('Panier reconstruit à partir de la commande refusée : corrigez-le si besoin, puis envoyez-le à nouveau.')).length).toBeGreaterThan(0)
    expect(storedCart()).toMatchObject({ day: DAY, comment: 'Livrer tôt', pending: null })
    expect(storedCart()?.lines).toHaveLength(3)
    await waitFor(async () => expect(await listEntries(USER_ID)).toEqual([]))

    const orangeLine = line('Jus orange 1 L')
    expect(within(orangeLine).getByText('Maximum : 35.')).toBeInTheDocument()
    // Sans borne, aucun maximum n'est montré (RG-C09).
    expect(within(line('Jus pomme 1 L')).getByText('Quantité trop élevée : réduisez-la.')).toBeInTheDocument()
    expect(line('Lben 1 L')).not.toHaveTextContent('Maximum')

    await user.click(within(orangeLine).getByRole('button', { name: 'Ramener à 35' }))
    expect(within(orangeLine).getByRole('textbox', { name: 'Quantité de Jus orange 1 L' })).toHaveValue('35')
    expect(within(orangeLine).queryByText('Maximum : 35.')).not.toBeInTheDocument()

    // Nouvel envoi : nouvel identifiant.
    await sendOrder(user)
    await waitFor(() => expect(requests(ORDERS_URL)).toHaveLength(2))
    const [first, second] = requests(ORDERS_URL).map((call) => (call.body as { client_request_id: string }).client_request_id)
    expect(second).not.toBe(first)
  })

  it('reports the products no longer available and the format errors on their lines', async () => {
    const user = userEvent.setup()
    fillCart()
    let answer = problem(422, 'PRODUCT_NOT_AVAILABLE', 'Un produit n’est pas disponible pour vous ce jour-là.', { violations: [{ line: 1, product_id: lben.id }] })
    cartApi({ [ORDERS_URL]: () => answer })
    renderClient('/client/panier')

    await sendOrder(user)
    await resumeRefused(user)
    expect(await screen.findByText('Un produit n’est pas disponible pour vous ce jour-là.')).toBeInTheDocument()
    expect(within(line('Lben 1 L')).getByText('Ce produit n’est plus disponible pour vous : retirez-le.')).toBeInTheDocument()
    expect(line('Jus orange 1 L')).not.toHaveTextContent('disponible pour vous')

    answer = problem(422, 'VALIDATION_FAILED', 'Les données envoyées sont invalides.', {
      errors: { 'lines.0.quantity': ['La quantité doit être un nombre entier de 1 à 1 000 000.'], comment: ['Le commentaire est trop long.'] },
    })
    await sendOrder(user)
    await resumeRefused(user)
    expect(await screen.findByText('Le commentaire est trop long.')).toBeInTheDocument()
    expect(within(line('Jus orange 1 L')).getByText('La quantité doit être un nombre entier de 1 à 1 000 000.')).toBeInTheDocument()
    expect(line('Lben 1 L')).not.toHaveTextContent('retirez-le')
  })

  it.each<[string, Response, string]>([
    ['CLIENT_BLOCKED', problem(403, 'CLIENT_BLOCKED', 'Votre compte client est bloqué.'), 'Commande suspendue : votre compte client est bloqué. Contactez votre commercial régional.'],
    ['DAY_NOT_OPEN', problem(409, 'DAY_NOT_OPEN', 'Cette journée est clôturée.'), 'La prise de commande n’est pas ouverte pour cette journée : la commande n’a pas été envoyée.'],
    ['CUTOFF_PASSED', problem(409, 'CUTOFF_PASSED', 'L’heure limite (13:00) est dépassée.'), 'L’heure limite de commande est dépassée : la commande n’a pas été envoyée.'],
    ['MAX_ORDERS_REACHED', problem(409, 'MAX_ORDERS_REACHED', 'Déjà envoyée.', { max_orders_per_day: 1 }), 'Vous avez déjà envoyé votre commande pour cette journée.'],
    [
      'ORDER_BELOW_MINIMUM',
      problem(422, 'ORDER_BELOW_MINIMUM', 'Minimum.', { min_amount_ht: 5000, total_ht: 3613.5 }),
      plain(`Montant minimum de commande : ${formatAmount(5000)} HT (votre commande : ${formatAmount(3613.5)} HT).`),
    ],
  ])('shows the server reason of a refusal %s, then explains it once resumed in the cart', async (_, answer, message) => {
    const user = userEvent.setup()
    fillCart()
    const detail = ((await answer.clone().json()) as { detail: string }).detail
    const { todayQueries } = cartApi({ [ORDERS_URL]: () => answer.clone() })
    renderClient('/client/panier')

    await sendOrder(user)
    expect(await within(await outboxBlock()).findByText(`Refusée : ${detail}`)).toBeInTheDocument()
    // La journée est relue : ce qui empêche l'envoi s'affiche.
    await waitFor(() => expect(todayQueries().length).toBeGreaterThan(1))

    await resumeRefused(user)
    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(message)
    expect(storedCart()?.pending).toBeNull()
    expect(storedCart()?.lines).toHaveLength(2)
  })

  it('warns below the minimum amount and says why the order cannot be sent', async () => {
    fillCart()
    cartApi({}, makeToday({ min_amount_ht: 5000, can_submit: false, submit_blocker: 'CUTOFF_PASSED', status: 'ORDERING_CLOSED' }))
    renderClient('/client/panier')

    expect(await screen.findByText(plain(`Montant minimum de commande : ${formatAmount(5000)} HT.`))).toBeInTheDocument()
    expect(screen.getByText('L’heure limite de commande est passée.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Envoyer la commande' })).toBeDisabled()
  })

  it('sends a new order offline through the outbox: the order waits on the device and the cart is emptied', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    const user = userEvent.setup()
    fillCart()
    const { requests } = cartApi({
      [ORDERS_URL]: () => {
        throw new TypeError('Failed to fetch')
      },
    })
    renderClient('/client/panier')

    expect(await screen.findByText(/^Hors ligne — données de \d\d:\d\d$/)).toBeInTheDocument()
    expect(screen.getByText('Hors ligne : la commande sera enregistrée sur cet appareil et envoyée dès le retour du réseau.')).toBeInTheDocument()
    await user.click(within(line('Lben 1 L')).getByRole('button', { name: 'Augmenter la quantité de Lben 1 L' }))
    expect(storedCart()?.lines[1]).toEqual({ product_id: lben.id, quantity: 8 })

    await sendOrder(user)
    expect(await screen.findByText('Commande enregistrée sur cet appareil : elle partira dès le retour du réseau.')).toBeInTheDocument()
    expect(within(await outboxBlock()).getByText('En attente d’envoi')).toBeInTheDocument()
    expect(storedCart()).toBeNull()
    const [entry] = await listEntries(USER_ID)
    expect(entry?.body.lines).toEqual([
      { product_id: orange.id, quantity: 3 },
      { product_id: lben.id, quantity: 8 },
    ])
    expect(entry).toMatchObject({ status: 'pending', day: DAY })
    expect(requests(ORDERS_URL)).toHaveLength(1)
  })

  it('cannot send a second order for a day that already has one waiting on the device (I-112)', async () => {
    fillCart()
    await enqueue({ user_id: USER_ID, client_id: CLIENT_ID, body: { client_request_id: 'waiting-1', day: DAY, comment: null, lines: [{ product_id: pomme.id, quantity: 2 }] } })
    cartApi()
    renderClient('/client/panier')

    expect(await screen.findByText('Une commande est déjà en attente d’envoi pour cette journée.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Envoyer la commande' })).toBeDisabled()
    expect(await outboxBlock()).toHaveTextContent('Commande du jeudi 08/10/2026 · 1 produit')
  })

  it('keeps the modifications of a submitted order for the network, offline (D-13)', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    testCart().startEditing(makeOrder({ version: 2 }))
    cartApi({}, makeToday({ orders: [makeSummary({ version: 2 })], products: [{ ...orange, available: 50 }, pomme, lben] }))
    renderClient('/client/panier')

    expect(
      await screen.findByText('Hors ligne : l’enregistrement des modifications reprendra au retour du réseau. Votre panier reste enregistré sur cet appareil.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer les modifications' })).toBeDisabled()
  })

  it('says offline that nothing is saved on the device for the day, the cart staying on the device', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    onlineManager.setOnline(false)
    try {
      fillCart()
      const { todayQueries } = cartApi()
      renderClient('/client/panier')

      expect(await screen.findByText('Pas de données enregistrées sur cet appareil pour cet écran : reconnectez-vous au réseau.')).toBeInTheDocument()
      expect(screen.getByText('Votre panier reste enregistré sur cet appareil.')).toBeInTheDocument()
      expect(todayQueries()).toHaveLength(0)
      expect(storedCart()?.lines).toHaveLength(2)

      // Retour du réseau : la journée est lue.
      act(() => onlineManager.setOnline(true))
      expect(await screen.findByRole('article', { name: 'Jus orange 1 L' })).toBeInTheDocument()
    } finally {
      onlineManager.setOnline(true)
    }
  })

  it('saves the modifications of a submitted order with its version (PATCH)', async () => {
    const user = userEvent.setup()
    testCart().startEditing(makeOrder({ version: 2, comment: 'Livrer tôt' }))
    const patchUrl = `PATCH /api/v1/client/orders/${ORDER_ID}`
    const { requests, todayQueries } = cartApi(
      { [patchUrl]: () => json(makeOrder({ version: 3 })) },
      // Journée de la commande, disponible hors de ses quantités (I-62) ; maximum atteint, mais commande modifiable.
      makeToday({ can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED', orders: [makeSummary({ version: 2 })], products: [{ ...orange, available: 50 }, pomme, lben] }),
    )
    renderClient('/client/panier')

    expect(await screen.findByRole('heading', { name: `Modification de la commande ${ORDER_NUMBER}` })).toBeInTheDocument()
    expect(todayQueries()[0]).toEqual({ day: DAY, order: ORDER_ID })
    const orangeLine = await screen.findByRole('article', { name: 'Jus orange 1 L' })
    expect(within(orangeLine).getByRole('textbox', { name: 'Quantité de Jus orange 1 L' })).toHaveValue('45')
    await user.click(within(orangeLine).getByRole('button', { name: 'Diminuer la quantité de Jus orange 1 L' }))

    await sendOrder(user, 'Enregistrer les modifications', 'Confirmer les modifications')
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(`/client/commandes/${ORDER_ID}`))
    expect(screen.getByTestId('flash')).toHaveTextContent(`Commande ${ORDER_NUMBER} modifiée.`)
    expect(requests(patchUrl)[0]?.body).toEqual({ version: 2, comment: 'Livrer tôt', lines: [{ product_id: orange.id, quantity: 44 }] })
    expect(requests(ORDERS_URL)).toHaveLength(0)
    expect(storedCart()).toBeNull()
  })

  it('reloads the order after a VERSION_CONFLICT and offers to restart the modification from it', async () => {
    const user = userEvent.setup()
    testCart().startEditing(makeOrder({ version: 2 }))
    const current = makeOrder({ version: 3, lines: [{ ...makeOrder().lines[0]!, requested_qty: 20 }], comment: 'Changée ailleurs' })
    const { requests } = cartApi(
      {
        [`PATCH /api/v1/client/orders/${ORDER_ID}`]: () => problem(409, 'VERSION_CONFLICT', 'La commande a été modifiée entre-temps : rechargez-la.', { version: 3 }),
        [`GET /api/v1/client/orders/${ORDER_ID}`]: () => json(current),
      },
      makeToday({ orders: [makeSummary({ version: 2 })], products: [{ ...orange, available: 50 }, pomme, lben] }),
    )
    renderClient('/client/panier')

    await user.click(within(await screen.findByRole('article', { name: 'Jus orange 1 L' })).getByRole('button', { name: 'Diminuer la quantité de Jus orange 1 L' }))
    await sendOrder(user, 'Enregistrer les modifications', 'Confirmer les modifications')

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(`La commande ${ORDER_NUMBER} a été modifiée entre-temps, depuis un autre appareil ou un autre compte.`)
    expect(alert).toHaveTextContent('Reprenez la modification à partir de sa version actuelle.')
    expect(requests(`GET /api/v1/client/orders/${ORDER_ID}`)).toHaveLength(1)
    // Rien n'est perdu tant que le client n'a pas choisi.
    expect(testCart().get().lines).toEqual([{ product_id: orange.id, quantity: 44 }])

    await user.click(within(alert).getByRole('button', { name: 'Reprendre la modification' }))
    expect(testCart().get()).toMatchObject({
      lines: [{ product_id: orange.id, quantity: 20 }],
      comment: 'Changée ailleurs',
      editing: { orderId: ORDER_ID, number: ORDER_NUMBER, version: 3 },
    })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(within(line('Jus orange 1 L')).getByRole('textbox', { name: 'Quantité de Jus orange 1 L' })).toHaveValue('20')
  })

  it('takes a VERSION_CONFLICT for a success when the order already holds the cart (lost answer)', async () => {
    const user = userEvent.setup()
    testCart().startEditing(makeOrder({ version: 2 }))
    cartApi(
      {
        [`PATCH /api/v1/client/orders/${ORDER_ID}`]: () => problem(409, 'VERSION_CONFLICT', 'Conflit.', { version: 3 }),
        [`GET /api/v1/client/orders/${ORDER_ID}`]: () => json(makeOrder({ version: 3 })),
      },
      makeToday({ orders: [makeSummary({ version: 2 })], products: [{ ...orange, available: 50 }, pomme, lben] }),
    )
    renderClient('/client/panier')

    await sendOrder(user, 'Enregistrer les modifications', 'Confirmer les modifications')
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(`/client/commandes/${ORDER_ID}`))
    expect(storedCart()).toBeNull()
  })

  it('offers to abandon a modification that is no longer possible', async () => {
    const user = userEvent.setup()
    testCart().startEditing(makeOrder())
    cartApi({}, makeToday({ status: 'ORDERING_CLOSED', can_submit: false, submit_blocker: 'CUTOFF_PASSED', orders: [makeSummary({ can_modify: false, can_cancel: false })] }))
    renderClient('/client/panier')

    expect(await screen.findByRole('button', { name: 'Enregistrer les modifications' })).toBeDisabled()
    expect(screen.getAllByText('Cette commande ne peut plus être modifiée.').length).toBeGreaterThan(0)
    await user.click(screen.getByRole('button', { name: 'Abandonner la modification' }))
    expect(storedCart()).toBeNull()
    expect(await screen.findByText('Votre panier est vide.')).toBeInTheDocument()
  })

  it('removes the products no longer available and caps the quantities at each reading of the day', async () => {
    let today = makeToday()
    testCart().setQuantity(DAY, orange.id, 30)
    testCart().setQuantity(DAY, lben.id, 7)
    testCart().setComment('Livrer avant 10 h')
    cartApi({}, () => today)
    const { queryClient } = renderClient('/client/panier')

    await screen.findByRole('article', { name: 'Jus orange 1 L' })
    expect(screen.queryByText('Votre panier a été mis à jour')).not.toBeInTheDocument()

    // Relecture (temps réel, 30 s) : borne baissée, Lben retiré du catalogue.
    today = makeToday({ products: [{ ...orange, available: 12 }, pomme] })
    await act(() => queryClient.invalidateQueries({ queryKey: ['client'] }))

    const notice = await screen.findByText('Votre panier a été mis à jour')
    expect(notice.parentElement).toHaveTextContent('« Jus orange 1 L » : quantité ramenée de 30 à 12.')
    expect(notice.parentElement).toHaveTextContent('« Lben 1 L » retiré : il n’est plus disponible.')
    expect(storedCart()).toMatchObject({ lines: [{ product_id: orange.id, quantity: 12 }], comment: 'Livrer avant 10 h' })
    expect(screen.queryByRole('article', { name: 'Lben 1 L' })).not.toBeInTheDocument()
  })

  it('empties a cart of another day with a notice', async () => {
    testCart().setQuantity('2026-10-07', orange.id, 3)
    cartApi()
    renderClient('/client/panier')

    expect(await screen.findByText('Votre panier du mercredi 07/10/2026 a été vidé : la journée de commande a changé.')).toBeInTheDocument()
    expect(screen.getByText('Votre panier est vide.')).toBeInTheDocument()
    expect(storedCart()).toBeNull()
  })
})
