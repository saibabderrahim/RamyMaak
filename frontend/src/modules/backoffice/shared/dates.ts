import { businessToday } from '../../../shared/format'

/** Date calendaire « AAAA-MM-JJ » décalée de `days` jours. */
export function addDays(day: string, days: number): string {
  const date = new Date(`${day.slice(0, 10)}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

/**
 * Journée d'effet d'un changement de région : la journée suivante (RG-S04),
 * pour ne pas fausser les compteurs de la journée en cours.
 */
export function nextBusinessDay(now: Date = new Date()): string {
  return addDays(businessToday(now), 1)
}
