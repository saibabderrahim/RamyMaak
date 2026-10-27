import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../shared/api/client'
import type { CurrentUser, DeviceSession, ListResponse, PreferencesInput } from '../../shared/api/types'
import { meQueryKey } from '../../shared/auth/session'

/** Profil : préférences et appareils connectés (lot 8, contrat §4 et §5). */

export const sessionsKey = ['auth', 'sessions'] as const

/** Préférences du compte (rappels N-10 à N-12) : la réponse est l'utilisateur courant. */
export function useUpdatePreferences() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: PreferencesInput) => api.patch<CurrentUser>('/auth/me/preferences', input),
    onSuccess: (user) => queryClient.setQueryData(meQueryKey, user),
  })
}

/** Sessions du compte, de la plus récente à la plus ancienne. */
export function useDeviceSessions() {
  return useQuery({
    queryKey: sessionsKey,
    queryFn: async ({ signal }) => (await api.get<ListResponse<DeviceSession>>('/auth/sessions', undefined, signal)).data,
  })
}

/**
 * Déconnexion d'un autre appareil : supprime la session et ses abonnements
 * push. Un appareil déjà déconnecté (404) l'est : la liste est relue.
 */
export function useRevokeSession() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (session: DeviceSession) => {
      try {
        await api.delete<void>(`/auth/sessions/${encodeURIComponent(session.id)}`)
      } catch (error) {
        if (!(error instanceof ApiError && error.status === 404)) throw error
      }
      return session
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: sessionsKey }),
  })
}
