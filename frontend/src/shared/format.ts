/**
 * Formats d'affichage (ENF-13) : fuseau Africa/Algiers, dates JJ/MM/AAAA.
 */
export const BUSINESS_TIME_ZONE = 'Africa/Algiers'

const dateTimeFormat = new Intl.DateTimeFormat('fr-FR', {
  timeZone: BUSINESS_TIME_ZONE,
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return dateTimeFormat.format(new Date(iso)).replace(',', '')
}

const dateFormat = new Intl.DateTimeFormat('fr-FR', {
  timeZone: 'UTC',
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})

/** Date calendaire « AAAA-MM-JJ » affichée « JJ/MM/AAAA ». */
export function formatDate(day: string | null | undefined): string {
  if (!day) return '—'
  return dateFormat.format(new Date(`${day.slice(0, 10)}T00:00:00Z`))
}

const quantityFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })

/** Quantité en unités de vente « 1 200 », espace insécable comme séparateur de milliers (ENF-13). */
export function formatQuantity(value: number): string {
  return quantityFormat.format(value).replace(/[\u202f\u00a0]/g, '\u00a0')
}

const amountFormat = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** Montant en dinars : 2 décimales, espace comme séparateur de milliers (ENF-13). */
export function formatAmount(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return `${amountFormat.format(value).replace(/[\u202f\u00a0]/g, '\u00a0')}\u00a0DA`
}

/** Jour métier courant « AAAA-MM-JJ » (Africa/Algiers). */
export function businessToday(now: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: BUSINESS_TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now)
  return parts
}
