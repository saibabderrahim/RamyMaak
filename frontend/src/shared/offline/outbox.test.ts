import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Order, SubmitOrderInput } from '../api/types'
import { db } from './db'
import {
  claim,
  decodeXsrf,
  enqueue,
  fill,
  getEntry,
  hasWaitingEntry,
  listEntries,
  needsRetry,
  ORDERS_ENDPOINT,
  OutboxBusyError,
  type OutboxDeps,
  outboxNotice,
  processEntry,
  processPending,
  readSessionUser,
  removeEntry,
  removeSent,
  removeUserEntries,
  SENDING_STALE_MS,
  sendEntry,
  writeSessionUser,
} from './outbox'
import { isCacheablePhoto, isPhotoRequest } from './photos'

// Boîte d'envoi des commandes client (lot 8, contrat §6.3) : logique partagée par l'application et le service worker.

const USER = 9
const DAY = '2026-10-08'
const ORDER = { id: '01JA2Z6K8QW3X9M4T7RBN5CDEF', number: 'RMK-261008-00042', status: 'SUBMITTED' } as Order

const body = (id = 'req-1', day = DAY): SubmitOrderInput => ({ client_request_id: id, day, comment: null, lines: [{ product_id: 42, quantity: 3 }] })
const put = (id = 'req-1', day = DAY, user = USER) => enqueue({ user_id: user, client_id: 12, body: body(id, day) })

const respond = (status: number, payload: unknown = null) =>
  new Response(payload === null ? null : JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } })
const problem = (status: number, code: string, detail: string) => respond(status, { type: 'about:blank', title: code, status, code, detail })

/** Envoi simulé : `answer` à chaque requête, jeton anti-CSRF fixe. */
function deps(answer: () => Response | Promise<Response>): { fetch: ReturnType<typeof vi.fn>; deps: OutboxDeps } {
  const fetch = vi.fn(async () => answer())
  return { fetch, deps: { fetch, xsrf: async () => 'jeton' } }
}

afterEach(() => vi.restoreAllMocks())

