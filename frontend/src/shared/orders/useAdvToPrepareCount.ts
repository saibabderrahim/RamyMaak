import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import type { AdvOrdersPage } from '../api/types'
import { businessToday } from '../format'
import { ORDER_CANCELLED_BY_ADV, ORDER_VALIDATED, ORDERS_PROCESSED } from '../realtime'
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
 * Nombre de commandes Validées dans la file de l'appelant ; 0 tant qu'il
 * n'est pas lu ou si le compte ne lit pas les commandes (`enabled` faux :
 * aucune requête, aucun abonnement).
 */
export function useAdvToPrepareCount(enabled: boolean): number {
  const refetchInterval = useCoalescedRefresh(enabled ? `adv.${businessToday()}` : null, TO_PREPARE_EVENTS, [ADV_TO_PREPARE_KEY])
  const query = useQuery({
    queryKey: ADV_TO_PREPARE_KEY,
    queryFn: ({ signal }) => api.get<AdvOrdersPage>('/adv/orders', { 'filter[status]': 'VALIDATED', per_page: 1 }, signal),
    select: (page) => page.meta.counts.VALIDATED,
    enabled,
    refetchInterval,
  })
  return enabled ? (query.data ?? 0) : 0
}
