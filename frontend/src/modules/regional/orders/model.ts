import type { OrderHistoryEntry, OrderStatus, RegionalOrdersPage, RegionalOrderSummary } from '../../../shared/api/types'
import { businessToday } from '../../../shared/format'
import { FALLBACK_REFRESH_MS } from '../../../shared/realtime'
import { formatDayMonth, formatTime } from '../quotas/format'

/**
 * Commandes reçues par le régional (EF-REG-04, lecture) — contrat :
 * docs/lot-5-contrat-api.md §4 et §6. Onglets, lecture des pages et mise en
 * forme, sans React.
 */

export type OrderCounts = RegionalOrdersPage['meta']['counts']

/**
 * Onglets de la liste : clé du compteur (`meta.counts`), valeur du paramètre
 * `?onglet=` et statuts demandés (`filter[status]`). « Validées » regroupe
 * Validée à Livrée ; « Annulées et expirées », les deux fins sans décision.
 */
export const ORDER_TABS = [
  { key: 'SUBMITTED', slug: 'a-valider', statuses: ['SUBMITTED'] },
  { key: 'VALIDATED', slug: 'validees', statuses: ['VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED', 'DELIVERED'] },
  { key: 'REJECTED', slug: 'rejetees', statuses: ['REJECTED'] },
  { key: 'CLOSED', slug: 'annulees', statuses: ['CANCELLED', 'EXPIRED'] },
] as const satisfies readonly { key: keyof OrderCounts; slug: string; statuses: readonly OrderStatus[] }[]

export type OrderTab = (typeof ORDER_TABS)[number]
export type OrderTabKey = OrderTab['key']

/** Onglet du paramètre `?onglet=` ; « À valider » par défaut ou pour une valeur inconnue. */
export function tabOf(slug: string | null): OrderTab {
  return ORDER_TABS.find((tab) => tab.slug === slug) ?? ORDER_TABS[0]
}

/** Détail d'une commande reçue (lien des notifications N-05 et N-06). */
export function orderPath(id: string): string {
  return `/regional/commandes/${id}`
}

/** Région filtrée du paramètre `?region=` ; null : absente ou mal formée. */
export function regionParam(value: string | null): number | null {
  const id = Number(value)
  return value !== null && Number.isInteger(id) && id > 0 ? id : null
}

/**
 * Commandes des pages reçues, dans l'ordre du serveur (heure d'envoi
 * croissante, RG-V01). Une page lue après l'arrivée ou le départ d'une
 * commande peut en répéter une : elle n'apparaît qu'une fois, à sa première
 * place, avec son dernier état.
 */
export function ordersOf(pages: readonly RegionalOrdersPage[]): RegionalOrderSummary[] {
  const byId = new Map<string, RegionalOrderSummary>()
  for (const page of pages) for (const order of page.data) byId.set(order.id, order)
  return [...byId.values()]
}

/**
 * Heure d'envoi dans le fuseau métier : « 09:40 », précédée du jour
 * (« 07/10 18:20 ») quand la commande a été envoyée un autre jour que sa
 * journée (commande de demain envoyée la veille).
 */
export function submissionTime(submittedAt: string, day: string): string {
  const time = formatTime(submittedAt)
  return businessToday(new Date(submittedAt)) === day ? time : `${formatDayMonth(submittedAt)} ${time}`
}

/** Entrée de l'historique (§6.3) : envoi, modification par le client (même statut, version suivante) ou changement de statut. */
export function historyEvent(entry: Pick<OrderHistoryEntry, 'from' | 'to'>): 'created' | 'modified' | OrderStatus {
  if (entry.from === null) return 'created'
  return entry.from === entry.to ? 'modified' : entry.to
}

export type Intervals = ReadonlyMap<number, number | false>

/**
 * Intervalle de rafraîchissement d'un écran abonné à plusieurs canaux : le
 * plus court de ceux des régions ; 30 s tant qu'aucune région n'est abonnée.
 */
export function refreshInterval(intervals: Intervals): number | false {
  if (intervals.size === 0) return FALLBACK_REFRESH_MS
  const numbers = [...intervals.values()].filter((interval): interval is number => interval !== false)
  return numbers.length > 0 ? Math.min(...numbers) : false
}
