import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, NetworkError } from '../api/client'
import type { CurrentUser, Role } from '../api/types'

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
  })
}

/**
 * Utilisateur connecté, à n'utiliser que sous un garde `RequireAuth`.
 */
export function useAuthenticatedUser(): CurrentUser {
  const { data } = useCurrentUser()
  if (!data) throw new Error('useAuthenticatedUser() utilisé hors d’une route protégée')
  return data
}

export type LoginInput = { username: string; password: string; remember: boolean }

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: LoginInput) => api.post<CurrentUser>('/auth/login', input),
    onSuccess: (user) => {
      queryClient.clear()
      queryClient.setQueryData(meQueryKey, user)
    },
  })
}

function useEndSession(path: '/auth/logout' | '/auth/logout-all') {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.post<void>(path),
    onSettled: () => {
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
