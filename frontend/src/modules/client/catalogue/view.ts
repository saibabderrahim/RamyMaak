import { useCallback, useState, useSyncExternalStore } from 'react'
import type { ClientProduct } from '../../../shared/api/types'
import { readJson, writeJson } from '../../../shared/storage'

// Présentation du catalogue du jour (§7.3) : liste compacte ou galerie sur
// téléphone, choix gardé sur l'appareil ; panier latéral sur PC ; filtres.

/** Clé du choix d'affichage, par appareil (pas par compte : retirée seulement avec les données du site). */
export const CATALOGUE_VIEW_KEY = 'ramymaak:catalogue-view'

export type CatalogueView = 'list' | 'gallery'

/** Choix lu sur l'appareil ; liste compacte par défaut, et si le stockage est indisponible. */
export function readCatalogueView(): CatalogueView {
  return readJson(CATALOGUE_VIEW_KEY) === 'gallery' ? 'gallery' : 'list'
}

/** Liste compacte ou galerie à 2 colonnes (téléphone), choix gardé sur l'appareil. */
export function useCatalogueView(): [CatalogueView, (view: CatalogueView) => void] {
  const [view, setView] = useState<CatalogueView>(readCatalogueView)
  const choose = useCallback((next: CatalogueView) => {
    setView(next)
    // readJson / writeJson ignorent un stockage indisponible (navigation privée, quota).
    writeJson(CATALOGUE_VIEW_KEY, next)
  }, [])
  return [view, choose]
}

/** Panier en panneau latéral à partir de 1 024 px (§7.3). */
export const DESKTOP_SCREEN = '(min-width: 1024px)'

function subscribeDesktop(listener: () => void): () => void {
  const query = window.matchMedia?.(DESKTOP_SCREEN)
  if (!query) return () => undefined
  query.addEventListener('change', listener)
  return () => query.removeEventListener('change', listener)
}

// Sans matchMedia (ancien navigateur, tests) : présentation du téléphone.
const isDesktop = () => window.matchMedia?.(DESKTOP_SCREEN).matches ?? false

/** Écran de PC (≥ 1 024 px), suivi en direct (fenêtre redimensionnée, rotation de tablette). */
export function useDesktop(): boolean {
  return useSyncExternalStore(subscribeDesktop, isDesktop, () => false)
}

/** Texte comparé sans casse ni accents (« Lben » trouve « lben », « eau » trouve « Eau »). */
export function searchable(text: string): string {
  return text
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

/**
 * Produits d'une famille (null : toutes) dont le nom, le nom court ou le
 * code article contient la recherche, dans l'ordre reçu (famille, ordre,
 * code article).
 */
export function filterProducts(products: readonly ClientProduct[], familyId: number | null, search: string): ClientProduct[] {
  const needle = searchable(search)
  return products.filter(
    (product) =>
      (familyId === null || product.family_id === familyId) &&
      (needle === '' || [product.name, product.short_name, product.sku].some((text) => searchable(text).includes(needle))),
  )
}

/** Paramètres de l'adresse avec `key` changé (null : retiré), les autres gardés. */
export function withParam(params: URLSearchParams, key: string, value: string | null): URLSearchParams {
  const next = new URLSearchParams(params)
  if (value === null || value === '') next.delete(key)
  else next.set(key, value)
  return next
}
