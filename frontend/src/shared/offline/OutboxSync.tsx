import { type QueryClient, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import type { ClientToday } from '../api/types'
import { displayStatus, removeSent } from './outbox'
import { notifyOutboxChanged, onServiceWorkerOutboxMessage, sendPendingEntries, useOutboxEntries } from './useOutbox'

/** Nouvelle tentative tant qu'une commande attend et que l'application est ouverte (§6.3). */
export const OUTBOX_RETRY_MS = 30_000

/**
 * Une commande envoyée reste affichée jusqu'à ce que la liste des commandes
 * ait été relue après son envoi (§6.3, point 4) : toute lecture réussie de
 * « Mes commandes », ou une lecture de la journée qui la contient (accueil,
 * panier, catalogue). Les données relues du cache (`setState`) ou écrites
 * par l'application (`manual`) ne comptent pas.
 */
function forgetSeenOrders(queryClient: QueryClient, userId: number): () => void {
  return queryClient.getQueryCache().subscribe((event) => {
    if (event.type !== 'updated' || event.action.type !== 'success' || event.action.manual) return
    const { queryKey, state } = event.query
    if (queryKey[0] !== 'client') return
    const readAt = state.dataUpdatedAt
    if (queryKey[1] === 'orders') {
      void removeSent(userId, (_, sentAt) => sentAt < readAt).catch(() => 0)
    } else if (queryKey[1] === 'today') {
      const ids = new Set(((state.data as ClientToday | undefined)?.orders ?? []).map((order) => order.id))
      void removeSent(userId, (entry, sentAt) => sentAt < readAt && entry.order !== null && ids.has(entry.order.id)).catch(() => 0)
    }
  })
}

/**
 * Envoi de la boîte d'envoi d'un client (contrat §6.3, point 2) : à
 * l'ouverture de l'application, au retour du réseau, au retour au premier
 * plan, puis toutes les 30 s tant qu'une commande attend ; un envoi fait par
 * le service worker (Background Sync) relit la boîte et les données du client.
 */
export function OutboxSync({ userId }: { userId: number }) {
  const queryClient = useQueryClient()
  const entries = useOutboxEntries(userId)
  const waiting = entries?.some((entry) => displayStatus(entry) === 'pending' || entry.status === 'sending') ?? false

  useEffect(() => {
    const run = () => void sendPendingEntries(queryClient, userId)
    const onVisible = () => {
      if (document.visibilityState === 'visible') run()
    }
    run()
    window.addEventListener('online', run)
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      window.removeEventListener('online', run)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [queryClient, userId])

  useEffect(() => {
    if (!waiting) return
    const timer = window.setInterval(() => {
      if (navigator.onLine) void sendPendingEntries(queryClient, userId)
    }, OUTBOX_RETRY_MS)
    return () => window.clearInterval(timer)
  }, [waiting, queryClient, userId])

  useEffect(() => forgetSeenOrders(queryClient, userId), [queryClient, userId])

  useEffect(
    () =>
      onServiceWorkerOutboxMessage(() => {
        notifyOutboxChanged()
        void queryClient.invalidateQueries({ queryKey: ['client'] })
      }),
    [queryClient],
  )

  return null
}
