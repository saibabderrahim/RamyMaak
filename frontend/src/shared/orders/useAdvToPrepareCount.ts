import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import type { AdvOrdersPage } from '../api/types'
import { businessToday, nextDay } from '../format'
import { FALLBACK_REFRESH_MS, ORDER_CANCELLED_BY_ADV, ORDER_VALIDATED, ORDERS_PROCESSED } from '../realtime'
import { useCoalescedRefresh } from './liveRefresh'

/**
 * Compteur « commandes à préparer » du menu « Commandes » de l'ADV (N-08,
 * I-80) : pas de notification enregistrée, le nombre se lit sur la file
 * (`meta.counts.VALIDATED`, périmètre de l'ADV, toutes journées) et se tient
 * à jour en temps réel. Dans shared/ : le menu est dans le paquet commun, il
 * ne doit rien importer du back-office.
 */

/** Racine des requêtes du compteur : les écrans de la file l'invalident après un changement. */
export const ADV_TO_PREPARE_KEY = ['adv', 'orders', 'to-prepare'] as const

/** Événements de `adv.{jour}` qui changent le nombre de commandes Validées. */
const TO_PREPARE_EVENTS = [ORDER_VALIDATED, ORDERS_PROCESSED, ORDER_CANCELLED_BY_ADV] as const

/**
 * Relecture de secours, même connecté : le compteur couvre toutes les
 * journées, mais n'écoute que `adv.{aujourd'hui}` et `adv.{demain}` ; un
 * traitement des commandes d'une journée passée, par un autre ADV, est pris
 * en compte au plus tard une minute après.
 */
const COUNTER_SLOW_REFRESH_MS = 60_000

/**
 * Nombre de commandes Validées dans la file de l'appelant ; 0 tant qu'il
 * n'est pas lu ou si le compte ne lit pas les commandes (`enabled` faux :
 * aucune requête, aucun abonnement).
 */
export function useAdvToPrepareCount(enabled: boolean): number {
  const today = businessToday()
  // Demain aussi : une répartition envoyée la veille fait valider des commandes de demain dès aujourd'hui (I-42).
  const todayInterval = useCoalescedRefresh(enabled ? `adv.${today}` : null, TO_PREPARE_EVENTS, [ADV_TO_PREPARE_KEY])
  const tomorrowInterval = useCoalescedRefresh(enabled ? `adv.${nextDay(today)}` : null, TO_PREPARE_EVENTS, [ADV_TO_PREPARE_KEY])
  const refetchInterval = todayInterval === false && tomorrowInterval === false ? COUNTER_SLOW_REFRESH_MS : FALLBACK_REFRESH_MS
  const query = useQuery({
    queryKey: ADV_TO_PREPARE_KEY,
    queryFn: ({ signal }) => api.get<AdvOrdersPage>('/adv/orders', { 'filter[status]': 'VALIDATED', per_page: 1 }, signal),
    select: (page) => page.meta.counts.VALIDATED,
    enabled,
    refetchInterval,
  })
  return enabled ? (query.data ?? 0) : 0
}
