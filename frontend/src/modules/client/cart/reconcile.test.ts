import { afterEach, describe, expect, it } from 'vitest'
import { ApiError, NetworkError } from '../../../shared/api/client'
import { totals } from '../../../shared/money'
import { purgeSessionStorage } from '../../../shared/storage'
import { MAX_LINE_QUANTITY } from '../../../shared/ui/QuantityStepper'
import { filterProducts, searchable, withParam } from '../catalogue/view'
import { json, mockApi, problem } from '../../../test/utils'
import { isRetryable, postOrder, sameContent, sendIssues } from './api'
import { cartItems, cartTotals, lineAmount, normalizeComment, orderLines, reconcileLines } from './reconcile'
import { cartKey, CartStore } from './store'
import { CUTOFF_MARGIN_MS, cutoffDelay, syncCart } from './sync'
import { DAY, fraise, lben, makeOrder, makeSummary, makeToday, ORDER_ID, orange, orderOf, pomme } from './testing'

afterEach(() => {
  purgeSessionStorage()
  localStorage.clear()
})

const products = [orange, pomme, fraise, lben]

describe('reconciliation of the cart with the catalogue of the day', () => {
  it('removes what can no longer be ordered, caps above the available and keeps the order of the lines', () => {
    const result = reconcileLines(
      [
        { product_id: pomme.id, quantity: 600 },
        { product_id: orange.id, quantity: 45 },
        { product_id: fraise.id, quantity: 2 },
        { product_id: 99, quantity: 4 },
        { product_id: 100, quantity: 1 },
        { product_id: lben.id, quantity: 7 },
      ],
      products,
      new Map([[99, 'Jus mangue 1 L']]),
    )

    expect(result.lines).toEqual([
      // Sans borne : rien n'est plafonné, le serveur reste juge (RG-C09).
      { product_id: pomme.id, quantity: 600 },
      { product_id: orange.id, quantity: 40 },
      { product_id: lben.id, quantity: 7 },
    ])
    expect(result.changes).toEqual([
      { kind: 'capped', productId: orange.id, name: 'Jus orange 1 L', from: 45, to: 40 },
      { kind: 'removed', productId: fraise.id, name: 'Jus fraise 1 L', quantity: 2 },
      { kind: 'removed', productId: 99, name: 'Jus mangue 1 L', quantity: 4 },
      { kind: 'removed', productId: 100, name: null, quantity: 1 },
    ])
  })

  it('changes nothing in a cart that fits, and caps at the maximum of a line without a borne', () => {
    const lines = [
      { product_id: orange.id, quantity: 40 },
      { product_id: pomme.id, quantity: 12 },
    ]
    expect(reconcileLines(lines, products)).toEqual({ lines, changes: [] })
    expect(reconcileLines([{ product_id: pomme.id, quantity: MAX_LINE_QUANTITY + 1 }], products).lines).toEqual([{ product_id: pomme.id, quantity: MAX_LINE_QUANTITY }])
    // Disponible à 0 sans « épuisé » : retiré quand même.
    expect(reconcileLines([{ product_id: orange.id, quantity: 1 }], [{ ...orange, available: 0 }]).lines).toEqual([])
  })

  it('prices the lines at the price of the sales unit of the day, with the rounding of the server', () => {
    const items = cartItems(
      [
        { product_id: orange.id, quantity: 3 },
        { product_id: 99, quantity: 1 },
        { product_id: lben.id, quantity: 7 },
      ],
      products,
    )
    expect(items.map((item) => item.product.id)).toEqual([orange.id, lben.id])
    expect(cartTotals(items)).toEqual(
      totals([
        { unit_price_ht: 900, vat_rate: 19, quantity: 3 },
        { unit_price_ht: 130.5, vat_rate: 9, quantity: 7 },
      ]),
    )
    expect(cartTotals(items)).toEqual({ ht: 3613.5, vat: 595.22, ttc: 4208.72 })
    expect(lineAmount(items[1]!)).toBe(913.5)
    expect(cartTotals([])).toEqual({ ht: 0, vat: 0, ttc: 0 })
  })

  it('sends the lines in the order of the cart and a trimmed comment, null when empty', () => {
    expect(orderLines([{ product_id: 42, quantity: 3 }])).toEqual([{ product_id: 42, quantity: 3 }])
    expect(normalizeComment('  Livrer tôt ')).toBe('Livrer tôt')
    expect(normalizeComment('   ')).toBeNull()
  })
})

