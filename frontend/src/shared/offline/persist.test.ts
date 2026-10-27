import { QueryClient } from '@tanstack/react-query'
import { persistQueryClientSave } from '@tanstack/react-query-persist-client'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { makeUser } from '../../test/utils'
import { createQueryClient } from '../api/queryClient'
import { meQueryKey } from '../auth/session'
import { limitDraftsPrefix } from '../storage'
import { db } from './db'
import { enqueue, listEntries, readSessionUser, SESSION_USER_KEY } from './outbox'
import {
  isPersistedQueryKey,
  LAST_USER_KEY,
  PERSIST_MAX_AGE,
  PERSIST_VERSION,
  PERSISTED_QUERY_PREFIXES,
  persistOptions,
  purgeAccount,
  queryCacheKey,
  readLastUser,
  shouldPersistQuery,
  startQueryPersistence,
  trackAccount,
} from './persist'

// Cache de requêtes persistant (lot 8, contrat §6.1, I-101).

const client = makeUser('client', { id: 9, client_id: 12 })
const other = makeUser('client', { id: 10, client_id: 13, name: 'Autre gérant' })

type Stored = { buster: string; timestamp: number; clientState: { queries: Array<{ queryKey: unknown[]; state: { data: unknown } }> } }

async function stored(userId: number): Promise<Stored | null> {
  const record = await db.kv.get(queryCacheKey(userId))
  return record ? (JSON.parse(record.value) as Stored) : null
}

const storedKeys = async (userId: number) => (await stored(userId))?.clientState.queries.map((query) => query.queryKey) ?? []

/** Enregistrement de l'état du client, écriture différée (1 s) comprise. */
async function save(queryClient: QueryClient) {
  await persistQueryClientSave(persistOptions(queryClient))
  await vi.advanceTimersByTimeAsync(1100)
}

afterEach(() => {
  vi.useRealTimers()
})

describe('persisted query cache: allowlist (§6.1)', () => {
  it.each([
    [['auth', 'me']],
    [['client', 'today', 'default']],
    [['client', 'orders', { page: 1 }]],
    [['client', 'order', 'X']],
    [['regional', 'dashboard', '2026-10-08']],
    [['regional', 'quotas', '2026-10-08']],
    [['regional', 'orders', '2026-10-08', 'all', 'toValidate']],
    [['regional', 'order', 'X']],
    [['distribution', 'grid', '2026-10-08', 3]],
    [['distribution', 'regions', '2026-10-08']],
    [['field', 'clients', 'list', '']],
    [['notifications', 'list', 'all']],
  ])('keeps the field query %j', (key) => {
    expect(isPersistedQueryKey(key)).toBe(true)
  })

  it.each([
    [['adv', 'dashboard']],
    [['admin', 'users', 'assignable', 'regional']],
    [['backoffice', 'orders', 'list']],
    [['backoffice', 'quotas', 'matrix', '2026-10-08']],
    [['distribution', 'backoffice', '2026-10-08', 3]],
    [['regional', 'clients']],
    [['reasons', 'ADJUSTMENT']],
    [['auth', 'sessions']],
    [['auth']],
  ])('never keeps %j (back-office or outside the list)', (key) => {
    expect(isPersistedQueryKey(key)).toBe(false)
  })

  it('keeps successful queries only, and uses the key of the signed-in user', () => {
    expect(PERSISTED_QUERY_PREFIXES[0]).toEqual(meQueryKey)
    const query = (status: 'success' | 'error' | 'pending') => ({ queryKey: ['client', 'today'], state: { status } }) as unknown as Parameters<typeof shouldPersistQuery>[0]
    expect(shouldPersistQuery(query('success'))).toBe(true)
    expect(shouldPersistQuery(query('error'))).toBe(false)
    expect(shouldPersistQuery(query('pending'))).toBe(false)
  })

  it('writes the allowed queries of the account under its own key, never the back-office ones', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
    const queryClient = createQueryClient()
    queryClient.setQueryData(meQueryKey, client)
    queryClient.setQueryData(['client', 'today', 'default'], { day: '2026-10-08' })
    queryClient.setQueryData(['adv', 'dashboard'], { secret: true })
    queryClient.setQueryData(['backoffice', 'orders', 'list'], { secret: true })
    queryClient.setQueryData(['distribution', 'backoffice', '2026-10-08', 3], { secret: true })

    await save(queryClient)

    expect(await storedKeys(9)).toEqual(expect.arrayContaining([['auth', 'me'], ['client', 'today', 'default']]))
    expect(await storedKeys(9)).toHaveLength(2)
    expect((await stored(9))?.buster).toBe(PERSIST_VERSION)
    expect(await db.kv.toCollection().primaryKeys()).toEqual([queryCacheKey(9)])
  })

  it('keeps the persisted queries in memory as long as the persisted cache', () => {
    const queryClient = createQueryClient()
    expect(queryClient.getQueryDefaults(['client', 'today']).gcTime).toBe(PERSIST_MAX_AGE)
    expect(queryClient.getQueryDefaults(['backoffice', 'orders']).gcTime).toBeUndefined()
  })

  it('writes nothing without a signed-in account, nor a snapshot taken for another account', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
    const queryClient = createQueryClient()
    queryClient.setQueryData(meQueryKey, null)
    queryClient.setQueryData(['client', 'today', 'default'], { day: '2026-10-08' })
    await save(queryClient)
    expect(await db.kv.count()).toBe(0)

    // Instantané du compte 9 écrit après le passage au compte 10 (écriture différée) : ignoré.
    queryClient.setQueryData(meQueryKey, client)
    const options = persistOptions(queryClient)
    const pending = persistQueryClientSave(options)
    queryClient.setQueryData(meQueryKey, other)
    await pending
    await vi.advanceTimersByTimeAsync(1100)
    expect(await stored(9)).toBeNull()
  })
})

