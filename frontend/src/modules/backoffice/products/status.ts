import type { Product } from '../../../shared/api/types'

/**
 * Statut affiché par la case « Produit actif » : un produit dont la
 * désactivation est programmée (RG-Q07) est présenté comme désactivé ; la
 * cocher annule la désactivation (`is_active: true`).
 */
export function shownActive(product: Pick<Product, 'is_active' | 'pending_deactivation_on'>): boolean {
  return product.is_active && !product.pending_deactivation_on
}