describe('outbox: sending (§6.3)', () => {
  it('posts the saved body with the session cookie and the anti-CSRF token', async () => {
    const fetch = vi.fn(async () => respond(201, ORDER))
    const outcome = await sendEntry({ body: body() }, fetch, 'jeton=1')

    expect(outcome).toEqual({ status: 'sent', order: ORDER, created: true })
    expect(fetch).toHaveBeenCalledWith(ORDERS_ENDPOINT, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': 'jeton=1' },
      body: JSON.stringify(body()),
    })
  })

  it.each<[string, () => Response | Promise<Response>, unknown]>([
    ['201: a new order', () => respond(201, ORDER), { status: 'sent', created: true }],
    ['200: an id already received, the existing order', () => respond(200, ORDER), { status: 'sent', created: false }],
    ['a network error', () => Promise.reject(new TypeError('Failed to fetch')), { status: 'pending', reason: 'network' }],
    ['a 503', () => problem(503, 'SERVER_ERROR', 'Indisponible'), { status: 'pending', reason: 'server', httpStatus: 503 }],
    ['a 408', () => respond(408), { status: 'pending', reason: 'server', httpStatus: 408 }],
    ['a 429', () => problem(429, 'TOO_MANY_REQUESTS', 'Trop'), { status: 'pending', reason: 'server', httpStatus: 429 }],
    ['a 419', () => problem(419, 'CSRF_TOKEN_MISMATCH', 'Jeton'), { status: 'pending', reason: 'csrf' }],
    ['a 401', () => problem(401, 'UNAUTHENTICATED', 'Session expirée'), { status: 'pending', reason: 'unauthenticated' }],
    ['an unreadable 201', () => new Response('<html>', { status: 201 }), { status: 'pending', reason: 'server' }],
  ])('turns %s into its outcome', async (_, answer, expected) => {
    expect(await sendEntry({ body: body() }, async () => answer(), null)).toMatchObject(expected as object)
  })

  it('refuses on any other 4xx, with the reason given by the server', async () => {
    const outcome = await sendEntry({ body: body() }, async () => problem(409, 'CUTOFF_PASSED', 'L’heure limite de commande (13:00) est passée.'), null)
    expect(outcome).toMatchObject({ status: 'refused', error: { code: 'CUTOFF_PASSED', detail: 'L’heure limite de commande (13:00) est passée.' } })
    expect(await sendEntry({ body: body() }, async () => respond(422, { message: 'non' }), null)).toMatchObject({
      status: 'refused',
      error: { code: 'HTTP_ERROR', detail: '' },
    })
  })

  it('records each outcome on the entry, with the number of attempts', async () => {
    let answer: () => Response | Promise<Response> = () => Promise.reject(new TypeError('offline'))
    const { deps: sendDeps } = deps(() => answer())
    await put()

    expect((await processEntry('req-1', sendDeps))?.entry).toMatchObject({ status: 'pending', attempts: 1, error: null, sending_at: null })
    answer = () => problem(401, 'UNAUTHENTICATED', 'Session expirée')
    expect((await processEntry('req-1', sendDeps))?.entry).toMatchObject({ status: 'pending', attempts: 2, error: { code: 'UNAUTHENTICATED' } })
    answer = () => respond(200, ORDER)
    const sent = await processEntry('req-1', sendDeps)
    expect(sent?.entry).toMatchObject({ status: 'sent', attempts: 3, error: null, order: { id: ORDER.id, number: ORDER.number } })
    expect(sent?.entry.sent_at).toEqual(expect.any(String))
    expect(await getEntry('req-1')).toEqual(sent?.entry)
    // Envoyée : plus rien à envoyer.
    expect(await processEntry('req-1', sendDeps)).toBeNull()
  })

  it('keeps a refused order with its reason, and never sends it again', async () => {
    const { deps: sendDeps, fetch } = deps(() => problem(422, 'ORDER_BELOW_MINIMUM', 'Montant minimum : 5 000,00 DA HT.'))
    await put()
    expect((await processEntry('req-1', sendDeps))?.entry).toMatchObject({
      status: 'refused',
      error: { code: 'ORDER_BELOW_MINIMUM', detail: 'Montant minimum : 5 000,00 DA HT.' },
    })
    expect(await processPending(USER, sendDeps)).toEqual([])
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('renews the anti-CSRF token once after a 419', async () => {
    const answers = [problem(419, 'CSRF_TOKEN_MISMATCH', 'Jeton'), respond(201, ORDER)]
    const fetch = vi.fn(async (_input: string, _init: RequestInit) => answers.shift()!)
    const xsrf = vi.fn(async (renew: boolean) => (renew ? 'nouveau' : 'ancien'))
    await put()

    expect((await processEntry('req-1', { fetch, xsrf }))?.outcome.status).toBe('sent')
    expect(xsrf.mock.calls).toEqual([[false], [true]])
    expect(fetch.mock.calls.map(([, init]) => (init.headers as Record<string, string>)['X-XSRF-TOKEN'])).toEqual(['ancien', 'nouveau'])
  })

  it('stays pending when the token cannot be read (network down)', async () => {
    await put()
    const outcome = await processEntry('req-1', { fetch: vi.fn(), xsrf: () => Promise.reject(new TypeError('offline')) })
    expect(outcome?.entry.status).toBe('pending')
  })

  it('sends an entry once at a time, across tabs and the service worker', async () => {
    let release: (response: Response) => void = () => undefined
    const fetch = vi.fn(() => new Promise<Response>((resolve) => (release = resolve)))
    await put()

    const first = processEntry('req-1', { fetch, xsrf: async () => null })
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))
    // Pendant l'envoi : ni second envoi, ni retrait.
    expect(await processEntry('req-1', { fetch, xsrf: async () => null })).toBeNull()
    expect(await removeEntry('req-1')).toBe(false)
    release(respond(201, ORDER))
    expect((await first)?.entry.status).toBe('sent')
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('takes back a sending left over for more than 2 minutes (tab closed during the sending)', async () => {
    await put()
    const start = new Date('2026-10-08T08:00:00Z')
    expect(await claim('req-1', db, start)).toMatchObject({ status: 'sending', attempts: 1 })
    expect(await claim('req-1', db, new Date(start.getTime() + SENDING_STALE_MS - 1000))).toBeNull()
    expect(await claim('req-1', db, new Date(start.getTime() + SENDING_STALE_MS + 1000))).toMatchObject({ status: 'sending', attempts: 2 })
  })

  it('does not bring back an entry removed during the sending (logout)', async () => {
    let release: (response: Response) => void = () => undefined
    await put()
    const pending = processEntry('req-1', { fetch: () => new Promise<Response>((resolve) => (release = resolve)), xsrf: async () => null })
    await vi.waitFor(async () => expect((await getEntry('req-1'))?.status).toBe('sending'))
    await removeUserEntries(USER)
    release(respond(201, ORDER))
    await pending
    expect(await getEntry('req-1')).toBeUndefined()
  })

  it('sends only the entries of the account, the oldest first', async () => {
    await enqueue({ user_id: USER, client_id: 12, body: body('a', '2026-10-08') }, db, new Date('2026-10-08T07:00:00Z'))
    await enqueue({ user_id: USER, client_id: 12, body: body('b', '2026-10-09') }, db, new Date('2026-10-08T06:00:00Z'))
    await put('other', DAY, 10)
    const fetch = vi.fn(async (_: string, init: RequestInit) => respond(201, { ...ORDER, id: JSON.parse(String(init.body)).client_request_id }))
    const seen: string[] = []

    const results = await processPending(USER, { fetch, xsrf: async () => null }, (result) => seen.push(result.entry.id))
    expect(results.map((result) => result.entry.id)).toEqual(['b', 'a'])
    expect(seen).toEqual(['b', 'a'])
    expect((await getEntry('other'))?.status).toBe('pending')
  })
})

describe('outbox: one waiting order per day (I-112)', () => {
  it('refuses a second order for a day that already has one waiting, not for another day', async () => {
    await put('first')
    await expect(put('second')).rejects.toBeInstanceOf(OutboxBusyError)
    await expect(put('tomorrow', '2026-10-09')).resolves.toMatchObject({ status: 'pending' })
    // Autre compte sur le même appareil : sa propre journée.
    await expect(put('other', DAY, 10)).resolves.toMatchObject({ status: 'pending' })
    expect(await hasWaitingEntry(USER, DAY)).toBe(true)
    expect((await listEntries(USER)).map((entry) => entry.id)).toEqual(['first', 'tomorrow'])
  })

  it('accepts a new order once the waiting one is sent or refused', async () => {
    await put('first')
    await processEntry('first', { fetch: async () => problem(409, 'CUTOFF_PASSED', 'Trop tard.'), xsrf: async () => null })
    expect(await hasWaitingEntry(USER, DAY)).toBe(false)
    await expect(put('second')).resolves.toMatchObject({ id: 'second' })
  })
})

describe('outbox: clean-up', () => {
  it('forgets the sent orders read again since, and only them', async () => {
    await put('sent', DAY)
    await put('waiting', '2026-10-09')
    await processEntry('sent', { fetch: async () => respond(201, ORDER), xsrf: async () => null })
    const sentAt = Date.parse((await getEntry('sent'))!.sent_at!)

    expect(await removeSent(USER, (_, at) => at < sentAt)).toBe(0)
    expect(await removeSent(USER, (_, at) => at < sentAt + 1)).toBe(1)
    expect((await listEntries(USER)).map((entry) => entry.id)).toEqual(['waiting'])
  })

  it('keeps the account of the device for the service worker', async () => {
    expect(await readSessionUser()).toBeNull()
    await writeSessionUser(9)
    expect(await readSessionUser()).toBe(9)
    await writeSessionUser(null)
    expect(await readSessionUser()).toBeNull()
  })
})

describe('outbox: background sync helpers (service worker)', () => {
  it('announces a sent or refused order, nothing for an order still waiting', () => {
    const entry = { id: 'req-1' } as Parameters<typeof outboxNotice>[0]['entry']
    expect(outboxNotice({ entry, outcome: { status: 'sent', order: ORDER, created: true } })).toEqual({
      title: 'Commande RMK-261008-00042 envoyée',
      body: 'Votre commande enregistrée sur l’appareil a été reçue.',
      url: `/client/commandes/${ORDER.id}`,
      tag: 'outbox-req-1',
    })
    expect(
      outboxNotice({ entry, outcome: { status: 'refused', error: { code: 'CUTOFF_PASSED', detail: 'L’heure limite est passée.' }, problem: { status: 409, code: 'CUTOFF_PASSED' } } }),
    ).toMatchObject({ title: 'Commande refusée : L’heure limite est passée.', url: '/client/commandes' })
    expect(outboxNotice({ entry, outcome: { status: 'pending', reason: 'network', httpStatus: null } })).toBeNull()
  })

  it('asks the browser to try again while an order waits for the network, not for an expired session', () => {
    const entry = { id: 'req-1' } as Parameters<typeof outboxNotice>[0]['entry']
    expect(needsRetry([{ entry, outcome: { status: 'pending', reason: 'network', httpStatus: null } }])).toBe(true)
    expect(needsRetry([{ entry, outcome: { status: 'pending', reason: 'unauthenticated', httpStatus: 401 } }])).toBe(false)
    expect(needsRetry([{ entry, outcome: { status: 'sent', order: ORDER, created: true } }])).toBe(false)
  })

  it('reads the anti-CSRF token of the Cookie Store and fills the texts', () => {
    expect(decodeXsrf('eyJpdiI6%3D%3D')).toBe('eyJpdiI6==')
    expect(decodeXsrf(undefined)).toBeNull()
    expect(decodeXsrf('%E0%A4%A')).toBe('%E0%A4%A')
    expect(fill('Commande {{number}} envoyée', { number: 'RMK-1' })).toBe('Commande RMK-1 envoyée')
    expect(fill('{{inconnu}}', {})).toBe('{{inconnu}}')
  })
})

describe('photos in the service worker cache (§6.2)', () => {
  const origin = 'https://ramymaak.example'
  it('keeps the product photos of the application only, never the API', () => {
    expect(isPhotoRequest(new URL(`${origin}/media/products/42/p7-200.webp`), 'GET', origin)).toBe(true)
    expect(isPhotoRequest(new URL(`${origin}/api/v1/client/today`), 'GET', origin)).toBe(false)
    expect(isPhotoRequest(new URL('https://cdn.example/media/p.webp'), 'GET', origin)).toBe(false)
    expect(isPhotoRequest(new URL(`${origin}/media/p.webp`), 'POST', origin)).toBe(false)
  })

  it('saves complete answers only', () => {
    expect(isCacheablePhoto({ status: 200 })).toBe(true)
    expect(isCacheablePhoto({ status: 206 })).toBe(false)
    expect(isCacheablePhoto({ status: 404 })).toBe(false)
    expect(isCacheablePhoto({ status: 0 })).toBe(false)
  })
})
