import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../../shared/api/client'
import type { AdvCancelBody, AdvOrder, AdvOrdersPage, BulkStatusBody, BulkStatusResult, Paginated, Product, ShippingBody } from '../../../shared/api/types'
import { ADV_TO_PREPARE_KEY } from '../../../shared/orders'
import { ORDER_CANCELLED_BY_ADV, ORDER_SHIPPING_UPDATED, ORDER_VALIDATED, ORDERS_PROCESSED } from '../../../shared/realtime'
import { monitoringRoot } from '../monitoring/keys'
import { fetchFile, saveFile } from '../shared/download'
import { PAGE_SIZE, type QueueParams, tabById } from './model'

/** File des commandes validées et suivantes de l'ADV — contrat : docs/lot-6-contrat-api.md §5. */

export const ordersKey = ['backoffice', 'orders'] as const
/** Les files (listes de commandes), sans les détails : ce qu'un événement de la journée invalide. */
export const ordersListsKey = [...ordersKey, 'list'] as const
const listKey = (params: QueueParams, search: string) => [...ordersListsKey, params, search] as const
export const orderKey = (id: string) => [...ordersKey, 'detail', id] as const

/** Matrice des quotas et grille de répartition : V(r,p) change avec la validation et l'annulation. */
const quotasRoot = ['backoffice', 'quotas'] as const

/**
 * Événements de `adv.{jour}` qui changent la file et le détail (contrat §7) :
 * validation par le régional, statuts en masse, expédition, annulation par l'ADV, clôture.
 */
export const QUEUE_EVENTS = [ORDER_VALIDATED, ORDERS_PROCESSED, ORDER_SHIPPING_UPDATED, ORDER_CANCELLED_BY_ADV, 'DayClosed'] as const

/** Une commande bien formée est un ULID de 26 caractères (public_id) ; sinon inutile d'interroger le serveur. */
export function isOrderId(value: string | undefined): value is string {
  return value !== undefined && /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(value)
}

/**
 * Page de la file pour l'onglet courant. Pendant le chargement d'un autre
 * filtre ou d'une autre page du même onglet, la page précédente reste affichée ;
 * pendant celui d'un autre onglet, seuls les compteurs (indépendants de l'onglet)
 * restent affichés, pas les commandes d'un autre statut.
 */
export function useAdvOrders(params: QueueParams, search: string, refetchInterval: number | false) {
  const term = search.trim()
  return useQuery({
    queryKey: listKey(params, term),
    queryFn: ({ signal }) =>
      api.get<AdvOrdersPage>(
        '/adv/orders',
        {
          'filter[status]': tabById(params.tab).status,
          'filter[day]': params.day,
          'filter[region]': params.region,
          'filter[depot]': params.depot,
          'filter[product]': params.product,
          'filter[search]': term,
          sort: params.sort,
          page: params.page,
          per_page: PAGE_SIZE,
        },
        signal,
      ),
    placeholderData: (previous, previousQuery) =>
      previous && (previousQuery?.queryKey[2] as QueueParams | undefined)?.tab === params.tab ? previous : previous && { ...previous, data: [] },
    refetchInterval,
  })
}

/** Produits proposés au filtre « Produit » : dix au plus, dès deux caractères saisis. */
export function useProductChoices(term: string) {
  return useQuery({
    queryKey: [...ordersKey, 'product-choices', term],
    queryFn: ({ signal }) => api.get<Paginated<Product>>('/products', { 'filter[search]': term, per_page: 10 }, signal),
    select: (page) => page.data,
    enabled: term.length >= 2,
    staleTime: 60_000,
  })
}

export function useAdvOrder(id: string | undefined, refetchInterval: number | false) {
  const valid = isOrderId(id)
  return useQuery({
    queryKey: orderKey(id ?? ''),
    queryFn: ({ signal }) => api.get<AdvOrder>(`/adv/orders/${id}`, undefined, signal),
    enabled: valid,
    refetchInterval,
  })
}

/** Relit les files, le compteur du menu, le suivi et la matrice : tout ce qui montre les commandes ou V (le détail se tient lui-même). */
export function useRefreshOrders() {
  const queryClient = useQueryClient()
  return () => {
    for (const queryKey of [ordersListsKey, ADV_TO_PREPARE_KEY, monitoringRoot, quotasRoot]) {
      void queryClient.invalidateQueries({ queryKey })
    }
  }
}

/** Changement de statut en masse (RG-A02) : résultat partiel, un refus n'annule pas les autres (I-78). */
export function useBulkStatus() {
  const refresh = useRefreshOrders()
  return useMutation({
    mutationFn: (body: BulkStatusBody) => api.post<BulkStatusResult>('/adv/orders/bulk-status', body),
    onSettled: refresh,
  })
}

/** Expédition (RG-A03) : le détail à jour remplace celui du cache. Un refus (409) relit la commande. */
export function useUpdateShipping(id: string) {
  const queryClient = useQueryClient()
  const refresh = useRefreshOrders()
  return useMutation({
    mutationFn: (body: ShippingBody) => api.patch<AdvOrder>(`/adv/orders/${id}/shipping`, body),
    onSuccess: (order) => {
      queryClient.setQueryData(orderKey(id), order)
      refresh()
    },
    onError: () => queryClient.invalidateQueries({ queryKey: orderKey(id) }),
  })
}

/** Annulation exceptionnelle (RG-V07) : V est restitué, même journée clôturée. */
export function useCancelOrder(id: string) {
  const queryClient = useQueryClient()
  const refresh = useRefreshOrders()
  return useMutation({
    mutationFn: (body: AdvCancelBody) => api.post<AdvOrder>(`/adv/orders/${id}/cancel`, body),
    onSuccess: (order) => {
      queryClient.setQueryData(orderKey(id), order)
      refresh()
    },
    onError: () => queryClient.invalidateQueries({ queryKey: orderKey(id) }),
  })
}

/** Bon de commande PDF (RG-A04, I-86) : téléchargement authentifié, puis enregistrement du fichier ; un refus (4xx) est une erreur, jamais un fichier. */
export function useOrderPdf(order: { id: string; number: string }) {
  return useMutation({
    mutationFn: () => fetchFile(`/adv/orders/${order.id}/pdf`),
    onSuccess: (file) => saveFile(file.blob, file.filename ?? `bon-de-commande-${order.number}.pdf`),
  })
}
