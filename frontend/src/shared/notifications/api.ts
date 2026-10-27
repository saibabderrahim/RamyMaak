import {
  type InfiniteData,
  type QueryClient,
  type QueryKey,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { api } from '../api/client'
import type { AppNotification, NotificationsPage } from '../api/types'
import { useAuthenticatedUser } from '../auth/session'
import { FALLBACK_REFRESH_MS, NOTIFICATION_EVENT, useChannel, useRealtimeReconnect, useRealtimeStatus } from '../realtime'

/**
 * Centre de notifications (EF-NOT-01) — contrat : docs/lot-3-contrat-api.md §6.
 * Code commun à tous les rôles, client compris : rien du back-office ici.
 */

export const notificationsKey = ['notifications'] as const
const listsKey = [...notificationsKey, 'list'] as const
export const unreadCountKey = [...notificationsKey, 'unread-count'] as const

export type NotificationFilter = 'all' | 'unread'

export const NOTIFICATIONS_PAGE_SIZE = 20

type NotificationPages = InfiniteData<NotificationsPage, number>

/** Notifications de l'utilisateur, plus récentes d'abord ; pages suivantes à la demande. */
export function useNotifications(filter: NotificationFilter, refetchInterval: number | false) {
  const queryClient = useQueryClient()
  return useInfiniteQuery({
    queryKey: [...listsKey, filter],
    queryFn: async ({ pageParam, signal }) => {
      const page = await api.get<NotificationsPage>(
        '/notifications',
        { page: pageParam, per_page: NOTIFICATIONS_PAGE_SIZE, 'filter[unread]': filter === 'unread' ? 1 : undefined },
        signal,
      )
      // La cloche suit le compteur le plus récent.
      queryClient.setQueryData(unreadCountKey, page.meta.unread_count)
      return page
    },
    initialPageParam: 1,
    getNextPageParam: ({ meta }) => (meta.current_page < meta.last_page ? meta.current_page + 1 : undefined),
    refetchInterval,
  })
}

/** Nombre de notifications non lues (`meta.unread_count` d'une page d'un élément). */
export function useUnreadNotificationsCount(refetchInterval: number | false) {
  return useQuery({
    queryKey: unreadCountKey,
    queryFn: async ({ signal }) => (await api.get<NotificationsPage>('/notifications', { per_page: 1 }, signal)).meta.unread_count,
    refetchInterval,
  })
}

type Snapshot = Array<[QueryKey, unknown]>

/** Mise à jour immédiate des listes et du compteur, avant la réponse du serveur. */
function applyRead(queryClient: QueryClient, isTarget: (notification: AppNotification) => boolean, unreadCount: (count: number) => number) {
  const readAt = new Date().toISOString()
  const markRead = (notification: AppNotification) =>
    notification.read_at === null && isTarget(notification) ? { ...notification, read_at: readAt } : notification

  queryClient.setQueriesData<NotificationPages>({ queryKey: listsKey }, (data) =>
    data === undefined
      ? data
      : {
          ...data,
          pages: data.pages.map((page) => ({
            ...page,
            data: page.data.map(markRead),
            meta: { ...page.meta, unread_count: unreadCount(page.meta.unread_count) },
          })),
        },
  )
  queryClient.setQueryData<number>(unreadCountKey, (count) => (count === undefined ? count : unreadCount(count)))
}

async function optimistic(queryClient: QueryClient, update: () => void): Promise<Snapshot> {
  await queryClient.cancelQueries({ queryKey: notificationsKey })
  const snapshot = queryClient.getQueriesData({ queryKey: notificationsKey })
  update()
  return snapshot
}

function restore(queryClient: QueryClient, snapshot: Snapshot | undefined) {
  snapshot?.forEach(([queryKey, data]) => queryClient.setQueryData(queryKey, data))
}

/** Marque une notification comme lue (appui sur la notification). */
export function useMarkNotificationRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (notification: AppNotification) =>
      api.post<AppNotification>(`/notifications/${encodeURIComponent(notification.id)}/read`),
    onMutate: (notification) =>
      optimistic(queryClient, () =>
        applyRead(
          queryClient,
          (item) => item.id === notification.id,
          (count) => (notification.read_at === null ? Math.max(0, count - 1) : count),
        ),
      ),
    onError: (_error, _notification, snapshot) => restore(queryClient, snapshot),
    onSettled: () => queryClient.invalidateQueries({ queryKey: notificationsKey }),
  })
}

/** « Tout marquer comme lu ». */
export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.post<{ updated: number }>('/notifications/read-all'),
    onMutate: () =>
      optimistic(queryClient, () =>
        applyRead(
          queryClient,
          () => true,
          () => 0,
        ),
      ),
    onError: (_error, _variables, snapshot) => restore(queryClient, snapshot),
    onSettled: () => queryClient.invalidateQueries({ queryKey: notificationsKey }),
  })
}

/**
 * Rafraîchissement de secours (§9.6) : aucun tant que la connexion temps
 * réel est établie, toutes les 30 s sinon.
 */
export function useNotificationsRefreshInterval(): number | false {
  return useRealtimeStatus() === 'connected' ? false : FALLBACK_REFRESH_MS
}

function isNotification(payload: unknown): payload is AppNotification {
  return typeof payload === 'object' && payload !== null && typeof (payload as AppNotification).title === 'string'
}

/**
 * Notifications reçues en temps réel sur `user.{id}` : relit le compteur et
 * les listes (l'API reste la source de vérité). `onReceived` reçoit la
 * notification diffusée. Renvoie l'intervalle de rafraîchissement à passer
 * aux requêtes (30 s hors connexion).
 */
export function useLiveNotifications(onReceived?: (notification: AppNotification) => void): number | false {
  const user = useAuthenticatedUser()
  const queryClient = useQueryClient()
  const status = useRealtimeStatus()
  const onReceivedRef = useRef(onReceived)
  useEffect(() => {
    onReceivedRef.current = onReceived
  })

  useChannel(`user.${user.id}`, {
    [NOTIFICATION_EVENT]: (payload) => {
      void queryClient.invalidateQueries({ queryKey: notificationsKey })
      if (isNotification(payload)) onReceivedRef.current?.(payload)
    },
  })

  // Retour de la connexion après une coupure : des notifications ont pu être manquées.
  useRealtimeReconnect(() => void queryClient.invalidateQueries({ queryKey: notificationsKey }))

  return status === 'connected' ? false : FALLBACK_REFRESH_MS
}

/** Chemin interne de la PWA (EF-NOT-03) ; une autre adresse n'est jamais suivie. Partagé avec le service worker. */
export { internalPath } from '../push/paths'
