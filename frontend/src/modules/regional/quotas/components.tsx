import { useEffect, useId, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type { DayCutoffs, RegionalQuota, RegionalQuotaRegion, RegionDayStatus } from '../../../shared/api/types'
import { useLiveRefresh } from '../../../shared/realtime'
import { Badge } from '../../../shared/ui'
import { regionalQuotasKey } from './api'
import { formatDayMonth, formatQuantity, formatTime, groupByFamily } from './format'

const STATUS_TONES: Record<RegionDayStatus, 'neutral' | 'success' | 'warning' | 'brand'> = {
  QUOTA_DRAFT: 'warning',
  QUOTA_PUBLISHED: 'brand',
  DISTRIBUTION_DRAFT: 'brand',
  DISTRIBUTION_PUBLISHED: 'success',
  CLOSED: 'neutral',
}

// Publication (N-01) et modification (N-02) des quotas d'une région (§9.6).
const LIVE_EVENTS = ['QuotaPublished'] as const

/**
 * Abonnement au canal `region-day.{regionId}.{day}` : une publication relit
 * les quotas. Signale l'intervalle de rafraîchissement de secours (30 s
 * hors connexion temps réel) ; `null` au démontage.
 */
export function RegionLiveUpdates({
  regionId,
  day,
  onInterval,
}: {
  regionId: number
  day: string
  onInterval: (regionId: number, interval: number | false | null) => void
}) {
  const interval = useLiveRefresh(`region-day.${regionId}.${day}`, LIVE_EVENTS, [regionalQuotasKey(day)])
  useEffect(() => {
    onInterval(regionId, interval)
  }, [regionId, interval, onInterval])
  useEffect(() => () => onInterval(regionId, null), [regionId, onInterval])
  return null
}

/**
 * Carte d'une région : statut, heures limites applicables (prolongation
 * comprise, RG-A05) et quotas publiés groupés par famille (§7.6).
 */
export function RegionQuotasCard({ region, dayCutoffs }: { region: RegionalQuotaRegion; dayCutoffs: DayCutoffs | null }) {
  const { t } = useTranslation()
  const headingId = useId()
  const groups = useMemo(() => groupByFamily(region.quotas), [region.quotas])
  const published = region.quota_version > 0

  return (
    <section aria-labelledby={headingId} className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 id={headingId} className="text-lg font-semibold text-slate-900">
            {region.region.name}
          </h2>
          <p className="text-sm text-slate-600">{region.region.code}</p>
        </div>
        <Badge tone={STATUS_TONES[region.status]}>{t(`notifications.regionalQuotas.status.${region.status}`)}</Badge>
      </header>

      {published && region.quota_last_published_at !== null && (
        <p className="text-sm text-slate-700">
          {t('notifications.regionalQuotas.published', {
            version: region.quota_version,
            date: formatDayMonth(region.quota_last_published_at),
            time: formatTime(region.quota_last_published_at),
          })}
        </p>
      )}

      <Cutoffs region={region} dayCutoffs={dayCutoffs} />

      {!published ? (
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
          {region.status === 'CLOSED' ? t('notifications.regionalQuotas.closedWithoutQuotas') : t('notifications.regionalQuotas.waiting')}
        </p>
      ) : groups.length === 0 ? (
        <p className="text-sm text-slate-600">{t('notifications.regionalQuotas.noQuota')}</p>
      ) : (
        <>
          <p className="text-sm text-slate-600">{t('notifications.regionalQuotas.products', { count: region.quotas.length })}</p>
          {groups.map(({ family, quotas }) => (
            <FamilyQuotas key={family.id} name={family.name} quotas={quotas} />
          ))}
        </>
      )}
    </section>
  )
}

/** « Répartition avant 10:00 · Commande avant 13:00 », prolongations signalées. */
function Cutoffs({ region, dayCutoffs }: { region: RegionalQuotaRegion; dayCutoffs: DayCutoffs | null }) {
  const { t } = useTranslation()
  const items = [
    { key: 'distribution', time: region.cutoffs.distribution, extended: dayCutoffs !== null && region.cutoffs.distribution !== dayCutoffs.distribution },
    { key: 'ordering', time: region.cutoffs.ordering, extended: dayCutoffs !== null && region.cutoffs.ordering !== dayCutoffs.ordering },
  ] as const

  return (
    <p className="text-sm text-slate-800" data-testid="region-cutoffs">
      <span className="sr-only">{t('notifications.regionalQuotas.cutoffs')} : </span>
      {items.map(({ key, time, extended }, index) => (
        <span key={key}>
          {index > 0 && ' · '}
          {t(`notifications.regionalQuotas.${key}`, { time })}
          {extended && <span className="font-semibold text-amber-800"> ({t('notifications.regionalQuotas.extended')})</span>}
        </span>
      ))}
    </p>
  )
}

function FamilyQuotas({ name, quotas }: { name: string; quotas: RegionalQuota[] }) {
  const { t } = useTranslation()
  const headingId = useId()

  return (
    <div className="flex flex-col">
      <h3 id={headingId} className="border-b border-slate-200 pb-1 text-sm font-semibold tracking-wide text-slate-600 uppercase">
        {name}
      </h3>
      <ul aria-labelledby={headingId} className="divide-y divide-slate-100">
        {quotas.map(({ product, quantity, validated }) => (
          <li key={product.id} className="flex items-center justify-between gap-3 py-2">
            <div className="min-w-0">
              <p className="font-medium break-words text-slate-900">{product.name}</p>
              <p className="text-xs text-slate-600">
                {product.sku} · {product.sales_unit_label}
              </p>
            </div>
            <div className="shrink-0 text-end">
              <p className="font-semibold text-slate-900 tabular-nums">
                {t('notifications.regionalQuotas.quantity', { quantity: formatQuantity(quantity) })}
              </p>
              {validated > 0 && (
                <p className="text-xs text-slate-600 tabular-nums">
                  {t('notifications.regionalQuotas.validated', { quantity: formatQuantity(validated) })}
                </p>
              )}
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
