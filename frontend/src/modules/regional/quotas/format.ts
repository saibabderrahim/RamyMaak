import type { RegionalQuota } from '../../../shared/api/types'
import { BUSINESS_TIME_ZONE } from '../../../shared/format'

/** Journée « AAAA-MM-JJ » valide (calendrier compris : pas de 31/02). */
export function isDayKey(value: string | null): value is string {
  if (value === null || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T00:00:00Z`)
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value
}

/** Lendemain calendaire d'une journée (repris de shared/format). */
export { nextDay } from '../../../shared/format'

/** Grille de répartition d'une région pour une journée (EF-REG-02) : `/regional/repartition?day=…&region=…`. */
export function distributionPath(day: string, regionId: number): string {
  return `/regional/repartition?${new URLSearchParams({ day, region: String(regionId) })}`
}

const weekdayFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'long' })

/** « jeudi » pour « 2026-10-08 ». */
export function weekdayOf(day: string): string {
  return weekdayFormat.format(new Date(`${day}T00:00:00Z`))
}

const dayMonthFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: BUSINESS_TIME_ZONE, day: '2-digit', month: '2-digit' })
const timeFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: BUSINESS_TIME_ZONE, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

/** Instant ISO 8601 → « 08/10 » dans le fuseau métier (ENF-13). */
export function formatDayMonth(iso: string): string {
  return dayMonthFormat.format(new Date(iso))
}

/** Instant ISO 8601 (ou horodatage) → « 08:10 » dans le fuseau métier (ENF-13). */
export function formatTime(instant: string | number): string {
  return timeFormat.format(new Date(instant))
}

export type FamilyGroup = { family: RegionalQuota['product']['family']; quotas: RegionalQuota[] }

/** Quotas groupés par famille, dans l'ordre reçu (celui de la matrice ADV). */
export function groupByFamily(quotas: readonly RegionalQuota[]): FamilyGroup[] {
  const groups = new Map<number, FamilyGroup>()
  for (const quota of quotas) {
    const { family } = quota.product
    const group = groups.get(family.id) ?? { family, quotas: [] }
    group.quotas.push(quota)
    groups.set(family.id, group)
  }
  return [...groups.values()]
}
