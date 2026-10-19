import type { OrderHistoryEntry, OrderStatus } from '../../../shared/api/types'
import { BUSINESS_TIME_ZONE } from '../../../shared/format'

// Mise en forme de « Mes commandes » (EF-CLT-04, ENF-13) — contrat : docs/lot-5-contrat-api.md §3 et §6.

/**
 * Couleur du statut (contraste AA, le libellé dit toujours le statut) :
 * envoyée en bleu (en attente de décision), validée et suivantes en nuances
 * de vert, rejetée en rouge, annulée et expirée en gris.
 */
export const STATUS_CLASSES: Record<OrderStatus, string> = {
  SUBMITTED: 'bg-sky-100 text-sky-900',
  VALIDATED: 'bg-emerald-100 text-emerald-900',
  IN_PREPARATION: 'bg-teal-100 text-teal-900',
  PREPARED: 'bg-green-100 text-green-900',
  SHIPPED: 'bg-green-200 text-green-950',
  DELIVERED: 'bg-emerald-200 text-emerald-950',
  REJECTED: 'bg-red-100 text-red-800',
  CANCELLED: 'bg-slate-100 text-slate-700',
  EXPIRED: 'bg-slate-200 text-slate-800',
}

const timeFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: BUSINESS_TIME_ZONE, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

/** Heure « HH:MM » d'un instant ISO 8601, dans le fuseau métier (D-10). */
export function formatTime(iso: string): string {
  return timeFormat.format(new Date(iso))
}

const weekdayFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'long' })

/** Jour de la semaine d'une date calendaire « AAAA-MM-JJ » : « jeudi ». */
export function weekdayOf(day: string): string {
  return weekdayFormat.format(new Date(`${day.slice(0, 10)}T00:00:00Z`))
}

const quantityFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })

/** Quantité en unités de vente, espace insécable comme séparateur de milliers. */
export function formatQuantity(value: number): string {
  return quantityFormat.format(value).replace(/[  ]/g, ' ')
}

/**
 * Motif (rejet, ajustement, annulation) : les listes de motifs
 * paramétrables arrivent au lot 6 (EF-ADM-05) ; d'ici là, le code est
 * rendu lisible (« QUOTA_INSUFFISANT » → « Quota insuffisant »).
 */
export function reasonLabel(code: string): string {
  const text = code.replace(/[_-]+/g, ' ').trim().toLowerCase()
  return text === '' ? code : text.charAt(0).toUpperCase() + text.slice(1)
}

/**
 * Clé du libellé d'une entrée de l'historique (§6.3) : envoi, modification
 * par le client (Envoyée → Envoyée, nouvelle version) ou changement de statut.
 */
export function historyLabelKey(
  entry: Pick<OrderHistoryEntry, 'from' | 'to'>,
): 'orders.clientOrders.history.created' | 'orders.clientOrders.history.modified' | `orders.clientOrders.history.to.${OrderStatus}` {
  if (entry.from === null) return 'orders.clientOrders.history.created'
  if (entry.from === entry.to) return 'orders.clientOrders.history.modified'
  return `orders.clientOrders.history.to.${entry.to}`
}

/** Résultat d'une action affiché par la page : message, ou refus du serveur (Problem Details). */
export type Feedback = { tone: 'success' | 'warning'; text: string } | { error: unknown }

/** Message transmis par l'écran précédent (`state.notice`), après un envoi par exemple. */
export function locationNotice(state: unknown): string | null {
  const notice = typeof state === 'object' && state !== null ? (state as { notice?: unknown }).notice : undefined
  return typeof notice === 'string' && notice !== '' ? notice : null
}

/** Numéro de page lu dans l'adresse (`?page=`), 1 par défaut. */
export function pageParam(value: string | null): number {
  const page = Number(value)
  return Number.isInteger(page) && page >= 1 ? page : 1
}
