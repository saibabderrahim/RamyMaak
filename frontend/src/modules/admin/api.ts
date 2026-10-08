import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, buildUrl } from '../../shared/api/client'
import type { AuditLog, Paginated, Role, Settings, User, UserWithTemporaryPassword } from '../../shared/api/types'

export type UserFilters = {
  search?: string
  role?: Role | ''
  is_active?: '' | '1' | '0'
  page?: number
}

const usersKey = ['admin', 'users'] as const

export function useUsers(filters: UserFilters) {
  return useQuery({
    queryKey: [...usersKey, 'list', filters],
    queryFn: ({ signal }) =>
      api.get<Paginated<User>>(
        '/admin/users',
        {
          'filter[search]': filters.search,
          'filter[role]': filters.role,
          'filter[is_active]': filters.is_active,
          page: filters.page,
          per_page: 25,
          sort: 'username',
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  })
}

export function useUser(id: number) {
  return useQuery({
    queryKey: [...usersKey, 'detail', id],
    queryFn: ({ signal }) => api.get<User>(`/admin/users/${id}`, undefined, signal),
  })
}

export type UserInput = {
  username: string
  name: string
  email: string | null
  phone: string | null
  role: Role
  client_id: number | null
}

export function useCreateUser() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: UserInput) => api.post<UserWithTemporaryPassword>('/admin/users', input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: usersKey }),
  })
}

export type UserUpdate = Partial<Omit<UserInput, 'username'>> & { is_active?: boolean; unlock?: boolean }

export function useUpdateUser(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: UserUpdate) => api.patch<User>(`/admin/users/${id}`, input),
    onSuccess: (user) => {
      queryClient.setQueryData([...usersKey, 'detail', id], user)
      void queryClient.invalidateQueries({ queryKey: [...usersKey, 'list'] })
    },
  })
}

export function useResetPassword(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.post<UserWithTemporaryPassword>(`/admin/users/${id}/reset-password`),
    onSuccess: ({ user }) => queryClient.setQueryData([...usersKey, 'detail', id], user),
  })
}

export type AuditFilters = {
  log_name?: string
  event?: string
  from?: string
  to?: string
  page?: number
}

function auditQuery(filters: AuditFilters) {
  return {
    'filter[log_name]': filters.log_name,
    'filter[event]': filters.event,
    'filter[from]': filters.from,
    'filter[to]': filters.to,
  }
}

export function useAuditLogs(filters: AuditFilters) {
  return useQuery({
    queryKey: ['admin', 'audit-logs', filters],
    queryFn: ({ signal }) =>
      api.get<Paginated<AuditLog>>('/admin/audit-logs', { ...auditQuery(filters), page: filters.page, per_page: 50 }, signal),
    placeholderData: keepPreviousData,
  })
}

export function auditExportUrl(filters: AuditFilters): string {
  return buildUrl('/admin/audit-logs', { ...auditQuery(filters), format: 'csv' })
}

const settingsKey = ['admin', 'settings'] as const

export function useSettings() {
  return useQuery({
    queryKey: settingsKey,
    queryFn: ({ signal }) => api.get<Settings>('/admin/settings', undefined, signal),
  })
}

export function useUpdateSettings() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: Settings) => api.put<Settings>('/admin/settings', input),
    onSuccess: (settings) => queryClient.setQueryData(settingsKey, settings),
  })
}
