import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { ApiError } from '../../../shared/api/client'
import { businessToday, formatDateTime } from '../../../shared/format'
import { useCoalescedRefresh } from '../../../shared/orders'
import { Alert, Button, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { minutesOf, useBusinessNowOn } from '../../../shared/ui/clock'
import { DayRail } from '../../../shared/ui/DayRail'
import { DayStatusBadge } from '../days/components'
import { formatDayLong, isDay } from '../days/dates'
import { useBackoffice } from '../shared/access'
import { MONITORING_DAY_EVENTS, MONITORING_REGION_EVENTS, monitoringKey, useDayMonitoring, useDistributionLateRefresh } from './api'
import { KeyFigures, ProductMatrix, RegionsTable } from './components'
import { ExportDialog, PickingListDialog } from './DocumentDialogs'

/** Abonnement à `region-day.{r}.{jour}` d'une région visible (aucun rendu). */
function RegionLive({ day, regionId }: { day: string; regionId: number }) {
  useCoalescedRefresh(`region-day.${regionId}.${day}`, MONITORING_REGION_EVENTS, [monitoringKey(day)])
  return null
}

/**
 * Suivi de la journée (EF-ADV-03) : `/adv/journees/:day/suivi`, en lecture
 * pour l'Admin. Chiffres clés, régions avec leurs alertes (retard de
 * répartition, retard de validation), matrice produit × région des
 * quantités validées (épuisés en évidence), bon de préparation et export.
 * Tenu à jour par `adv.{jour}` et `region-day.{région}.{jour}` de chaque
 * région visible (l'API reste la source de vérité, les identifiants seuls
 * sont diffusés, I-81).
 */
export default function DayMonitoringPage() {
  const { day = '' } = useParams()
  return <Monitoring key={day} day={day} />
}

function Monitoring({ day }: { day: string }) {
  const { t } = useTranslation()
  const { base, can } = useBackoffice()
  const valid = isDay(day)
  const refetchInterval = useCoalescedRefresh(valid ? `adv.${day}` : null, MONITORING_DAY_EVENTS, [monitoringKey(day)])
  const query = useDayMonitoring(day, { enabled: valid, refetchInterval })
  // N-13 (lot 8) : « Répartition en retard » reçue dans le centre de notifications.
  useDistributionLateRefresh()
  const data = query.data
  const [dialog, setDialog] = useState<'picking' | 'export' | null>(null)
  const now = useBusinessNowOn(day, businessToday())

  const links = (
    <>
      <Link to={`${base}/journees/${day}`} className="inline-flex min-h-11 items-center text-sm text-brand-700 hover:underline">
        {t('backoffice.monitoring.links.day')}
      </Link>
      {can('order.view') && (
        <Link to={`${base}/commandes?jour=${day}`} className="inline-flex min-h-11 items-center text-sm text-brand-700 hover:underline">
          {t('backoffice.monitoring.links.orders')}
        </Link>
      )}
    </>
  )

  if (!valid) {
    return (
      <>
        <PageHeader title={t('backoffice.monitoring.title')} />
        <Alert tone="warning">{t('backoffice.monitoring.invalid')}</Alert>
      </>
    )
  }

  if (!data) {
    const notOpened = query.error instanceof ApiError && query.error.status === 404
    return (
      <>
        <PageHeader title={t('backoffice.monitoring.title')} actions={links} />
        <p className="-mt-3 mb-4 text-sm text-slate-600">{t('backoffice.monitoring.subtitle', { day: formatDayLong(day) })}</p>
        {notOpened ? (
          <Alert tone="warning">{t('backoffice.monitoring.notOpened')}</Alert>
        ) : query.isError ? (
          <ErrorAlert error={query.error} />
        ) : (
          <Spinner label={t('backoffice.monitoring.loading')} />
        )}
      </>
    )
  }

  return (
    <>
      <PageHeader
        title={t('backoffice.monitoring.title')}
        actions={
          <>
            {links}
            {can('order.process') && (
              <Button variant="secondary" onClick={() => setDialog('picking')}>
                {t('backoffice.documents.pickingList')}
              </Button>
            )}
            <Button variant="secondary" onClick={() => setDialog('export')}>
              {t('backoffice.documents.export')}
            </Button>
          </>
        }
      />
      <div className="-mt-3 mb-6 flex flex-wrap items-center gap-3 text-sm text-slate-700">
        <span>{t('backoffice.monitoring.subtitle', { day: formatDayLong(day) })}</span>
        <DayStatusBadge day={{ status: data.status, status_label: t(`backoffice.days.status.${data.status}`) }} />
        <span className="text-xs text-slate-500">{t('backoffice.monitoring.generatedAt', { date: formatDateTime(data.generated_at) })}</span>
      </div>

      <div className="flex flex-col gap-6">
        {/* Échec d'une relecture en arrière-plan : le dernier suivi reçu reste affiché. */}
        <ErrorAlert error={query.error} />
        {/* Fil de la journée (charte Ramy, lot 7) : heures limites de la journée, heure courante le jour même. */}
        <section aria-label={t('backoffice.monitoring.rail')} className="rounded-lg border border-slate-200 bg-white px-4 pt-2 pb-4">
          <DayRail
            label={t('backoffice.monitoring.rail')}
            now={now}
            stops={(['distribution', 'ordering', 'validation', 'closing'] as const).map((kind) => ({
              label: t(`backoffice.monitoring.railStops.${kind}`),
              time: data.cutoffs[kind],
              minutes: minutesOf(data.cutoffs[kind]),
            }))}
          />
        </section>
        <KeyFigures data={data} />
        <RegionsTable data={data} />
        <ProductMatrix data={data} />
      </div>

      {data.regions.map((item) => (
        <RegionLive key={item.region.id} day={day} regionId={item.region.id} />
      ))}

      <PickingListDialog day={day} open={dialog === 'picking'} onClose={() => setDialog(null)} />
      <ExportDialog day={day} open={dialog === 'export'} onClose={() => setDialog(null)} />
    </>
  )
}
