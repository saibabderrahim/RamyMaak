import { type QueryClient, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { ClientToday, Order } from '../../../shared/api/types'
import { clientKey, orderKey, todayKey } from '../api'

// Écritures de « Mes commandes » (EF-CLT-04) — contrat : docs/lot-5-contrat-api.md §3.

/**
 * Annulation par le client (RG-C05, I-52) : `POST /client/orders/{id}/cancel`
 * avec la version lue. La commande renvoyée remplace celle du cache ; toutes
 * les données du client sont relues (accueil, catalogue : quantités
 * libérées). `networkMode: 'always'` : hors ligne, la requête échoue aussitôt
 * au lieu d'attendre le retour du réseau.
 */
export function useCancelOrder(id: string) {
  const queryClient = useQueryClient()
  return useMutation({
    networkMode: 'always',
    mutationFn: (version: number) => api.post<Order>(`/client/orders/${encodeURIComponent(id)}/cancel`, { version }),
    onSuccess: (order) => {
      queryClient.setQueryData(orderKey(id), order)
      void queryClient.invalidateQueries({ queryKey: clientKey })
    },
  })
}

/**
 * Journée proposée (I-42), relue sur le serveur (état, droits, disponible du
 * jour) : « Recommander » ne part jamais de données périmées. Même clé et
 * même requête que `useClientToday()`.
 */
export function fetchProposedDay(queryClient: QueryClient): Promise<ClientToday> {
  return queryClient.fetchQuery({
    queryKey: todayKey(),
    queryFn: ({ signal }) => api.get<ClientToday>('/client/today', undefined, signal),
    staleTime: 0,
  })
}