describe('cart kept in step with the day of the client', () => {
  const store = () => new CartStore(cartKey(9, 12))

  it('empties a new cart left on another day, with a notice only when it held lines', () => {
    const cart = store()
    cart.setQuantity('2026-10-07', orange.id, 3)
    expect(syncCart(cart, makeToday())).toEqual({ kind: 'dayChanged', day: '2026-10-07' })
    expect(cart.get().lines).toEqual([])

    const commentOnly = store()
    commentOnly.setComment('Livrer tôt')
    commentOnly.setQuantity('2026-10-07', orange.id, 1)
    commentOnly.setQuantity('2026-10-07', orange.id, 0)
    expect(syncCart(commentOnly, makeToday())).toBeNull()
    expect(commentOnly.get().comment).toBe('')
  })

  it('empties the cart of a closed day', () => {
    const cart = store()
    cart.setQuantity(DAY, orange.id, 3)
    expect(syncCart(cart, makeToday({ status: 'DAY_CLOSED', products: [], families: [] }))).toEqual({ kind: 'dayClosed', day: DAY })
    expect(cart.get().lines).toEqual([])
  })

  it('reconciles the cart of the day and keeps its comment, its interrupted sending and its modification', () => {
    const cart = store()
    cart.startEditing(makeOrder({ comment: 'Livrer tôt', lines: [{ ...makeOrder().lines[0]!, requested_qty: 60 }] }))
    cart.beginSend()
    const pending = cart.get().pending

    const notice = syncCart(cart, makeToday({ orders: [makeSummary()], products: [{ ...orange, available: 50 }] }))
    expect(notice).toEqual({ kind: 'reconciled', changes: [{ kind: 'capped', productId: orange.id, name: 'Jus orange 1 L', from: 60, to: 50 }] })
    expect(cart.get()).toMatchObject({ lines: [{ product_id: orange.id, quantity: 50 }], comment: 'Livrer tôt', editing: { orderId: ORDER_ID }, pending })
    expect(syncCart(cart, makeToday({ orders: [makeSummary()], products: [{ ...orange, available: 50 }] }))).toBeNull()
  })

  it('leaves the lines of an interrupted sending alone: the day may already count them', () => {
    const cart = store()
    cart.setQuantity(DAY, orange.id, 40)
    cart.setQuantity(DAY, 99, 2)
    cart.beginSend()
    const before = cart.get()
    const counted = makeToday({ can_submit: false, submit_blocker: 'MAX_ORDERS_REACHED', orders: [makeSummary()], products: [{ ...orange, engaged: 50, available: 0, sold_out: true }] })
    expect(syncCart(cart, counted)).toBeNull()
    expect(cart.get()).toBe(before)

    // Refus définitif : l'identifiant abandonné, le rapprochement reprend.
    cart.abandonSend()
    expect(syncCart(cart, counted)).toMatchObject({ kind: 'reconciled', changes: [{ kind: 'removed', productId: orange.id }, { kind: 'removed', productId: 99 }] })
    expect(cart.get().lines).toEqual([])

    // Journée changée : le panier est vidé, envoi interrompu compris.
    const old = store()
    old.setQuantity('2026-10-07', orange.id, 3)
    old.beginSend()
    expect(syncCart(old, makeToday())).toEqual({ kind: 'dayChanged', day: '2026-10-07' })
    expect(old.get().pending).toBeNull()
  })

  it('leaves alone an order that can no longer be modified, or the reading of another day', () => {
    const cart = store()
    cart.startEditing(makeOrder())
    const before = cart.get()
    expect(syncCart(cart, makeToday({ orders: [makeSummary({ can_modify: false })], products: [] }))).toBeNull()
    expect(syncCart(cart, makeToday({ day: '2026-10-09', orders: [makeSummary()], products: [] }))).toBeNull()
    expect(cart.get()).toBe(before)
  })
})

