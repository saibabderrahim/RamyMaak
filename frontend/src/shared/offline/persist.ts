import { createAsyncStoragePersister } from '@tanstack/query-async-storage-persister'
import type { DehydratedState, Query, QueryClient, QueryKey } from '@tanstack/react-query'
import {
  type PersistQueryClientOptions,
  type Persister,
  persistQueryClientRestore,
  persistQueryClientSubscribe,
} from '@tanstack/react-query-persist-client'
import type { CurrentUser } from '../api/types'
import { db as defaultDb, type RamyMaakDb } from './db'
import { removeUserEntries, writeSessionUser } from './outbox'

/**
 * Cache de requêtes persistant (lot 8, contrat §6.1, I-101) : le cache de
 * TanStack Query est enregistré dans IndexedDB (table `kv`), une entrée par
 * compte (`query-cache:{userId}`), pour lire hors ligne les écrans de
 * terrain. Liste blanche de requêtes réussies : jamais le back-office. Le
 * service worker, lui, ne met jamais l'API en cache.
 *
 * Le compte n'est connu qu'après GET /auth/me : le dernier compte connecté
 * est noté dans le navigateur (`ramymaak:last-user`) pour relire le bon
 * cache au démarrage. Changement de compte, déconnexion ou session expirée :
 * le cache du compte précédent est effacé.
 */

/** Version du format enregistré : la changer invalide les caches existants. */
export const PERSIST_VERSION = 'lot8-1'
/** Ancienneté maximale du cache relu (7 jours). */
export const PERSIST_MAX_AGE = 7 * 24 * 60 * 60_000
export const LAST_USER_KEY = 'ramymaak:last-user'
export const QUERY_CACHE_PREFIX = 'query-cache:'
/** Écritures groupées : au plus une par seconde. */
const THROTTLE_MS = 1000

// Clé de l'utilisateur connecté, `meQueryKey` de shared/auth/session.ts (qui importe ce module).
const ME_KEY = ['auth', 'me'] as const

/**
 * Préfixes des requêtes enregistrées (§6.1) : compte, données du client,
 * écrans du régional, centre de notifications. Rien d'autre, notamment
 * aucune clé du back-office (`adv`, `admin`, `backoffice`, `distribution/backoffice`…).
 */
export const PERSISTED_QUERY_PREFIXES: ReadonlyArray<readonly string[]> = [
  ME_KEY,
  ['client'],
  ['regional', 'dashboard'],
  ['regional', 'quotas'],
  ['regional', 'orders'],
  ['regional', 'order'],
  ['distribution', 'grid'],
  ['distribution', 'regions'],
  ['field', 'clients'],
  ['notifications'],
]

export const queryCacheKey = (userId: number) => `${QUERY_CACHE_PREFIX}${userId}`

export function isPersistedQueryKey(key: QueryKey): boolean {
  return PERSISTED_QUERY_PREFIXES.some((prefix) => prefix.length <= key.length && prefix.every((part, index) => key[index] === part))
}

/** Requête enregistrée : réussie, et sur la liste blanche. */
export function shouldPersistQuery(query: Pick<Query, 'queryKey' | 'state'>): boolean {
  return query.state.status === 'success' && isPersistedQueryKey(query.queryKey)
}

/**
 * Requêtes enregistrées gardées en mémoire aussi longtemps que le cache
 * persistant : sinon, retirées au bout de 5 minutes sans écran, elles
 * disparaîtraient aussi de l'enregistrement suivant.
 */
export function applyPersistedDefaults(queryClient: QueryClient): void {
  PERSISTED_QUERY_PREFIXES.forEach((prefix) => queryClient.setQueryDefaults([...prefix], { gcTime: PERSIST_MAX_AGE }))
}

/** Dernier compte connecté sur ce navigateur ; null : aucun, ou stockage indisponible. */
export function readLastUser(): number | null {
  try {
    const id = Number(window.localStorage.getItem(LAST_USER_KEY))
    return Number.isInteger(id) && id > 0 ? id : null
  } catch {
    return null
  }
}

function writeLastUser(userId: number | null): void {
  try {
    if (userId === null) window.localStorage.removeItem(LAST_USER_KEY)
    else window.localStorage.setItem(LAST_USER_KEY, String(userId))
  } catch {
    // Stockage indisponible : le cache ne sera pas relu au prochain démarrage.
  }
}

const sameKey = (key: QueryKey, other: readonly unknown[]) => key.length === other.length && other.every((part, index) => key[index] === part)

function currentUserId(queryClient: QueryClient): number | null {
  return queryClient.getQueryData<CurrentUser | null>(ME_KEY)?.id ?? null
}

