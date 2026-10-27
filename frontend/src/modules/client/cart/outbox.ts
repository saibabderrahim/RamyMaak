import type { TFunction } from 'i18next'
import type { OutboxEntry } from '../../../shared/offline/db'
import type { CartStore } from './store'

// Boîte d'envoi des commandes client (lot 8, contrat §6.3) : motif d'un refus, reprise dans le panier.

/** Refus dont le texte de l'application vaut le motif du serveur, quand celui-ci manque. */
const PLAIN_REFUSALS = ['CLIENT_BLOCKED', 'DAY_NOT_OPEN', 'CUTOFF_PASSED', 'INVALID_TRANSITION', 'NOT_FOUND'] as const
type PlainRefusal = (typeof PLAIN_REFUSALS)[number]

/** Motif d'un refus : celui du serveur (`detail`), sinon le texte du code, sinon un texte général. */
export function refusalReason(t: TFunction, error: OutboxEntry['error']): string {
  if (error?.detail) return error.detail
  if (error && (PLAIN_REFUSALS as readonly string[]).includes(error.code)) return t(`orders.client.errors.${error.code as PlainRefusal}`)
  return t('offline.outbox.refusedFallback')
}

/** Panier reconstruit depuis la commande refusée : le prochain envoi aura un nouvel identifiant (RG-C07). */
export function resumeInCart(store: CartStore, entry: OutboxEntry): void {
  store.rebuild(
    entry.day,
    entry.body.lines.map((line) => ({ product_id: line.product_id, quantity: line.quantity })),
  )
  if (entry.body.comment) store.setComment(entry.body.comment)
}
