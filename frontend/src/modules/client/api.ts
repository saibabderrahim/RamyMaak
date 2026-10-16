import { useQuery } from '@tanstack/react-query'
import { api } from '../../shared/api/client'
import type { ClientToday, Order, OrderStatus, OrderSummary, Paginated } from '../../shared/api/types'
import { useAuthenticatedUser } from '../../shared/auth/session'
import { useLiveRefresh } from '../../shared/realtime'

/**
 * Données du client (lot 5) — contrat : docs/lot-5-contrat-api.md §3.
 * Clés de requête partagées par l'accueil, le catalogue, le panier et
 * « Mes commandes » : toute écriture invalide le préfixe ['client'].
 */

export const clientKey = ['client'] as const
/** Journée de commande ; sans `day`, celle que propose le serveur (I-42). */
export const todayKey = (day?: string) => ['client', 'today', day ?? 'default'] as const
export const ordersKey = (params: OrdersParams = {}) => ['client', 'orders', params] as const
export const orderKey = (id: string) => ['client', 'order', id] as const

export type OrdersParams = { day?: string; status?: OrderStatus[]; page?: number }

/** Événements du canal client.{id} qui changent ce que voit le client (§9.6). */
export const CLIENT_EVENTS = ['LimitsUpdated', 'OrderSubmitted', 'OrderUpdated', 'OrderCancelled', 'OrderStatusChanged'] as const

/**
 * Rafraîchissement en temps réel des données du client (canal client.{id}) ;
 * renvoie l'intervalle de repli (30 s) à passer en refetchInterval.
 */
export function useClientLive(): number | false {
  const user = useAuthenticatedUser()
  return useLiveRefresh(user.client_id === null ? null : `client.${user.client_id}`, CLIENT_EVENTS, [clientKey])
}

export function useClientToday(day?: string) {
  const refetchInterval = useClientLive()
  return useQuery({
    queryKey: todayKey(day),
    queryFn: ({ signal }) => api.get<ClientToday>('/client/today', day ? { day } : undefined, signal),
    refetchInterval,
  })
}

export function useClientOrders(params: OrdersParams = {}) {
  const refetchInterval = useClientLive()
  return useQuery({
    queryKey: ordersKey(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<OrderSummary>>(
        '/client/orders',
        {
          'filter[day]': params.day,
          'filter[status]': params.status?.join(','),
          page: params.page,
        },
        signal,
      ),
    refetchInterval,
  })
}

export function useClientOrder(id: string) {
  const refetchInterval = useClientLive()
  return useQuery({
    queryKey: orderKey(id),
    queryFn: ({ signal }) => api.get<Order>(`/client/orders/${encodeURIComponent(id)}`, undefined, signal),
    refetchInterval,
  })
}
