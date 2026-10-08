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
