import { type InfiniteData, keepPreviousData, useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { Client, Paginated } from '../../../shared/api/types'

/**
 * Clients des écrans terrain : « Mes clients » du régional (EF-CLI-03) et
 * fiche du client connecté (EF-CLT-06) — contrat : docs/lot-2-contrat-api.md §4.
 * Le serveur applique le périmètre (RG-S01, RG-S02) : l'écran ne filtre rien.
 */

const fieldClientsKey = ['field', 'clients'] as const

/** Page maximale de l'API : la liste d'un régional tient le plus souvent en une page. */
export const CLIENTS_PAGE_SIZE = 100

type ClientPages = InfiniteData<Paginated<Client>, number>

/** Clients du périmètre, triés par raison sociale ; pages suivantes à la demande. */
export function useMyClients(search: string, enabled = true) {
  return useInfiniteQuery({
    queryKey: [...fieldClientsKey, 'list', search],
    queryFn: ({ pageParam, signal }) =>
      api.get<Paginated<Client>>(
        '/clients',
        { 'filter[search]': search, per_page: CLIENTS_PAGE_SIZE, page: pageParam, sort: 'name' },
        signal,
      ),
    initialPageParam: 1,
    getNextPageParam: ({ meta }) => (meta.current_page < meta.last_page ? meta.current_page + 1 : undefined),
    placeholderData: keepPreviousData,
    enabled,
  })
}

/**
 * Fiche d'un client (404 hors périmètre). Ouverte depuis la liste, elle
 * s'affiche aussitôt avec les données déjà reçues, puis se met à jour :
 * utile en réseau faible (ENF-10).
 */
export function useFieldClient(id: number, enabled = true) {
  const queryClient = useQueryClient()
  return useQuery<Client>({
    queryKey: [...fieldClientsKey, 'detail', id],
    queryFn: ({ signal }) => api.get<Client>(`/clients/${id}`, undefined, signal),
    placeholderData: () => {
      for (const [, pages] of queryClient.getQueriesData<ClientPages>({ queryKey: [...fieldClientsKey, 'list'] })) {
        const found = pages?.pages.flatMap((page) => page.data).find((client) => client.id === id)
        if (found) return found
      }
      return undefined
    },
    enabled,
  })
}
