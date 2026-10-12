import { BUSINESS_TIME_ZONE, formatDate } from '../../../shared/format'
import { addDays } from '../shared/dates'

/**
 * Dates calendaires des journées (« AAAA-MM-JJ », sans heure ni fuseau) :
 * jour de la semaine, libellés, intervalle du calendrier, fenêtre
 * d'ouverture (EF-ADV-01).
 */

export const DAY_PATTERN = /^\d{4}-\d{2}-\d{2}$/

/** Heure « HH:MM » sur 24 h (saisie dans le fuseau métier, Africa/Algiers). */
export const TIME_PATTERN = /^([01]\d|2[0-3]):[0-5]\d$/

/** Une journée s'ouvre d'aujourd'hui à aujourd'hui + 30 (règle du serveur). */
export const OPEN_DAYS_AHEAD = 30

const weekdayFormat = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', timeZone: 'UTC' })

function utc(day: string): Date {
  return new Date(`${day.slice(0, 10)}T00:00:00Z`)
}

export function isDay(value: string | null | undefined): value is string {
  return typeof value === 'string' && DAY_PATTERN.test(value) && !Number.isNaN(utc(value).getTime())
}

/** Jour de la semaine : 0 = dimanche … 6 = samedi (comme `weekday` et `working_days`). */
export function weekdayOf(day: string): number {
  return utc(day).getUTCDay()
}

/** « jeudi 08/10/2026 ». */
export function formatDayLong(day: string): string {
  return `${weekdayFormat.format(utc(day))} ${formatDate(day)}`
}

const dayMonthFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: BUSINESS_TIME_ZONE, day: '2-digit', month: '2-digit' })
const timeFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: BUSINESS_TIME_ZONE, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

/** Instant ISO 8601 → `{ date: '08/10', time: '09:40' }` dans le fuseau métier (ENF-13). */
export function instantParts(iso: string): { date: string; time: string } {
  const instant = new Date(iso)
  return { date: dayMonthFormat.format(instant), time: timeFormat.format(instant) }
}

/** Toutes les dates de `from` à `to` incluses (au plus quelques dizaines). */
export function datesBetween(from: string, to: string): string[] {
  const dates: string[] = []
  for (let day = from; day <= to && dates.length < 400; day = addDays(day, 1)) dates.push(day)
  return dates
}

/** Nombre de jours de `from` à `to` inclus. */
export function spanOf(from: string, to: string): number {
  return Math.round((utc(to).getTime() - utc(from).getTime()) / 86_400_000) + 1
}

export type OpenWindow = 'open' | 'past' | 'tooFar'

/** Position d'une date dans la fenêtre d'ouverture : d'aujourd'hui à aujourd'hui + 30. */
export function openWindow(day: string, today: string): OpenWindow {
  if (day < today) return 'past'
  if (day > addDays(today, OPEN_DAYS_AHEAD)) return 'tooFar'
  return 'open'
}