describe('cutoff on the clock of the server (D-10)', () => {
  it('waits until the cutoff of the server, whatever the clock of the device', () => {
    const receivedAt = Date.parse('2026-10-18T10:00:00Z')
    // Serveur à 11:59:50 UTC, appareil dix jours plus tard, 2 s après la réception.
    expect(cutoffDelay('2026-10-08T12:00:00+00:00', '2026-10-08T11:59:50+00:00', receivedAt, receivedAt + 2_000)).toBe(8_000 + CUTOFF_MARGIN_MS)
    // Données relues du cache, heure limite passée depuis la réception : relecture aussitôt (après la marge).
    expect(cutoffDelay('2026-10-08T12:00:00+00:00', '2026-10-08T11:00:00+00:00', receivedAt, receivedAt + 3_600_000 + 5_000)).toBe(CUTOFF_MARGIN_MS)
    // Heure limite déjà passée quand le serveur a répondu : rien à attendre (pas de relecture en boucle).
    expect(cutoffDelay('2026-10-08T12:00:00+00:00', '2026-10-08T12:00:05+00:00', receivedAt, receivedAt)).toBeNull()
    expect(cutoffDelay('2026-10-08T12:00:00+00:00', '2026-10-08T12:00:00+00:00', receivedAt, receivedAt)).toBe(CUTOFF_MARGIN_MS)
    // Délai plafonné à celui d'un setTimeout.
    expect(cutoffDelay('2027-10-08T12:00:00+00:00', '2026-10-08T12:00:00+00:00', receivedAt, receivedAt)).toBe(2_147_483_647)
  })
})

