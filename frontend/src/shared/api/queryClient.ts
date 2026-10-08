import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query'
import { meQueryKey } from '../auth/session'
import { ApiError } from './client'

/**
 * Client TanStack Query (§9.5). Une session expirée (401) ou un changement
 * de mot de passe exigé (403) détecté sur n'importe quelle requête met à
 * jour l'état d'authentification ; les gardes de route redirigent alors.
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
