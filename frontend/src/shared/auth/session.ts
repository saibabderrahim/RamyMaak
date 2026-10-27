import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef } from 'react'
import { api, ApiError, NetworkError } from '../api/client'
import type { CurrentUser, Role } from '../api/types'
import { purgeAccount } from '../offline/persist'
import { disconnectRealtime } from '../realtime/connection'
import { purgeSessionStorage } from '../storage'

export const meQueryKey = ['auth', 'me'] as const

/**
 * Utilisateur connecté, ou `null` sans session valide.
 */
export function useCurrentUser() {
  return useQuery({
    queryKey: meQueryKey,
    queryFn: async (): Promise<CurrentUser | null> => {
      try {
        return await api.get<CurrentUser>('/auth/me')
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) return null
        throw error
      }
    },
    staleTime: 5 * 60_000,
    retry: (failureCount, error) => error instanceof NetworkError && failureCount < 2,
    // Retour du réseau après un démarrage hors ligne (cache de l'appareil, lot 8) : la session est vérifiée.
    refetchOnReconnect: 'always',
  })
}

/**
 * Utilisateur connecté, à n'utiliser que sous un garde `RequireAuth`.
 *
 * Fin de session (déconnexion, expiration) : un composant de la coquille peut
 * se redessiner de lui-même (lecture suivie de la boîte d'envoi, lot 8) avant
 * le garde, qui le retire au rendu suivant ; il garde d'ici là le dernier
 * utilisateur connecté au lieu de faire tomber la page.
 */
export function useAuthenticatedUser(): CurrentUser {
  const { data } = useCurrentUser()
  const last = useRef<CurrentUser | null>(null)
  if (data) last.current = data
  const user = data ?? last.current
  if (!user) throw new Error('useAuthenticatedUser() utilisé hors d’une route protégée')
  return user
}

export type LoginInput = { username: string; password: string; remember: boolean }

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: LoginInput) => api.post<CurrentUser>('/auth/login', input),
    onSuccess: (user) => {
      disconnectRealtime()
      queryClient.clear()
      queryClient.setQueryData(meQueryKey, user)
    },
  })
}

/**
 * Déconnexion de l'appareil ou de tous les appareils : en cas d'échec aussi
 * (hors ligne), les données du compte quittent l'appareil — paniers,
 * brouillons de bornes, cache persistant et boîte d'envoi (lot 8, §6.1).
 */
function useEndSession(path: '/auth/logout' | '/auth/logout-all') {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.post<void>(path),
    onSettled: () => {
      const userId = queryClient.getQueryData<CurrentUser | null>(meQueryKey)?.id
      disconnectRealtime()
      purgeSessionStorage(userId)
      if (userId !== undefined) void purgeAccount(userId)
      queryClient.clear()
      queryClient.setQueryData(meQueryKey, null)
    },
  })
}

export function useLogout() {
  return useEndSession('/auth/logout')
}

export function useLogoutAll() {
  return useEndSession('/auth/logout-all')
}

export type ChangePasswordInput = {
  current_password: string
  password: string
  password_confirmation: string
}

export function useChangePassword() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ChangePasswordInput) => api.put<CurrentUser>('/auth/password', input),
    onSuccess: (user) => queryClient.setQueryData(meQueryKey, user),
  })
}

const HOME_PATHS: Record<Role, string> = {
  admin: '/admin',
  adv: '/adv',
  regional: '/regional',
  client: '/client',
}

export function homePath(role: Role): string {
  return HOME_PATHS[role]
}
