import { useCallback, useMemo, useSyncExternalStore } from 'react'
import { useTranslation } from 'react-i18next'
import type { DistributionGrid } from '../../shared/api/types'
import { formatQuantity } from '../../shared/format'
import { buildChange, type CellChange, type EditsByClient, type LimitMessages } from './model'

/** Grille PC et tablette à partir de 768 px (EF-REG-02), vue pivot en dessous (EF-REG-03). */
export const WIDE_SCREEN = '(min-width: 768px)'

function subscribeWide(listener: () => void): () => void {
  const query = window.matchMedia?.(WIDE_SCREEN)
  if (!query) return () => undefined
  query.addEventListener('change', listener)
  return () => query.removeEventListener('change', listener)
}

// Sans matchMedia (ancien navigateur, tests) : la grille PC.
const isWide = () => window.matchMedia?.(WIDE_SCREEN).matches ?? true

/** Largeur de l'écran suivie en direct (rotation, fenêtre redimensionnée). */
export function useWideScreen(): boolean {
  return useSyncExternalStore(subscribeWide, isWide, () => true)
}

/**
 * Saisie d'une cellule (grille PC, vue téléphone, actions de masse) : lecture
 * et contrôle du texte (format, RG-B03, RG-B07) avec les messages traduits.
 * null : rien à changer.
 */
export function useLimitChanges(grid: DistributionGrid, edits: EditsByClient) {
  const { t } = useTranslation()
  const products = useMemo(() => new Map(grid.products.map((product) => [product.id, product])), [grid.products])
  const published = grid.region.distribution_version > 0
  const messages = useMemo<LimitMessages>(
    () => ({
      format: t('distribution.errors.format'),
      aboveQuota: (quota) => t('distribution.errors.aboveQuota', { quota: formatQuantity(quota) }),
      belowValidated: (validated) => t('distribution.errors.belowValidated', { validated: formatQuantity(validated) }),
    }),
    [t],
  )

  return useCallback(
    (clientId: number, productId: number, text: string): CellChange | null => {
      const product = products.get(productId)
      if (!product) return null
      const cell = grid.cells[String(clientId)]?.[String(productId)]
      return buildChange({ clientId, product, cell, edit: edits.get(clientId)?.[productId], published }, text, messages)
    },
    [products, grid.cells, edits, published, messages],
  )
}
