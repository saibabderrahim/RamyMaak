import { useEffect, useId, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { DayCutoffs, RegionalQuota, RegionalQuotaRegion, RegionDayStatus } from '../../../shared/api/types'
import { formatQuantity } from '../../../shared/format'
import { ORDERS_PROCESSED, useLiveRefresh, VALIDATED_QUANTITY_EVENTS } from '../../../shared/realtime'
import { Badge } from '../../../shared/ui'
import { regionalQuotasKey } from './api'
import { distributionPath, formatDayMonth, formatTime, groupByFamily } from './format'

// Répartition en cours : pas encore envoyée aux clients, d'où l'ambre.
const STATUS_TONES: Record<RegionDayStatus, 'neutral' | 'success' | 'warning' | 'brand'> = {
  QUOTA_DRAFT: 'warning',
  QUOTA_PUBLISHED: 'brand',
  DISTRIBUTION_DRAFT: 'warning',
  DISTRIBUTION_PUBLISHED: 'success',
  CLOSED: 'neutral',
}

/** Statut d'une région pour la journée (§6.1) : à répartir, répartition en cours, publiée, clôturée. */
export function RegionStatusBadge({ status }: { status: RegionDayStatus }) {
  const { t } = useTranslation()
  return <Badge tone={STATUS_TONES[status]}>{t(`notifications.regionalQuotas.status.${status}`)}</Badge>
}

/**
 * Événements du canal de la région (§9.6) : publication (N-01) et
 * modification (N-02) des quotas ; envoi de la répartition aux clients et
 * premier brouillon de répartition (statut « Répartition en cours »), lot 4 ;
 * validation et annulation ADV, qui changent V(r,p) donc le reste R = Q − V, et
 * traitement ADV (lot 6, §7).
 */
const LIVE_EVENTS = ['QuotaPublished', 'DistributionPublished', 'LimitDraftsUpdated', ...VALIDATED_QUANTITY_EVENTS, ORDERS_PROCESSED] as const

/**
 * Abonnement au canal `region-day.{regionId}.{day}` : une publication ou un
 * envoi relit les quotas. Signale l'intervalle de rafraîchissement de
 * secours (30 s hors connexion temps réel) ; `null` au démontage.
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
 * comprise, RG-A05), état de la répartition avec l'accès à sa grille
 * (lot 4) et quotas publiés groupés par famille (§7.6).
 */
export function RegionQuotasCard({
  region,
  day,
  dayCutoffs,
}: {
  region: RegionalQuotaRegion
  day: string
  dayCutoffs: DayCutoffs | null
}) {
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
        <RegionStatusBadge status={region.status} />
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

      {published && <Distribution region={region} day={day} />}

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

const LINK_CLASSES =
  'inline-flex min-h-11 w-full shrink-0 items-center justify-center rounded-lg px-4 text-sm font-semibold transition-colors sm:w-auto ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'
const PRIMARY_LINK = 'bg-brand-600 text-white hover:bg-brand-700'
const SECONDARY_LINK = 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50'

/**
 * État de la répartition aux clients (§6.1, EF-REG-02) et accès à la grille,
 * une fois les quotas publiés : « Répartir » tant que rien n'est envoyé,
 * « Voir la répartition » après l'envoi (une nouvelle version reste
 * possible depuis la grille) ou sur une journée clôturée.
 */
function Distribution({ region, day }: { region: RegionalQuotaRegion; day: string }) {
  const { t } = useTranslation()
  const sent = region.distribution_version > 0
  const closed = region.status === 'CLOSED'
  const state = sent ? 'sent' : closed ? 'notSent' : region.status === 'DISTRIBUTION_DRAFT' ? 'draft' : 'toDistribute'
  const action = t(`notifications.regionalQuotas.distributionState.${sent || closed ? 'view' : 'distribute'}`)

  return (
    <div className="flex flex-col gap-2 rounded-lg bg-slate-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
      <p
        data-testid="region-distribution"
        className={`text-sm font-medium ${sent ? 'text-emerald-800' : closed ? 'text-slate-700' : 'text-amber-900'}`}
      >
        {t(`notifications.regionalQuotas.distributionState.${state}`, {
          version: region.distribution_version,
          time: region.cutoffs.distribution,
        })}
      </p>
      <Link
        to={distributionPath(day, region.region.id)}
        aria-label={t('notifications.regionalQuotas.distributionState.linkLabel', { action, name: region.region.name })}
        className={`${LINK_CLASSES} ${sent || closed ? SECONDARY_LINK : PRIMARY_LINK}`}
      >
        {action}
      </Link>
    </div>
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
                  <span>{t('notifications.regionalQuotas.validated', { quantity: formatQuantity(validated) })}</span>
                  {' · '}
                  <span data-testid="quota-remaining" className={quantity <= validated ? 'font-semibold text-red-800' : undefined}>
                    {quantity <= validated
                      ? t('orders.decision.quotas.soldOut')
                      : t('orders.decision.quotas.remaining', { quantity: formatQuantity(quantity - validated) })}
                  </span>
                </p>
              )}
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
