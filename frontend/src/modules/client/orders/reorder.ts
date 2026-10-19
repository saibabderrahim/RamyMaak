import type { ClientProduct, ClientToday, OrderLine } from '../../../shared/api/types'
import { MAX_LINE_QUANTITY } from '../../../shared/ui/QuantityStepper'
import type { CartLine } from '../cart/store'

// « Recommander » (EF-CLT-05) : panier rebâti depuis une commande passée, sur
// la journée proposée — contrat : docs/lot-5-contrat-api.md §6.

export type ReorderSkip = { product_id: number; name: string; reason: 'unavailable' | 'soldOut' }
export type ReorderCap = { product_id: number; name: string; from: number; to: number }

export type ReorderPlan = {
  /** Lignes du nouveau panier, dans l'ordre de la commande. */
  lines: CartLine[]
  /** Produits ignorés : plus commandables ce jour-là, ou épuisés. */
  skipped: ReorderSkip[]
  /** Quantités plafonnées au disponible du jour. */
  capped: ReorderCap[]
}

/**
 * Lignes reprises d'une commande : la quantité demandée, plafonnée à
 * `available` quand une borne existe (RG-C03) ; sans borne (`available`
 * null), la quantité demandée est gardée et le serveur reste juge (RG-C09).
 * Un produit absent du catalogue du jour (non commandable, I-44) ou épuisé
 * est ignoré.
 */
export function planReorder(lines: readonly OrderLine[], products: readonly ClientProduct[]): ReorderPlan {
  const catalogue = new Map(products.map((product) => [product.id, product]))
  const plan: ReorderPlan = { lines: [], skipped: [], capped: [] }

  for (const line of lines) {
    const product = catalogue.get(line.product_id)
    if (!product) {
      plan.skipped.push({ product_id: line.product_id, name: line.name, reason: 'unavailable' })
      continue
    }
    if (product.sold_out || product.available === 0) {
      plan.skipped.push({ product_id: line.product_id, name: product.name, reason: 'soldOut' })
      continue
    }

    const max = Math.min(product.available ?? MAX_LINE_QUANTITY, MAX_LINE_QUANTITY)
    const quantity = Math.min(line.requested_qty, max)
    if (quantity < line.requested_qty) plan.capped.push({ product_id: line.product_id, name: product.name, from: line.requested_qty, to: quantity })
    if (quantity > 0) plan.lines.push({ product_id: line.product_id, quantity })
  }

  return plan
}

/** Le résumé a quelque chose à signaler (produit ignoré ou quantité plafonnée). */
export function hasChanges(plan: ReorderPlan): boolean {
  return plan.skipped.length > 0 || plan.capped.length > 0
}

/** Raison pour laquelle la journée proposée ne permet pas de recommander. */
export type ReorderBlock = 'CLIENT_BLOCKED' | 'WAITING_QUOTAS' | 'DAY_CLOSED' | 'CUTOFF_PASSED' | 'MAX_ORDERS_REACHED'

/**
 * Journée proposée fermée à une nouvelle commande : `submit_blocker` (dans
 * l'ordre du serveur), précisé par l'état de la journée (DAY_NOT_OPEN :
 * en attente des quotas ou clôturée) ; null : « Recommander » est possible.
 */
export function reorderBlock(today: Pick<ClientToday, 'status' | 'can_submit' | 'submit_blocker'>): ReorderBlock | null {
  if (today.status === 'ORDERING_OPEN' && today.can_submit) return null
  switch (today.submit_blocker) {
    case 'CLIENT_BLOCKED':
    case 'CUTOFF_PASSED':
    case 'MAX_ORDERS_REACHED':
      return today.submit_blocker
  }
  if (today.status === 'DAY_CLOSED') return 'DAY_CLOSED'
  if (today.status === 'ORDERING_CLOSED') return 'CUTOFF_PASSED'
  return 'WAITING_QUOTAS'
}
