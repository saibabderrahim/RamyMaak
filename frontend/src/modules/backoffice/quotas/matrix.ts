import type { QuotaCell, QuotaMatrixProduct } from '../../../shared/api/types'
import { BUSINESS_TIME_ZONE } from '../../../shared/format'

/**
 * Règles d'affichage et de saisie de la matrice des quotas (EF-ADV-02,
 * §5.2), sans React : valeur affichée, totaux, lecture d'une saisie et d'un
 * bloc collé depuis Excel.
 */

/** Quota maximal d'une cellule (RG-Q01, `QuotaBook::MAX_QUANTITY`). */
export const MAX_QUANTITY = 1_000_000

/** Taille maximale d'un lot d'enregistrement (`PUT …/quotas`). */
export const MAX_BATCH = 2_000

/** Délai d'enregistrement automatique après la dernière saisie. */
export const SAVE_DELAY_MS = 600

/** Identifiant d'une cellule : « produit:région ». */
export type CellKey = `${number}:${number}`

export const cellKey = (productId: number, regionId: number): CellKey => `${productId}:${regionId}`

/** Valeur affichée d'une cellule (contrat §4) : brouillon, sinon quota publié ; 0 sans ligne en base. */
export function serverValue(cell: QuotaCell | undefined): number {
  return cell ? (cell.draft ?? cell.quantity) : 0
}

export type FamilyGroup = {
  family: QuotaMatrixProduct['family']
  /** Indices des produits du groupe dans la liste des lignes. */
  rows: number[]
}

/**
 * Groupes de lignes par famille, dans l'ordre reçu : le serveur trie déjà
 * les produits par famille, puis par ordre et code article.
 */
export function groupByFamily(products: readonly QuotaMatrixProduct[]): FamilyGroup[] {
  const groups: FamilyGroup[] = []
  products.forEach((product, index) => {
    const last = groups.at(-1)
    if (last && last.family.id === product.family.id) {
      last.rows.push(index)
    } else {
      groups.push({ family: product.family, rows: [index] })
    }
  })
  return groups
}

// Espaces tolérés comme séparateurs de milliers (Excel, saisie à la main).
const SEPARATORS = /[\s\u00a0\u202f]/g

/**
 * Lecture d'une saisie ou d'une valeur collée : entier de 0 à 1 000 000,
 * espaces de milliers tolérés ; une cellule vide vaut 0 (comme Suppr).
 * `null` : valeur refusée (décimale, négative, texte, trop grande).
 */
export function parseQuantity(text: string): number | null {
  const compact = text.replace(SEPARATORS, '')
  if (compact === '') return 0
  if (!/^\d{1,7}$/.test(compact)) return null
  const value = Number(compact)
  return value <= MAX_QUANTITY ? value : null
}

/**
 * Bloc copié depuis Excel : lignes séparées par des retours à la ligne,
 * valeurs par des tabulations. Le retour à la ligne final qu'ajoute Excel
 * ne crée pas de ligne vide.
 */
export function parseClipboard(text: string): string[][] {
  const lines = text.replace(/\r\n?/g, '\n').split('\n')
  if (lines.length > 1 && lines.at(-1) === '') lines.pop()
  return lines.map((line) => line.split('\t'))
}

/** Le texte collé est-il un bloc (plusieurs cellules) plutôt qu'une simple valeur ? */
export function isBlock(text: string): boolean {
  return /[\t\n\r]/.test(text.replace(/(\r\n|\r|\n)$/, ''))
}

const timeFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: BUSINESS_TIME_ZONE, hour: '2-digit', minute: '2-digit' })

/** Heure « 09:12 » dans le fuseau métier (Africa/Algiers). */
export function formatTime(date: Date): string {
  return timeFormat.format(date)
}
