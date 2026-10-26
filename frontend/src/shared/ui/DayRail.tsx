import type { CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import { formatMinutes, railPosition, type RailStop } from './clock'

export type { RailStop } from './clock'

type Props = {
  stops: RailStop[]
  /** Minute du jour métier (Africa/Algiers) ; null : pas de repère « maintenant ». */
  now: number | null
  /** Nom du fil pour les lecteurs d'écran. */
  label: string
  /** Avant le premier arrêt : durée représentée par la demi-colonne qui le précède (minutes). */
  leadMinutes?: number
}

/**
 * Fil de la journée (charte Ramy, lot 7) : les heures limites à intervalles
 * réguliers, la partie écoulée en rouge et l'heure courante entre deux arrêts.
 * La liste des arrêts porte l'information ; la piste est décorative.
 */
export function DayRail({ stops, now, label, leadMinutes = 120 }: Props) {
  const { t } = useTranslation()
  const n = stops.length
  // Un arrêt sans heure est passé seulement si un arrêt suivant avec heure l'est.
  const isDone = (stop: RailStop, index: number): boolean => {
    if (now === null) return false
    if (stop.minutes !== null) return now >= stop.minutes
    return stops.slice(index + 1).some((next) => next.minutes !== null && now >= next.minutes)
  }
  const current = now === null ? -1 : stops.findIndex((stop, index) => !isDone(stop, index))
  const position = railPosition(stops, now, leadMinutes)

  return (
    <div className="flex flex-col gap-2">
      <div aria-hidden="true" className="relative mx-0 mt-5 mb-1 h-1 rounded-full bg-slate-300">
        <span className="absolute inset-0 origin-left rounded-full bg-brand-600" style={{ transform: `scaleX(${position ?? 0})` } satisfies CSSProperties} />
        {stops.map((stop, index) => (
          <span
            key={stop.label}
            className={`absolute top-1/2 size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 ${
              isDone(stop, index) ? 'border-brand-600 bg-brand-600' : 'border-slate-300 bg-white'
            }`}
            style={{ left: `${((index + 0.5) / n) * 100}%` }}
          />
        ))}
        {position !== null && now !== null && (
          <>
            <span className="absolute top-1/2 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-4 border-brand-700 bg-white" style={{ left: `${position * 100}%` }} />
            <span className="absolute bottom-3.5 -translate-x-1/2 text-xs font-semibold tabular-nums text-brand-700" style={{ left: `${position * 100}%` }}>
              {formatMinutes(now)}
            </span>
          </>
        )}
      </div>
      {/* Liste de définitions (arrêt : heure), lue dans l'ordre ; l'heure s'affiche au-dessus du libellé. */}
      <dl
        aria-label={now === null ? label : t('common.rail.label', { label, time: formatMinutes(now) })}
        className="grid text-center"
        style={{ gridTemplateColumns: `repeat(${n}, minmax(0, 1fr))` }}
      >
        {stops.map((stop, index) => (
          <div key={stop.label} aria-current={index === current ? 'step' : undefined} className="flex flex-col px-0.5 text-xs leading-snug text-slate-600">
            <dt className={`order-2 ${index === current ? 'font-semibold text-slate-900' : ''}`}>
              {stop.label}
              {isDone(stop, index) && <span className="sr-only">{t('common.rail.done')}</span>}
            </dt>
            <dd className={`order-1 text-sm font-semibold tabular-nums ${isDone(stop, index) ? 'text-slate-600' : 'text-slate-900'}`}>{stop.time}</dd>
            {stop.note && <dd className="order-3 font-semibold text-warning">{stop.note}</dd>}
          </div>
        ))}
      </dl>
    </div>
  )
}
