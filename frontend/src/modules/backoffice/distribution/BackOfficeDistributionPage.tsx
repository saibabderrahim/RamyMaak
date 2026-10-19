import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { ApiError } from '../../../shared/api/client'
import type { DistributionGrid } from '../../../shared/api/types'
import { formatDate } from '../../../shared/format'
import { useLiveRefresh } from '../../../shared/realtime'
import { Alert, Button, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useErrorMessage } from '../../../shared/ui/useErrorMessage'
import { backOfficeGridKey, useBackOfficeGrid } from '../../distribution/api'
import { GridHeader } from '../../distribution/components'
import { DistributionWorkspace } from '../../distribution/DistributionWorkspace'
import { type EditsByClient, formatQuantity, NO_HIGHLIGHTS, summarize } from '../../distribution/model'
import { useBackoffice } from '../shared/access'

// Événements de la journée (`adv.{day}`) et de la région (`region-day.{regionId}.{day}`, brouillons du régional).
const DAY_EVENTS = ['DistributionPublished', 'QuotaPublished', 'DayClosed'] as const
// S relu en direct sur les événements de commande (contrat du lot 5, §5).
const REGION_EVENTS = ['LimitDraftsUpdated', 'OrderSubmitted', 'OrderUpdated', 'OrderCancelled', 'OrdersExpired'] as const

const NO_EDITS: EditsByClient = new Map()
const noop = () => undefined
const noChange = () => null

/**
 * Répartition d'une région en lecture seule pour l'ADV et l'Admin :
 * `/adv|admin/journees/:day/repartition/:regionId`. Même grille que le
 * régional (modes Bornes, Commandes, Reste, recherche, catégorie, synthèse,
 * surallocations), sans saisie ni actions ; récapitulatif de ce qui reste à
 * envoyer aux clients. Tenue à jour en temps réel (§9.6).
 * Contrat : docs/lot-4-contrat-api.md §3 et §5.
 */
export default function BackOfficeDistributionPage() {
  const params = useParams()
  const day = params.day ?? ''
  const regionId = Number(params.regionId)
  // Autre journée ou autre région : écran neuf (sélection, filtres).
  return <BackOfficeDistributionScreen key={`${day}:${regionId}`} day={day} regionId={regionId} />
}

function BackOfficeDistributionScreen({ day, regionId }: { day: string; regionId: number }) {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const errorMessage = useErrorMessage()
  const key = backOfficeGridKey(day, regionId)
  const refetchInterval = useLiveRefresh(`adv.${day}`, DAY_EVENTS, [key])
  // L'ADV et l'Admin sont autorisés sur le canal de la région : brouillons du régional.
  useLiveRefresh(Number.isInteger(regionId) ? `region-day.${regionId}.${day}` : null, REGION_EVENTS, [key])
  const query = useBackOfficeGrid(day, regionId, refetchInterval)
  const date = formatDate(day)

  const back = (
    <Link to={`${base}/journees/${day}`} className="inline-flex min-h-11 items-center text-sm text-brand-700 hover:underline">
      {t('distribution.backOffice.back', { date })}
    </Link>
  )

  if (query.isPending) return <Spinner />

  const grid = query.data
  if (!grid) {
    if (query.error instanceof ApiError && query.error.status === 404) {
      return (
        <>
          <PageHeader title={t('distribution.backOffice.notFound')} />
          <Card>
            <p className="text-sm text-slate-700">{t('distribution.backOffice.notFoundHint')}</p>
            <div className="mt-2">{back}</div>
          </Card>
        </>
      )
    }
    return (
      <>
        <PageHeader title={t('distribution.title')} actions={back} />
        <ErrorAlert error={query.error} />
      </>
    )
  }

  return (
    <>
      <PageHeader title={t('distribution.backOffice.title', { region: grid.region.name, date })} actions={back} />
      {query.isRefetchError && (
        <Alert tone="warning" className="mb-4">
          <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <p>{t('distribution.refreshFailed', { reason: errorMessage(query.error) })}</p>
            <Button variant="secondary" onClick={() => void query.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        </Alert>
      )}
      <ReadOnlyDistribution grid={grid} />
    </>
  )
}

function ReadOnlyDistribution({ grid }: { grid: DistributionGrid }) {
  const { t } = useTranslation()
  const summaries = useMemo(() => summarize(grid, NO_EDITS), [grid])
  const { publication } = grid

  return (
    <div className="flex flex-col gap-4">
      <GridHeader grid={grid} />

      <section aria-label={t('distribution.backOffice.recap')} className="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700">
        <p className="font-semibold text-slate-900">
          {grid.region.distribution_version > 0 ? t('distribution.backOffice.sent', { version: grid.region.distribution_version }) : t('distribution.backOffice.notSent')}
        </p>
        <p>{publication.drafts > 0 ? t('distribution.backOffice.drafts', { count: publication.drafts }) : t('distribution.backOffice.noDrafts')}</p>
        {publication.overallocated.length > 0 && (
          <div className="text-orange-900">
            <p className="font-semibold">{t('distribution.publish.overallocatedTitle')}</p>
            <ul className="list-disc ps-5">
              {publication.overallocated.map((item) => (
                <li key={item.product_id}>
                  {t('distribution.publish.overallocated', { sku: item.sku, sum: formatQuantity(item.sum_limits), quota: formatQuantity(item.quota) })}
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>

      {grid.products.length === 0 ? (
        <Card>
          <p className="text-sm text-slate-700">{grid.region.status === 'QUOTA_DRAFT' ? t('distribution.backOffice.waiting') : t('distribution.waiting.noProducts')}</p>
        </Card>
      ) : grid.clients.length === 0 ? (
        <Card>
          <p className="text-sm text-slate-700">{t('distribution.noClients')}</p>
        </Card>
      ) : (
        <DistributionWorkspace
          grid={grid}
          edits={NO_EDITS}
          summaries={summaries}
          readOnly
          highlights={NO_HIGHLIGHTS}
          onEdit={noop}
          onRevert={noop}
          makeChange={noChange}
          label={t('distribution.grid.label', { region: grid.region.name, date: formatDate(grid.day.day) })}
        />
      )}
    </div>
  )
}
