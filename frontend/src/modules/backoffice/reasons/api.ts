import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { ListResponse, ReasonCode, ReasonCodeInput, ReasonCodeUpdate, ReasonType } from '../../../shared/api/types'
import { REASONS_KEY } from '../../../shared/reasons'

/**
 * Motifs de l'Admin (EF-ADM-05) — contrat : docs/lot-6-contrat-api.md §4.
 * `GET /admin/reason-codes?filter[type]=` (désactivés compris), création et
 * modification ; le code et le type ne changent pas, rien ne se supprime.
 */

export const adminReasonsKey = ['backoffice', 'reason-codes'] as const
export const adminReasonsOfType = (type: ReasonType) => [...adminReasonsKey, type] as const

/** Motifs d'un type, actifs et désactivés, dans l'ordre du serveur (ordre, puis libellé). */
export function useAdminReasons(type: ReasonType, enabled = true) {
  return useQuery({
    enabled,
    queryKey: adminReasonsOfType(type),
    queryFn: ({ signal }) => api.get<ListResponse<ReasonCode>>('/admin/reason-codes', { 'filter[type]': type }, signal),
    select: (response) => response.data,
  })
}

/**
 * Les listes de choix des écrans terrain (`GET /reason-codes`, 10 minutes de
 * cache) et les listes de l'Admin sont relues après toute écriture.
 */
function useRefreshReasons() {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: adminReasonsKey })
    void queryClient.invalidateQueries({ queryKey: REASONS_KEY })
  }
}

export function useCreateReason() {
  const refresh = useRefreshReasons()
  return useMutation({
    mutationFn: (input: ReasonCodeInput) => api.post<ReasonCode>('/admin/reason-codes', input),
    onSuccess: refresh,
  })
}

export function useUpdateReason() {
  const refresh = useRefreshReasons()
  return useMutation({
    mutationFn: ({ id, changes }: { id: number; changes: ReasonCodeUpdate }) => api.patch<ReasonCode>(`/admin/reason-codes/${id}`, changes),
    onSuccess: refresh,
  })
}
