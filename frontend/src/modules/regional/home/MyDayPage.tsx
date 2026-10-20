import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import type { AppNotification, NotificationType } from '../../../shared/api/types'
import { useAuthenticatedUser } from '../../../shared/auth/session'
import { businessToday, formatDate } from '../../../shared/format'
import { NOTIFICATION_EVENT, useChannel } from '../../../shared/realtime'
import { Alert, Button, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useErrorMessage } from '../../../shared/ui/useErrorMessage'
import { DayToggle, RegionOrdersLive } from '../orders/components'
import { useLiveIntervals } from '../orders/api'
import { formatTime, isDayKey, nextDay, weekdayOf } from '../quotas/format'
import { DASHBOARD_EVENTS, DASHBOARD_KEY, regionalDashboardKey, useRegionalDashboard } from './api'
import { ClientsWithoutOrder, RegionDayCard } from './components'
import { ordersPath, quotasPath } from './model'

/**
 * Notifications qui changent « Ma journée » sans passer par le canal d'une
 * région : prolongation d'une heure limite (CUTOFF_EXTENDED, canal `user.{id}`),
 * publication ou modification des quotas (ouverture de la journée).
 */
const DASHBOARD_NOTIFICATIONS: readonly NotificationType[] = ['CUTOFF_EXTENDED', 'QUOTAS_PUBLISHED', 'QUOTAS_UPDATED']

const SHORTCUT =
  'inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

/**
 * « Ma journée » du régional (EF-REG-01), page d'accueil `/regional?day=AAAA-MM-JJ` :
 * aujourd'hui (par défaut) ou demain ; une carte par région (statut, heures
 * limites dont la validation « bloquante » ou « prolongée », commandes à valider
 * avec leur lien, accès à la grille de répartition) ; « Clients sans commande »
 * par région, avec l'appel en un geste et la fiche du client. Tenue à jour en
 * temps réel sur le canal de chaque région (commandes, décisions, quotas,
 * répartition) et sur `user.{id}` (prolongation d'une heure limite), sinon
 * toutes les 30 s (§9.6). Écran pensé pour le téléphone. Contrat :
 * docs/lot-6-contrat-api.md §3 et §8.
 */
export default function MyDayPage() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const queryClient = useQueryClient()
  const errorMessage = useErrorMessage()
  const [searchParams, setSearchParams] = useSearchParams()

  const today = businessToday()
  const tomorrow = nextDay(today)
  const requested = searchParams.get('day')
  const day = isDayKey(requested) ? requested : today

  const { interval, report } = useLiveIntervals()
  const dashboard = useRegionalDashboard(day, interval)
  const data = dashboard.data

  useChannel(`user.${user.id}`, {
    [NOTIFICATION_EVENT]: (payload) => {
      const type = (payload as Partial<AppNotification> | null)?.type
      if (type !== undefined && DASHBOARD_NOTIFICATIONS.includes(type)) void queryClient.invalidateQueries({ queryKey: DASHBOARD_KEY })
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

  return (
    <>
      <PageHeader title={t('orders.myDay.title')} />

      <div className="flex flex-col gap-4">
        <DayToggle day={day} today={today} tomorrow={tomorrow} label={t('orders.myDay.dayChoice')} onChange={selectDay} />

        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <p className="font-semibold text-slate-900">{t('orders.myDay.dayTitle', { date: `${weekdayOf(day)} ${formatDate(day)}` })}</p>
          {data && <p className="text-xs text-slate-500">{t('orders.myDay.updatedAt', { time: formatTime(dashboard.dataUpdatedAt) })}</p>}
        </div>

        {dashboard.error && !data && (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={dashboard.error} />
            <Button variant="secondary" className="self-start" onClick={() => void dashboard.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        )}
        {dashboard.isPending && !dashboard.error && <Spinner />}

        {data &&
          (!data.opened ? (
            <Alert>
              <p className="font-semibold">{t('orders.myDay.notOpened')}</p>
              <p>{t('orders.myDay.notOpenedHint')}</p>
            </Alert>
          ) : (
            <>
              {data.regions.map((region) => (
                <RegionOrdersLive
                  key={`${data.day}-${region.region.id}`}
                  regionId={region.region.id}
                  day={data.day}
                  events={DASHBOARD_EVENTS}
                  queryKeys={[regionalDashboardKey(data.day)]}
                  onInterval={report}
                />
              ))}

              {dashboard.isRefetchError && (
                <Alert tone="warning">
                  <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <p>{t('orders.myDay.refreshFailed', { reason: errorMessage(dashboard.error) })}</p>
                    <Button variant="secondary" onClick={() => void dashboard.refetch()}>
                      {t('common.retry')}
                    </Button>
                  </div>
                </Alert>
              )}

              {data.status === 'CLOSED' && <Alert>{t('orders.myDay.closed')}</Alert>}

              <nav aria-label={t('orders.myDay.shortcuts.label')} className="flex flex-wrap gap-2">
                <Link to={quotasPath(data.day)} className={SHORTCUT}>
                  {t('orders.myDay.shortcuts.quotas')}
                </Link>
                <Link to={ordersPath(data.day)} className={SHORTCUT}>
                  {t('orders.myDay.shortcuts.orders')}
                </Link>
              </nav>

              {data.regions.length === 0 ? (
                <Alert tone="warning">{t('orders.myDay.noRegion')}</Alert>
              ) : (
                <>
                  <div className="flex flex-col gap-4">
                    {data.regions.map((region) => (
                      <RegionDayCard key={region.region.id} region={region} day={data.day} validationBlocking={data.validation_blocking} />
                    ))}
                  </div>
                  <ClientsWithoutOrder dashboard={data} />
                </>
              )}
            </>
          ))}
      </div>
    </>
  )
}
