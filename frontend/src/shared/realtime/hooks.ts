import { useQueryClient, type QueryKey } from '@tanstack/react-query'
import { useEffect, useRef, useSyncExternalStore } from 'react'
import { useAuthenticatedUser } from '../auth/session'
import {
  connectRealtime,
  FALLBACK_REFRESH_MS,
  getRealtimeStatus,
  subscribeRealtimeStatus,
  type RealtimeStatus,
} from './connection'

/** Nom diffusé des notifications Laravel (canal `user.{id}`). */
export const NOTIFICATION_EVENT = 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated'

export function useRealtimeStatus(): RealtimeStatus {
  return useSyncExternalStore(subscribeRealtimeStatus, getRealtimeStatus, getRealtimeStatus)
}

type Handlers = Record<string, (payload: unknown) => void>

// Abonnés par canal : on ne quitte un canal qu'au départ de son dernier abonné.
const subscribers = new Map<string, number>()

/**
 * Écoute des événements d'un canal privé (`region-day.3.2026-10-08`,
 * `adv.2026-10-08`, `user.4`) tant que le composant est affiché. Les clés
 * de `handlers` sont les noms diffusés (`QuotaPublished`, `DraftsUpdated`,
 * `DayClosed`, NOTIFICATION_EVENT). `channel` null : aucun abonnement.
 */
export function useChannel(channel: string | null, handlers: Handlers): void {
  const user = useAuthenticatedUser()
  const key = user.realtime?.key ?? null
  const handlersRef = useRef(handlers)
  useEffect(() => {
    handlersRef.current = handlers
  })
  const events = Object.keys(handlers).sort().join('|')

  useEffect(() => {
    if (channel === null || key === null || events === '') return
    let cancelled = false
    let release: (() => void) | undefined

    void connectRealtime(user.id, key).then((echo) => {
      if (cancelled || echo === null) return
      const subscription = echo.private(channel)
      subscribers.set(channel, (subscribers.get(channel) ?? 0) + 1)
      const listeners = events.split('|').map((event) => {
        const listener = (payload: unknown) => handlersRef.current[event]?.(payload)
        subscription.listen(`.${event}`, listener)
        return { event, listener }
      })
      release = () => {
        listeners.forEach(({ event, listener }) => subscription.stopListening(`.${event}`, listener))
        const remaining = (subscribers.get(channel) ?? 1) - 1
        if (remaining > 0) {
          subscribers.set(channel, remaining)
        } else {
          subscribers.delete(channel)
          echo.leave(channel)
        }
      }
    })

    return () => {
      cancelled = true
      release?.()
    }
  }, [channel, key, user.id, events])
}

/**
 * Données tenues à jour en temps réel : les événements `events` du canal
 * invalident les requêtes `queryKeys` (l'API reste la source de vérité,
 * §9.6). Renvoie l'intervalle de rafraîchissement à passer à `useQuery`
 * (`refetchInterval`) : aucun quand la connexion est établie, 30 s sinon.
 */
export function useLiveRefresh(channel: string | null, events: readonly string[], queryKeys: readonly QueryKey[]): number | false {
  const queryClient = useQueryClient()
  const status = useRealtimeStatus()
  const keysRef = useRef(queryKeys)
  useEffect(() => {
    keysRef.current = queryKeys
  })

  const refresh = () => keysRef.current.forEach((queryKey) => void queryClient.invalidateQueries({ queryKey }))
  useChannel(channel, Object.fromEntries(events.map((event) => [event, refresh])))

  // Retour de la connexion après une coupure : des événements ont pu être manqués.
  const previous = useRef(status)
  useEffect(() => {
    if (status === 'connected' && previous.current === 'unavailable') refresh()
    previous.current = status
  })

  return status === 'connected' && channel !== null ? false : FALLBACK_REFRESH_MS
}
