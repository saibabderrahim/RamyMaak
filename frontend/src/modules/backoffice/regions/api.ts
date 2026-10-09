import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, request } from '../../../shared/api/client'
import type { Assignment, AssignmentKind, Depot, ListResponse, Paginated, Region, User } from '../../../shared/api/types'

/** Régions, dépôts et affectations — contrat : docs/lot-2-contrat-api.md §3. */

export const regionsKey = ['backoffice', 'regions'] as const
const depotsKey = ['backoffice', 'depots'] as const

export type RegionFilters = {
  search?: string
  is_active?: '' | '1' | '0'
  page?: number
}

export function useRegions(filters: RegionFilters) {
  return useQuery({
    queryKey: [...regionsKey, 'list', filters],
    queryFn: ({ signal }) =>
      api.get<Paginated<Region>>(
        '/regions',
        {
          'filter[search]': filters.search,
          'filter[is_active]': filters.is_active,
          page: filters.page,
          per_page: 25,
          sort: 'code',
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  })
}

/**
 * Régions du périmètre pour les listes de choix (filtres, fiche client) :
 * une seule page de 100, triée par nom (inactives comprises pour l'Admin).
 */
export function useRegionOptions(enabled = true) {
  return useQuery({
    queryKey: [...regionsKey, 'options'],
    queryFn: ({ signal }) => api.get<Paginated<Region>>('/regions', { per_page: 100, sort: 'name' }, signal),
    select: (page) => page.data,
    staleTime: 5 * 60_000,
    enabled,
  })
}

export function useRegion(id: number) {
  return useQuery({
    queryKey: [...regionsKey, 'detail', id],
    queryFn: ({ signal }) => api.get<Region>(`/regions/${id}`, undefined, signal),
  })
}

export type RegionInput = {
  code: string
  name: string
  depot_id: number | null
  is_active: boolean
}

export function useCreateRegion() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: RegionInput) => api.post<Region>('/admin/regions', input),
    onSuccess: (region) => {
      queryClient.setQueryData([...regionsKey, 'detail', region.id], region)
      void queryClient.invalidateQueries({ queryKey: regionsKey })
      void queryClient.invalidateQueries({ queryKey: depotsKey })
    },
  })
}

/** Le code ne change pas (clé des fichiers Excel) : il n'est jamais envoyé. */
export type RegionUpdate = Partial<Omit<RegionInput, 'code'>>

export function useUpdateRegion(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: RegionUpdate) => api.patch<Region>(`/admin/regions/${id}`, input),
    onSuccess: (region) => {
      queryClient.setQueryData([...regionsKey, 'detail', id], region)
      void queryClient.invalidateQueries({ queryKey: [...regionsKey, 'list'] })
      void queryClient.invalidateQueries({ queryKey: [...regionsKey, 'options'] })
      void queryClient.invalidateQueries({ queryKey: depotsKey })
    },
  })
}

/* ---------- Dépôts (réservés à region.manage) ---------- */

export function useDepots(enabled = true) {
  return useQuery({
    queryKey: depotsKey,
    queryFn: ({ signal }) => api.get<ListResponse<Depot>>('/admin/depots', undefined, signal),
    select: (list) => list.data,
    enabled,
  })
}

export type DepotInput = {
  code: string
  name: string
  address: string | null
  is_active: boolean
}

export function useCreateDepot() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: DepotInput) => api.post<Depot>('/admin/depots', input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: depotsKey }),
  })
}

export function useUpdateDepot(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: Partial<Omit<DepotInput, 'code'>>) => api.patch<Depot>(`/admin/depots/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: depotsKey })
      // Le nom du dépôt figure sur les régions qu'il dessert.
      void queryClient.invalidateQueries({ queryKey: regionsKey })
    },
  })
}

/* ---------- Affectations (§3.3, §3.4) ---------- */

const assignmentsKey = (regionId: number) => [...regionsKey, 'detail', regionId, 'assignments'] as const

/** Historique complet, plus récentes d'abord (lisible avec region.view). */
export function useAssignments(regionId: number) {
  return useQuery({
    queryKey: assignmentsKey(regionId),
    queryFn: ({ signal }) => api.get<ListResponse<Assignment>>(`/admin/regions/${regionId}/assignments`, undefined, signal),
    select: (list) => list.data,
  })
}

export type AssignmentInput = {
  user_id: number
  kind: AssignmentKind
  starts_on: string
  ends_on: string | null
  /** Révoque le ou les titulaires en place sur la période (H-02). */
  replace?: boolean
}

function useRefreshRegion(regionId: number) {
  const queryClient = useQueryClient()
  return () => {
    // Fiche (titulaire, suppléants), historique et listes (colonne titulaire).
    void queryClient.invalidateQueries({ queryKey: [...regionsKey, 'detail', regionId] })
    void queryClient.invalidateQueries({ queryKey: [...regionsKey, 'list'] })
  }
}

/**
 * Rafraîchie même en cas de refus : un titulaire installé entre-temps
 * apparaît dans l'historique, et la soumission suivante propose alors son
 * remplacement.
 */
export function useCreateAssignment(regionId: number) {
  const refresh = useRefreshRegion(regionId)
  return useMutation({
    mutationFn: (input: AssignmentInput) => api.post<Assignment>(`/admin/regions/${regionId}/assignments`, input),
    onSettled: refresh,
  })
}

/**
 * Fin d'affectation immédiate (révocation datée, RG-S03). Rafraîchie même
 * en cas d'échec : une affectation déjà terminée ailleurs (409) disparaît
 * ainsi des affectations en cours.
 */
export function useEndAssignment(regionId: number) {
  const refresh = useRefreshRegion(regionId)
  return useMutation({
    mutationFn: (assignmentId: number) => request<Assignment>(`/admin/assignments/${assignmentId}`, { method: 'DELETE' }),
    onSettled: refresh,
  })
}

/**
 * Comptes actifs du rôle qu'exige la nature de l'affectation : régional
 * pour titulaire et suppléant, ADV pour ADV.
 */
export function useAssignableUsers(role: 'regional' | 'adv', enabled = true) {
  return useQuery({
    queryKey: ['admin', 'users', 'assignable', role],
    queryFn: ({ signal }) =>
      api.get<Paginated<User>>(
        '/admin/users',
        { 'filter[role]': role, 'filter[is_active]': 1, per_page: 100, sort: 'name' },
        signal,
      ),
    select: (page) => page.data,
    enabled,
  })
}
