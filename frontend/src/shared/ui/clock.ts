import { useEffect, useState } from 'react'

/** Un arrêt du fil de la journée : libellé, heure affichée et minute du jour (null : sans heure connue, après l'arrêt précédent). */
export type RailStop = { label: string; time: string; minutes: number | null; note?: string }

/** Position (0 à 1) de l'heure courante sur la piste : arrêts au milieu de colonnes égales. */
export function railPosition(stops: RailStop[], now: number | null, leadMinutes = 120): number | null {
  if (now === null || stops.length === 0) return null
  const n = stops.length
  const timed: Array<{ index: number; minutes: number }> = []
  stops.forEach((stop, index) => {
    if (stop.minutes !== null) timed.push({ index, minutes: stop.minutes })
  })
  const first = timed[0]
  const last = timed[timed.length - 1]
  if (!first || !last) return null
  if (now < first.minutes) {
    // Avant le premier arrêt : dans la demi-colonne qui le précède, sur les `leadMinutes` d'avant.
    const ratio = Math.max(0, 1 - (first.minutes - now) / leadMinutes)
    return ((first.index + 0.5) * ratio) / n
  }
  for (let k = 0; k < timed.length - 1; k++) {
    const a = timed[k]
    const b = timed[k + 1]
    if (a && b && now >= a.minutes && now < b.minutes) return (a.index + 0.5 + ((b.index - a.index) * (now - a.minutes)) / (b.minutes - a.minutes)) / n
  }
  // Après le dernier arrêt avec heure : un quart de colonne plus loin, sans dépasser la piste.
  return Math.min(1, (last.index + 0.5 + (last.index < n - 1 ? 0.25 : 0)) / n)
}

/** « HH:MM » → minute du jour ; null si l'heure est absente ou mal formée. */
export function minutesOf(time: string | null | undefined): number | null {
  const match = /^(\d{1,2}):(\d{2})/.exec(time ?? '')
  return match ? Number(match[1]) * 60 + Number(match[2]) : null
}

export function formatMinutes(minutes: number): string {
  const m = ((Math.round(minutes) % 1440) + 1440) % 1440
  return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`
}

const businessClock = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Algiers', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

/** Minute du jour métier (Africa/Algiers) d'un instant. */
export function businessMinutes(epochMs: number): number {
  return minutesOf(businessClock.format(new Date(epochMs))) ?? 0
}

/**
 * Heure du serveur tenue à jour sur l'appareil (D-10) : l'écart entre
 * `serverTime` et l'heure de l'appareil à la réception (`receivedAt`) est
 * conservé, relu toutes les 30 s. null sans heure du serveur.
 */
export function useServerNow(serverTime: string | null | undefined, receivedAt: number): number | null {
  const offset = serverTime ? Date.parse(serverTime) - receivedAt : null
  const [tick, setTick] = useState(() => Date.now())
  useEffect(() => {
    const timer = window.setInterval(() => setTick(Date.now()), 30_000)
    return () => window.clearInterval(timer)
  }, [])
  return offset === null || Number.isNaN(offset) ? null : tick + offset
}
