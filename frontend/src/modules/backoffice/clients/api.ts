import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { Client, ClientCategory, ClientStatus, ListResponse, Paginated } from '../../../shared/api/types'
import { businessToday } from '../../../shared/format'
import { postForFile, saveFile } from '../shared/download'

/** Clients, catégories et comptes clients — contrat : docs/lot-2-contrat-api.md §4. */

export const clientsKey = ['backoffice', 'clients'] as const
const categoriesKey = ['backoffice', 'client-categories'] as const

/** Lot maximal de la création de comptes en masse (EF-ADM-02). */
export const MAX_ACCOUNT_BATCH = 200

export type ClientFilters = {
  search?: string
  region_id?: string
  category_id?: string
  status?: ClientStatus | ''
  page?: number
}

export function useClients(filters: ClientFilters) {
  return useQuery({
    queryKey: [...clientsKey, 'list', filters],
    queryFn: ({ signal }) =>
      api.get<Paginated<Client>>(
        '/clients',
        {
          'filter[search]': filters.search,
          'filter[region_id]': filters.region_id,
          'filter[category_id]': filters.category_id,
          'filter[status]': filters.status,
          page: filters.page,
          per_page: 25,
          sort: 'code',
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  })
}

export function useClient(id: number) {
  return useQuery({
    queryKey: [...clientsKey, 'detail', id],
    queryFn: ({ signal }) => api.get<Client>(`/clients/${id}`, undefined, signal),
  })
}

export type ClientInput = {
  code: string
  name: string
  trade_name: string | null
  category_id: number | null
  region_id: number
  address: string | null
  wilaya: string | null
  commune: string | null
  lat: number | null
  lng: number | null
  phone: string | null
  phone2: string | null
  email: string | null
  nif: string | null
  nis: string | null
  rc: string | null
  ai: string | null
}

export function useCreateClient() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ClientInput) => api.post<Client>('/clients', input),
    onSuccess: (client) => {
      queryClient.setQueryData([...clientsKey, 'detail', client.id], client)
      void queryClient.invalidateQueries({ queryKey: [...clientsKey, 'list'] })
      void queryClient.invalidateQueries({ queryKey: categoriesKey })
      void queryClient.invalidateQueries({ queryKey: ['backoffice', 'regions'] })
    },
  })
}

/**
 * Modification partielle (code immuable). `region_id` programme un
 * changement pour la journée suivante (RG-S04) : ne l'envoyer que si
 * l'utilisateur l'a modifié — la région actuelle annule un changement
 * programmé. `status` et `status_reason` exigent client.block (EF-CLI-04).
 */
export type ClientUpdate = Partial<Omit<ClientInput, 'code'>> & {
  status?: ClientStatus
  status_reason?: string | null
}

export function useUpdateClient(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ClientUpdate) => api.patch<Client>(`/clients/${id}`, input),
    onSuccess: (client) => {
      queryClient.setQueryData([...clientsKey, 'detail', id], client)
      void queryClient.invalidateQueries({ queryKey: [...clientsKey, 'list'] })
      void queryClient.invalidateQueries({ queryKey: categoriesKey })
      void queryClient.invalidateQueries({ queryKey: ['backoffice', 'regions'] })
    },
  })
}

/* ---------- Catégories ---------- */

export function useCategories(enabled = true) {
  return useQuery({
    queryKey: categoriesKey,
    queryFn: ({ signal }) => api.get<ListResponse<ClientCategory>>('/client-categories', undefined, signal),
    select: (list) => list.data,
    staleTime: 5 * 60_000,
    enabled,
  })
}

export type CategoryInput = { code: string; name: string }

export function useCreateCategory() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CategoryInput) => api.post<ClientCategory>('/admin/client-categories', input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: categoriesKey }),
  })
}

/** Seul le libellé change : le code est la clé des fichiers Excel. */
export function useUpdateCategory(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: Pick<CategoryInput, 'name'>) => api.patch<ClientCategory>(`/admin/client-categories/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: categoriesKey })
      // Le libellé figure sur les fiches et la liste des clients.
      void queryClient.invalidateQueries({ queryKey: clientsKey })
    },
  })
}

/* ---------- Comptes clients en masse (EF-ADM-02) ---------- */

/**
 * Crée les comptes des clients donnés et télécharge le PDF des fiches
 * d'accès (mots de passe temporaires, à remettre en main propre). Les
 * clients qui ont déjà un compte ou sont inactifs sont ignorés par le
 * serveur ; aucun compte créé → 422.
 */
export function useCreateClientAccounts() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (clientIds: number[]) => {
      const file = await postForFile('/admin/client-accounts', { client_ids: clientIds })
      saveFile(file.blob, file.filename ?? `fiches-acces-${businessToday().replaceAll('-', '')}.pdf`)
      return { created: Number(file.headers.get('X-Accounts-Created') ?? clientIds.length) }
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: clientsKey })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
    },
  })
}
