import { formatDate } from '../../../shared/format'

// Mise en forme commune à l'accueil, au catalogue et au panier du client (ENF-13).

const weekdayFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'long' })

/** « jeudi 08/10/2026 » pour « 2026-10-08 » (date calendaire, sans fuseau). */
export function formatDayLong(day: string): string {
  return `${weekdayFormat.format(new Date(`${day.slice(0, 10)}T00:00:00Z`))} ${formatDate(day)}`
}

const quantityFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })

/** Quantité en unités de vente, espace insécable comme séparateur de milliers. */
export function formatQuantity(value: number): string {
  return quantityFormat.format(value).replace(/[\u202f\u00a0]/g, '\u00a0')
}

const rateFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })

/** Taux de TVA en pourcentage : « 19 », « 9,5 ». */
export function formatRate(rate: number): string {
  return rateFormat.format(rate)
}