describe('persisted query cache: accounts', () => {
  it('drops the cache of the previous account when another account signs in, and remembers the new one', async () => {
    await db.kv.put({ key: queryCacheKey(9), value: '{}' })
    localStorage.setItem(LAST_USER_KEY, '9')
    const queryClient = createQueryClient()
    queryClient.setQueryData(meQueryKey, client)
    const stop = trackAccount(queryClient)

    expect(readLastUser()).toBe(9)
    await vi.waitFor(async () => expect(await readSessionUser()).toBe(9))
    expect(await db.kv.get(queryCacheKey(9))).toBeDefined()

    // Connexion d'un autre compte (useLogin : cache vidé, nouvel utilisateur).
    queryClient.clear()
    queryClient.setQueryData(meQueryKey, other)
    await vi.waitFor(async () => expect(await db.kv.get(queryCacheKey(9))).toBeUndefined())
    expect(readLastUser()).toBe(10)
    expect(await readSessionUser()).toBe(10)
    stop()
  })

  it('drops the cache when the session ends (logout or expiry), and forgets the account', async () => {
    await db.kv.put({ key: queryCacheKey(9), value: '{}' })
    localStorage.setItem(LAST_USER_KEY, '9')
    const queryClient = createQueryClient()
    queryClient.setQueryData(meQueryKey, client)
    const stop = trackAccount(queryClient)

    queryClient.setQueryData(meQueryKey, null)
    await vi.waitFor(async () => expect(await db.kv.get(queryCacheKey(9))).toBeUndefined())
    expect(localStorage.getItem(LAST_USER_KEY)).toBeNull()
    expect(await db.kv.get(SESSION_USER_KEY)).toBeUndefined()
    stop()
  })

  it('purges the cache and the outbox of the account at logout, not those of another account', async () => {
    await db.kv.bulkPut([
      { key: queryCacheKey(9), value: '{}' },
      { key: queryCacheKey(10), value: '{}' },
    ])
    const body = (id: string) => ({ client_request_id: id, day: '2026-10-08', comment: null, lines: [{ product_id: 42, quantity: 1 }] })
    await enqueue({ user_id: 9, client_id: 12, body: body('a') })
    await enqueue({ user_id: 10, client_id: 13, body: body('b') })

    await purgeAccount(9)
    expect(await db.kv.get(queryCacheKey(9))).toBeUndefined()
    expect(await db.kv.get(queryCacheKey(10))).toBeDefined()
    expect(await listEntries(9)).toEqual([])
    expect(await listEntries(10)).toHaveLength(1)
  })

  it('names the limit drafts of an account like the distribution grid does (draftStorageKey)', () => {
    expect(limitDraftsPrefix(9)).toBe('ramymaak:limit-drafts:9:')
  })
})

describe('persisted query cache: start-up', () => {
  async function seed(timestamp = Date.now(), buster = PERSIST_VERSION) {
    const source = createQueryClient()
    source.setQueryData(meQueryKey, client)
    source.setQueryData(['client', 'today', 'default'], { day: '2026-10-08' })
    const { dehydrate } = await import('@tanstack/react-query')
    await db.kv.put({ key: queryCacheKey(9), value: JSON.stringify({ buster, timestamp, clientState: dehydrate(source) }) })
    localStorage.setItem(LAST_USER_KEY, '9')
  }

  it('restores the cache of the last account at start-up', async () => {
    await seed()
    const queryClient = createQueryClient()
    const stop = await startQueryPersistence(queryClient)
    expect(queryClient.getQueryData(meQueryKey)).toEqual(client)
    expect(queryClient.getQueryData(['client', 'today', 'default'])).toEqual({ day: '2026-10-08' })
    stop()
  })

  it.each([
    ['older than 7 days', () => seed(Date.now() - PERSIST_MAX_AGE - 60_000)],
    ['of an older format', () => seed(Date.now(), 'lot7')],
  ])('ignores and removes a cache %s', async (_, prepare) => {
    await prepare()
    const queryClient = createQueryClient()
    const stop = await startQueryPersistence(queryClient)
    expect(queryClient.getQueryData(meQueryKey)).toBeUndefined()
    await vi.waitFor(async () => expect(await db.kv.get(queryCacheKey(9))).toBeUndefined())
    stop()
  })

  it('starts without cache when no account signed in on this browser', async () => {
    await seed()
    localStorage.removeItem(LAST_USER_KEY)
    const queryClient = createQueryClient()
    const stop = await startQueryPersistence(queryClient)
    expect(queryClient.getQueryData(meQueryKey)).toBeUndefined()
    stop()
  })
})