/** Compte de l'instantané : celui de la requête GET /auth/me qu'il contient. */
function accountOf(state: DehydratedState): number | null {
  const me = state.queries.find((query) => sameKey(query.queryKey, ME_KEY))
  const user = me?.state.data as CurrentUser | null | undefined
  return user?.id ?? null
}

const ignore = () => undefined

/**
 * Persisteur IndexedDB par compte. L'instantané est préfixé par son compte
 * (« 12\n{…} ») : un instantané pris avant une déconnexion ou un changement
 * de compte, écrit après (écritures différées d'une seconde), n'atterrit
 * jamais dans le cache d'un autre compte, ni ne ressuscite celui d'un compte
 * déconnecté.
 */
export function createQueryCachePersister(queryClient: QueryClient, database: RamyMaakDb = defaultDb): Persister {
  return createAsyncStoragePersister({
    key: QUERY_CACHE_PREFIX,
    throttleTime: THROTTLE_MS,
    serialize: (client) => `${accountOf(client.clientState) ?? ''}\n${JSON.stringify(client)}`,
    storage: {
      getItem: async () => {
        const userId = readLastUser()
        return userId === null ? null : ((await database.kv.get(queryCacheKey(userId)))?.value ?? null)
      },
      setItem: async (_key, value) => {
        const cut = value.indexOf('\n')
        const userId = Number(value.slice(0, cut))
        if (!Number.isInteger(userId) || userId <= 0 || currentUserId(queryClient) !== userId) return
        await database.kv.put({ key: queryCacheKey(userId), value: value.slice(cut + 1) })
      },
      removeItem: async () => {
        const userId = readLastUser()
        if (userId !== null) await database.kv.delete(queryCacheKey(userId))
      },
    },
  })
}

export function persistOptions(queryClient: QueryClient, database: RamyMaakDb = defaultDb): PersistQueryClientOptions {
  return {
    queryClient,
    persister: createQueryCachePersister(queryClient, database),
    maxAge: PERSIST_MAX_AGE,
    buster: PERSIST_VERSION,
    dehydrateOptions: { shouldDehydrateQuery: shouldPersistQuery, shouldDehydrateMutation: () => false },
  }
}

/**
 * Suit le compte connecté (GET /auth/me) : changement de compte ou fin de
 * session (null) → cache du compte précédent effacé ; dernier compte noté
 * pour le prochain démarrage, et pour le service worker (`session-user`).
 */
export function trackAccount(queryClient: QueryClient, database: RamyMaakDb = defaultDb): () => void {
  let previous = readLastUser()
  let announced = false

  const update = (user: CurrentUser | null | undefined) => {
    // Pas encore lu (démarrage, connexion en cours) : rien à décider.
    if (user === undefined) return
    const next = user?.id ?? null
    if (next === previous && announced) return
    if (previous !== null && previous !== next) void database.kv.delete(queryCacheKey(previous)).catch(ignore)
    announced = true
    previous = next
    writeLastUser(next)
    void writeSessionUser(next, database).catch(ignore)
  }

  update(queryClient.getQueryData<CurrentUser | null>(ME_KEY))
  return queryClient.getQueryCache().subscribe((event) => {
    if ((event.type === 'added' || event.type === 'updated') && sameKey(event.query.queryKey, ME_KEY)) {
      update(event.query.state.data as CurrentUser | null | undefined)
    }
  })
}

/** Déconnexion : cache persistant et boîte d'envoi du compte effacés de l'appareil (§6.1). */
export async function purgeAccount(userId: number, database: RamyMaakDb = defaultDb): Promise<void> {
  await Promise.allSettled([database.kv.delete(queryCacheKey(userId)), removeUserEntries(userId, database)])
}

function withTimeout(promise: Promise<void>, ms: number): Promise<void> {
  let timer: ReturnType<typeof setTimeout> | undefined
  const timeout = new Promise<void>((_, reject) => {
    timer = setTimeout(() => reject(new Error('timeout')), ms)
  })
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer))
}

/**
 * Démarrage : cache du dernier compte relu (au plus `timeoutMs`, une base
 * bloquée ne retarde pas l'application), puis suivi du compte et
 * enregistrement des changements. Renvoie l'arrêt de l'enregistrement.
 */
export async function startQueryPersistence(
  queryClient: QueryClient,
  { database = defaultDb, timeoutMs = 3000 }: { database?: RamyMaakDb; timeoutMs?: number } = {},
): Promise<() => void> {
  const options = persistOptions(queryClient, database)
  try {
    await withTimeout(persistQueryClientRestore(options), timeoutMs)
  } catch {
    // Cache illisible ou trop lent : démarrage sans données enregistrées (le cache illisible est effacé).
  }
  const stopTracking = trackAccount(queryClient, database)
  const stopPersisting = persistQueryClientSubscribe(options)
  return () => {
    stopTracking()
    stopPersisting()
  }
}
