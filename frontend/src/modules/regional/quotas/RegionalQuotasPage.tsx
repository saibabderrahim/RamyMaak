import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import type { AppNotification, NotificationType } from '../../../shared/api/types'
import { useAuthenticatedUser } from '../../../shared/auth/session'
import { businessToday, formatDate } from '../../../shared/format'
import { FALLBACK_REFRESH_MS, NOTIFICATION_EVENT, useChannel } from '../../../shared/realtime'
import { Alert, Button, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useRegionalQuotas } from './api'
import { RegionLiveUpdates, RegionQuotasCard } from './components'
import { formatTime, isDayKey, nextDay, weekdayOf } from './format'

// Notifications qui touchent les quotas ou les heures limites d'une région (§7.8, RG-A05).
const REGION_DAY_NOTIFICATIONS: readonly NotificationType[] = ['QUOTAS_PUBLISHED', 'QUOTAS_UPDATED', 'CUTOFF_EXTENDED']

type Intervals = ReadonlyMap<number, number | false>

/**
 * Intervalle de rafraîchissement de la page : le plus court de ceux des
 * régions ; 30 s sans région affichée (journée pas encore ouverte : son
 * ouverture n'est pas diffusée au régional).
 */
function refreshInterval(intervals: Intervals): number | false {
  if (intervals.size === 0) return FALLBACK_REFRESH_MS
  const numbers = [...intervals.values()].filter((interval): interval is number => interval !== false)
  return numbers.length > 0 ? Math.min(...numbers) : false
}

/**
 * « Quotas du jour » du régional (§7.6, EF-REG-01) : pour chaque région de
 * son périmètre, statut, heures limites et quotas publiés par famille ;
 * bascule aujourd'hui / demain (`?day=AAAA-MM-JJ`, lien des notifications
 * N-01 et N-02) ; mise à jour en temps réel, sinon toutes les 30 s (§9.6).
 * Écran pensé pour le téléphone.
 */
export default function RegionalQuotasPage() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()

  const today = businessToday()
  const tomorrow = nextDay(today)
  const requested = searchParams.get('day')
  const day = isDayKey(requested) ? requested : today

  const [intervals, setIntervals] = useState<Intervals>(() => new Map())
  const reportInterval = useCallback((regionId: number, interval: number | false | null) => {
    setIntervals((previous) => {
      if (interval === null ? !previous.has(regionId) : previous.get(regionId) === interval) return previous
      const next = new Map(previous)
      if (interval === null) next.delete(regionId)
      else next.set(regionId, interval)
      return next
    })
  }, [])

  const quotas = useRegionalQuotas(day, refreshInterval(intervals))

  // Prolongation d'une heure limite : notifiée sur `user.{id}`, pas sur le canal de la région.
  useChannel(`user.${user.id}`, {
    [NOTIFICATION_EVENT]: (payload) => {
      const type = (payload as Partial<AppNotification> | null)?.type
      if (type !== undefined && REGION_DAY_NOTIFICATIONS.includes(type)) {
        void queryClient.invalidateQueries({ queryKey: ['regional', 'quotas'] })
      }
    },
  })

  const selectDay = (next: string) =>
    setSearchParams(
      (params) => {
        const updated = new URLSearchParams(params)
        updated.set('day', next)
        return updated
      },
      { replace: true },
    )

  const data = quotas.data

  return (
    <>
      <PageHeader title={t('notifications.regionalQuotas.title')} />

      <div className="flex flex-col gap-4">
        <div role="group" aria-label={t('notifications.regionalQuotas.dayChoice')} className="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 sm:max-w-md">
          {[
            { value: today, label: t('notifications.regionalQuotas.today') },
            { value: tomorrow, label: t('notifications.regionalQuotas.tomorrow') },
          ].map(({ value, label }) => (
            <button
              key={value}
              type="button"
              aria-pressed={day === value}
              onClick={() => selectDay(value)}
              className={`flex min-h-12 flex-col items-center justify-center rounded-lg px-3 py-1 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                day === value ? 'bg-white text-brand-800 shadow-xs' : 'text-slate-700 hover:bg-slate-200'
              }`}
            >
              {label}
              <span className="text-xs font-normal text-slate-600">{formatDate(value)}</span>
            </button>
          ))}
        </div>

        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <p className="font-semibold text-slate-900">
            {t('notifications.regionalQuotas.dayTitle', { date: `${weekdayOf(day)} ${formatDate(day)}` })}
          </p>
          {data && (
            <p className="text-xs text-slate-500">{t('notifications.regionalQuotas.updatedAt', { time: formatTime(quotas.dataUpdatedAt) })}</p>
          )}
        </div>

        {quotas.error && (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={quotas.error} />
            <Button variant="secondary" className="self-start" onClick={() => void quotas.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        )}
        {quotas.isPending && !quotas.error && <Spinner />}

        {data &&
          (!data.opened ? (
            <Alert>
              <p className="font-semibold">{t('notifications.regionalQuotas.notOpened')}</p>
              <p>{t('notifications.regionalQuotas.notOpenedHint')}</p>
            </Alert>
          ) : (
            <>
              {data.status === 'CLOSED' && <Alert>{t('notifications.regionalQuotas.closed')}</Alert>}
              {data.regions.length === 0 ? (
                <Alert tone="warning">{t('notifications.regionalQuotas.noRegion')}</Alert>
              ) : (
                <div className="flex flex-col gap-4">
                  {data.regions.map((region) => (
                    <div key={`${data.day}-${region.region.id}`}>
                      <RegionLiveUpdates regionId={region.region.id} day={data.day} onInterval={reportInterval} />
                      <RegionQuotasCard region={region} dayCutoffs={data.cutoffs} />
                    </div>
                  ))}
                </div>
              )}
            </>
          ))}
      </div>
    </>
  )
}