describe('sending of the cart', () => {
  const refusal = (status: number, code: string, extra: Record<string, unknown> = {}) => new ApiError({ status, code, detail: 'Refus', ...extra })

  const input = { client_request_id: '0b6f1c1e-3c55-4f43-9a51-7f0f4d1c2b3a', day: DAY, comment: null, lines: [{ product_id: orange.id, quantity: 3 }] }

  it('tells a new order (201) from the resending of a request id already received (200)', async () => {
    let status = 201
    const { calls } = mockApi({ 'POST /api/v1/client/orders': () => json(orderOf(input.lines), status) })

    await expect(postOrder(input)).resolves.toMatchObject({ created: true, order: { id: ORDER_ID } })
    status = 200
    await expect(postOrder(input)).resolves.toMatchObject({ created: false, order: { id: ORDER_ID } })
    const posts = calls.filter((call) => call.method === 'POST')
    expect(posts.map((call) => call.body)).toEqual([input, input])
    expect(posts[0]?.headers.get('X-XSRF-TOKEN')).toBe('token=1')
  })

  it('renews an expired anti-CSRF token once, and reports refusals and network errors like the other requests', async () => {
    let attempt = 0
    const { calls } = mockApi({
      'POST /api/v1/client/orders': () => (++attempt === 1 ? problem(419, 'CSRF_TOKEN_MISMATCH', 'Jeton expiré') : json(orderOf(input.lines), 201)),
    })
    await expect(postOrder(input)).resolves.toMatchObject({ created: true })
    expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual([
      'GET /sanctum/csrf-cookie',
      'POST /api/v1/client/orders',
      'GET /sanctum/csrf-cookie',
      'POST /api/v1/client/orders',
    ])

    mockApi({ 'POST /api/v1/client/orders': () => problem(409, 'CUTOFF_PASSED', 'Heure limite dépassée.') })
    await expect(postOrder(input)).rejects.toMatchObject({ name: 'ApiError', status: 409, code: 'CUTOFF_PASSED' })

    mockApi({
      'POST /api/v1/client/orders': () => {
        throw new TypeError('Failed to fetch')
      },
    })
    await expect(postOrder(input)).rejects.toBeInstanceOf(NetworkError)
  })

  it('keeps the request id when the server may have received the sending', () => {
    expect(isRetryable(new NetworkError())).toBe(true)
    expect(isRetryable(refusal(503, 'SERVER_ERROR'))).toBe(true)
    expect(isRetryable(refusal(429, 'TOO_MANY_REQUESTS'))).toBe(true)
    expect(isRetryable(refusal(401, 'UNAUTHENTICATED'))).toBe(true)
    expect(isRetryable(new Error('inattendue'))).toBe(true)
    for (const [status, code] of [
      [403, 'CLIENT_BLOCKED'],
      [409, 'CUTOFF_PASSED'],
      [409, 'CLIENT_LIMIT_EXCEEDED'],
      [422, 'VALIDATION_FAILED'],
      [404, 'NOT_FOUND'],
    ] as const) {
      expect(isRetryable(refusal(status, code))).toBe(false)
    }
  })

  it('maps the violations and the errors of the lines to the products sent', () => {
    const sent = [
      { product_id: orange.id, quantity: 45 },
      { product_id: pomme.id, quantity: 300 },
      { product_id: lben.id, quantity: 7 },
    ]
    const limits = sendIssues(
      refusal(409, 'CLIENT_LIMIT_EXCEEDED', {
        violations: [
          { line: 0, product_id: orange.id, requested: 45, max_allowed: 40 },
          { line: 1, product_id: pomme.id, requested: 300, max_allowed: null },
        ],
      }),
      sent,
    )
    expect([...limits.lines]).toEqual([
      [orange.id, { kind: 'limit', max: 40, quantity: 45 }],
      [pomme.id, { kind: 'limit', max: null, quantity: 300 }],
    ])

    expect([...sendIssues(refusal(422, 'PRODUCT_NOT_AVAILABLE', { violations: [{ line: 2, product_id: lben.id }] }), sent).lines]).toEqual([
      [lben.id, { kind: 'unavailable', quantity: 7 }],
    ])

    const format = sendIssues(
      refusal(422, 'VALIDATION_FAILED', {
        errors: { 'lines.1.quantity': ['Quantité invalide.'], 'lines.1.product_id': ['Produit en double.'], comment: ['Trop long.'], 'lines.9.quantity': ['Hors panier.'] },
      }),
      sent,
    )
    expect([...format.lines]).toEqual([[pomme.id, { kind: 'field', quantity: 300, messages: ['Quantité invalide.', 'Produit en double.'] }]])
    expect(format.fields).toEqual(['Trop long.', 'Hors panier.'])
  })

  it('recognises an order that already holds the cart: same day, lines and trimmed comment', () => {
    const order = orderOf(
      [
        { product_id: orange.id, quantity: 45 },
        { product_id: lben.id, quantity: 7 },
      ],
      { comment: 'Livrer tôt' },
    )
    const cart = (lines: { product_id: number; quantity: number }[], comment = 'Livrer tôt', day = DAY) => ({ day, lines, comment })
    const lines = [
      { product_id: lben.id, quantity: 7 },
      { product_id: orange.id, quantity: 45 },
    ]
    // Ordre des lignes indifférent, espaces autour du commentaire ignorés.
    expect(sameContent(order, cart(lines, ' Livrer tôt '))).toBe(true)
    expect(sameContent(order, cart([{ product_id: orange.id, quantity: 44 }, lines[0]!]))).toBe(false)
    expect(sameContent(order, cart(lines, ''))).toBe(false)
    expect(sameContent(order, cart([...lines, { product_id: pomme.id, quantity: 1 }]))).toBe(false)
    expect(sameContent(order, cart(lines.slice(1)))).toBe(false)
    expect(sameContent(order, cart(lines, 'Livrer tôt', '2026-10-09'))).toBe(false)
    expect(sameContent(makeOrder(), cart([{ product_id: orange.id, quantity: 45 }], ''))).toBe(true)
  })
})

describe('catalogue filters', () => {
  it('searches the name, the short name and the code, without case or accents, within a family', () => {
    expect(searchable('  Élan Été ')).toBe('elan ete')
    expect(filterProducts(products, null, '').map((product) => product.id)).toEqual([42, 57, 58, 61])
    expect(filterProducts(products, null, 'ramy').map((product) => product.id)).toEqual([57])
    expect(filterProducts(products, null, 'P0061').map((product) => product.id)).toEqual([61])
    expect(filterProducts(products, 1, '').map((product) => product.id)).toEqual([61])
    expect(filterProducts(products, 1, 'orange')).toEqual([])
  })

  it('changes one parameter of the address and keeps the others', () => {
    const params = new URLSearchParams('famille=2&q=jus')
    expect(withParam(params, 'produit', '42').toString()).toBe('famille=2&q=jus&produit=42')
    expect(withParam(params, 'q', '').toString()).toBe('famille=2')
    expect(withParam(params, 'famille', null).toString()).toBe('q=jus')
    expect(params.toString()).toBe('famille=2&q=jus')
  })
})
