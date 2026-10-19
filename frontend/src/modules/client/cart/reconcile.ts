import type { ClientProduct, OrderLineInput } from '../../../shared/api/types'
import { type PricedLine, type Totals, totals } from '../../../shared/money'
import { MAX_LINE_QUANTITY } from '../../../shared/ui/QuantityStepper'
import type { CartLine } from './store'

// Panier rapproché du catalogue du jour (contrat du lot 5, §6 « Panier ») et
// montants indicatifs calculés avec les règles du serveur (shared/money.ts,
// RG-P04, RG-P05). Fonctions pures, sans état.

/** Changement apporté au panier par le rapprochement. `name` null : produit inconnu de l'appareil. */
export type CartChange =
  | { kind: 'removed'; productId: number; name: string | null; quantity: number }
  | { kind: 'capped'; productId: number; name: string; from: number; to: number }

export type Reconciliation = { lines: CartLine[]; changes: CartChange[] }

/**
 * Rapprochement des lignes du panier avec les produits du jour :
 * - un produit absent du catalogue (plus commandable) ou épuisé est retiré ;
 * - une quantité au-dessus du disponible, quand il est connu (borne), est
 *   plafonnée ; sans borne, rien n'est montré ni plafonné (RG-C09), sauf
 *   au plafond d'une ligne (I-60) : le serveur reste juge.
 * L'ordre des lignes est gardé. `names` : noms déjà vus, pour signaler un
 * produit qui a quitté le catalogue.
 */
export function reconcileLines(
  lines: readonly CartLine[],
  products: readonly ClientProduct[],
  names: ReadonlyMap<number, string> = new Map(),
): Reconciliation {
  const byId = new Map(products.map((product) => [product.id, product]))
  const kept: CartLine[] = []
  const changes: CartChange[] = []

  for (const line of lines) {
    const product = byId.get(line.product_id)
    const max = product ? Math.min(product.available ?? MAX_LINE_QUANTITY, MAX_LINE_QUANTITY) : 0
    if (!product || product.sold_out || max <= 0) {
      changes.push({ kind: 'removed', productId: line.product_id, name: product?.name ?? names.get(line.product_id) ?? null, quantity: line.quantity })
    } else if (line.quantity > max) {
      changes.push({ kind: 'capped', productId: line.product_id, name: product.name, from: line.quantity, to: max })
      kept.push({ product_id: line.product_id, quantity: max })
    } else {
      kept.push(line)
    }
  }

  return { lines: kept, changes }
}

/** Ligne du panier avec son produit du jour. */
export type CartItem = { line: CartLine; product: ClientProduct }

/** Lignes dont le produit figure au catalogue du jour, dans l'ordre du panier. */
export function cartItems(lines: readonly CartLine[], products: readonly ClientProduct[]): CartItem[] {
  const byId = new Map(products.map((product) => [product.id, product]))
  return lines.flatMap((line) => {
    const product = byId.get(line.product_id)
    return product ? [{ line, product }] : []
  })
}

/** Ligne chiffrée au prix du jour de l'unité de vente (`price.price_ht`). */
export function pricedLine({ line, product }: CartItem): PricedLine {
  return { unit_price_ht: product.price.price_ht, vat_rate: product.vat_rate, quantity: line.quantity }
}

/** Totaux HT, TVA et TTC indicatifs du panier. */
export function cartTotals(items: readonly CartItem[]): Totals {
  return totals(items.map(pricedLine))
}

/** Montant HT d'une ligne. */
export function lineAmount(item: CartItem): number {
  return totals([pricedLine(item)]).ht
}

/** Lignes envoyées à l'API (POST et PATCH), dans l'ordre du panier : les refus portent leurs indices. */
export function orderLines(lines: readonly CartLine[]): OrderLineInput[] {
  return lines.map(({ product_id, quantity }) => ({ product_id, quantity }))
}

/** Commentaire envoyé : sans espaces autour, null s'il est vide (comme le serveur). */
export function normalizeComment(comment: string): string | null {
  const trimmed = comment.trim()
  return trimmed === '' ? null : trimmed
}
