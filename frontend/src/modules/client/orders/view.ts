import type { Order, OrderHistoryEntry, OrderLine, OrderStatus } from '../../../shared/api/types'
import { BUSINESS_TIME_ZONE } from '../../../shared/format'

// Mise en forme de « Mes commandes » (EF-CLT-04, ENF-13) — contrat : docs/lot-5-contrat-api.md §3 et §6 ;
// motifs, ajustement et expédition : docs/lot-6-contrat-api.md §4 et §8.

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

/** Statuts franchis une fois la commande acceptée par le régional (Validée et suivantes). */
const ACCEPTED_STATUSES: readonly OrderStatus[] = ['VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED', 'DELIVERED']

/** Commande acceptée par le régional (validée, en cours de traitement, expédiée ou livrée). */
export const isAccepted = (status: OrderStatus): boolean => ACCEPTED_STATUSES.includes(status)

/** Commande expédiée : seule étape où le serveur montre les quantités expédiées, les écarts et le bon de livraison (I-79). */
export const isShipped = (status: OrderStatus): boolean => status === 'SHIPPED' || status === 'DELIVERED'

/** Ligne réduite à la validation (quantité validée inférieure à la quantité demandée). */
export const isReduced = (line: Pick<OrderLine, 'requested_qty' | 'validated_qty'>): boolean =>
  line.validated_qty !== null && line.validated_qty < line.requested_qty

/** Ligne expédiée en quantité inférieure à la quantité validée (écart d'expédition, RG-A03). */
export const hasShippingGap = (line: Pick<OrderLine, 'validated_qty' | 'shipped_qty'>): boolean =>
  line.validated_qty !== null && line.shipped_qty !== null && line.shipped_qty < line.validated_qty

/** Nombre de lignes réduites à la validation. */
export const reducedCount = (lines: readonly Pick<OrderLine, 'requested_qty' | 'validated_qty'>[]): number => lines.filter(isReduced).length

/** Dernière entrée de l'historique qui mène au statut donné. */
function lastEntryTo(history: readonly OrderHistoryEntry[], status: OrderStatus): OrderHistoryEntry | undefined {
  return history.filter((entry) => entry.to === status).at(-1)
}

/**
 * Nature de la décision à montrer au client : rejet, annulation exceptionnelle de l'ADV (motif donné,
 * I-75), validation avec ajustement, ou autre décision commentée ; null quand il n'y a rien à dire
 * (commande envoyée, expirée, annulée par le client lui-même, validée telle quelle sans commentaire).
 */
export type DecisionKind = 'REJECTED' | 'CANCELLED' | 'ADJUSTED' | 'other'

export function decisionKind(
  order: Pick<Order, 'status' | 'is_adjusted' | 'decision_reason' | 'decision_reason_code' | 'decision_comment'>,
): DecisionKind | null {
  const stated = order.decision_reason !== null || order.decision_reason_code !== null || order.decision_comment !== null
  if (order.status === 'REJECTED') return stated ? 'REJECTED' : null
  if (order.status === 'CANCELLED') return stated ? 'CANCELLED' : null
  if (order.is_adjusted && isAccepted(order.status)) return 'ADJUSTED'
  return stated ? 'other' : null
}

/** Instant de la décision : celui de l'annulation pour une annulation de l'ADV (`decided_at` garde la validation, I-75). */
export function decisionInstant(order: Pick<Order, 'status' | 'decided_at' | 'history'>): string | null {
  return (order.status === 'CANCELLED' ? lastEntryTo(order.history, 'CANCELLED')?.at : undefined) ?? order.decided_at
}

/** Commande annulée par l'administration des ventes (annulation exceptionnelle après la validation). */
export function cancelledByAdv(order: Pick<Order, 'status' | 'history'>): boolean {
  return order.status === 'CANCELLED' && lastEntryTo(order.history, 'CANCELLED')?.actor === 'ADV'
}
