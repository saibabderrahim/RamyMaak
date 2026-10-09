import { formatAmount, formatDate } from '../../../shared/format'

const countFormat = new Intl.NumberFormat('fr-FR')
const numberFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 7 })

/** Nombre de lignes, espace comme séparateur de milliers (ENF-13). */
export function formatCount(value: number): string {
  return countFormat.format(value).replace(/[\u202f\u00a0]/g, '\u00a0')
}

/** Champs de l'aperçu exprimés en dinars. */
const AMOUNT_FIELDS = new Set(['price_ht'])

type ValueLabels = { empty: string; yes: string; no: string }

/**
 * Valeur de l'aperçu des changements (§8.1, étape 3) affichée à la
 * française : dates JJ/MM/AAAA, montants en DA, booléens Oui / Non.
 */
export function formatChangeValue(field: string, value: unknown, labels: ValueLabels): string {
  if (value === null || value === undefined || value === '') return labels.empty
  if (typeof value === 'boolean') return value ? labels.yes : labels.no
  if (typeof value === 'number') return AMOUNT_FIELDS.has(field) ? formatAmount(value) : numberFormat.format(value)
  if (typeof value === 'string') return /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value) : value
  return JSON.stringify(value)
}
