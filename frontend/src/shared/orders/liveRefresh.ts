import { type QueryKey, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { FALLBACK_REFRESH_MS, useChannel, useRealtimeReconnect, useRealtimeStatus } from '../realtime'

/**
 * Relecture en temps réel des écrans de l'ADV (docs/lot-6-contrat-api.md §7) :
 * comme `useLiveRefresh`, mais les rafales d'événements (une validation par
 * commande, un changement de statut en masse) sont regroupées. Le premier
 * événement programme une relecture `wait` ms plus tard, les suivants
 * l'attendent : au plus une relecture par fenêtre et par écoute ; une
 * relecture déjà en cours est relancée, elle a pu partir avant le changement
 * (§9.6, l'API reste la source de vérité).
 */

/** Fenêtre de regroupement des événements. */
export const LIVE_COALESCE_MS = 500

/**
 * Renvoie l'intervalle de rafraîchissement à passer à `useQuery` : aucun
 * quand la connexion est établie, 30 s sinon (comme `useLiveRefresh`).
 */
export function useCoalescedRefresh(
  channel: string | null,
  events: readonly string[],
  queryKeys: readonly QueryKey[],
  wait: number = LIVE_COALESCE_MS,
): number | false {
  const queryClient = useQueryClient()
  const status = useRealtimeStatus()
  const keysRef = useRef(queryKeys)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    keysRef.current = queryKeys
  })

  useEffect(
    () => () => {
      if (timer.current !== null) clearTimeout(timer.current)
    },
    [],
  )

  const refresh = () => {
    timer.current = null
    keysRef.current.forEach((queryKey) => void queryClient.invalidateQueries({ queryKey }))
  }
  const schedule = () => {
    timer.current ??= setTimeout(refresh, wait)
  }

  useChannel(channel, Object.fromEntries(events.map((event) => [event, schedule])))

  // Retour de la connexion après une coupure : des événements ont pu être manqués. Plusieurs écoutes
  // d'un même écran se rattrapent en même temps : une relecture déjà partie après le retour suffit.
  useRealtimeReconnect(() => keysRef.current.forEach((queryKey) => void queryClient.invalidateQueries({ queryKey }, { cancelRefetch: false })))

  return status === 'connected' && channel !== null ? false : FALLBACK_REFRESH_MS
}
