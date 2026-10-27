import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query'
import { meQueryKey } from '../auth/session'
import { applyPersistedDefaults } from '../offline/persist'
import { ApiError } from './client'

/**
 * Client TanStack Query (§9.5). Une session expirée (401) ou un changement
 * de mot de passe exigé (403) détecté sur n'importe quelle requête met à
 * jour l'état d'authentification ; les gardes de route redirigent alors.
 *
 * Hors ligne (lot 8, §6.1) : les requêtes attendent le réseau (`networkMode`
 * « online » par défaut) en montrant les données en mémoire, relues du cache
 * persistant au démarrage (shared/offline/persist.ts) ; les requêtes
 * enregistrées y restent aussi longtemps que ce cache (`gcTime`).
 */
export function createQueryClient(): QueryClient {
  const client: QueryClient = new QueryClient({
    queryCache: new QueryCache({ onError: (error, query) => handleAuthError(error, query.queryKey) }),
    mutationCache: new MutationCache({ onError: (error) => handleAuthError(error) }),
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        retry: (failureCount, error) => !(error instanceof ApiError) && failureCount < 2,
        refetchOnWindowFocus: true,
      },
    },
  })
  applyPersistedDefaults(client)

  function handleAuthError(error: unknown, queryKey?: readonly unknown[]): void {
    if (!(error instanceof ApiError)) return
    const isMeQuery = queryKey !== undefined && queryKey.join('/') === meQueryKey.join('/')

    if (error.status === 401 && !isMeQuery && client.getQueryData(meQueryKey)) {
      sessionExpired = true
      client.setQueryData(meQueryKey, null)
    } else if (error.code === 'PASSWORD_CHANGE_REQUIRED') {
      void client.invalidateQueries({ queryKey: meQueryKey })
    }
  }

  return client
}

let sessionExpired = false

/** Indique (une seule fois) que la dernière déconnexion vient d'une expiration. */
export function consumeSessionExpired(): boolean {
  const value = sessionExpired
  sessionExpired = false
  return value
}
