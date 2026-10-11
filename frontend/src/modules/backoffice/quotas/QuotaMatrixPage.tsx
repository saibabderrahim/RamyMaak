import { useMutation } from '@tanstack/react-query'
import { useCallback, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { ApiError } from '../../../shared/api/client'
import type { QuotaViolation } from '../../../shared/api/types'
import { formatDate } from '../../../shared/format'
import { useLiveRefresh } from '../../../shared/realtime'
import { Alert, Badge, Button, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useBackoffice } from '../shared/access'
import { EmptyState } from '../shared/ui'
import { downloadQuotaTemplate, MATRIX_EVENTS, matrixKey, useQuotaMatrix } from './api'
import { MatrixLegend, SaveIndicator } from './components'
import { CopyDialog } from './CopyDialog'
import type { CellChange } from './drafts'
import { PublishDialog } from './PublishDialog'
import { QuotaGrid } from './QuotaGrid'
import { useDraftQueue } from './useDraftQueue'

/**
 * Matrice des quotas d'une journée (EF-ADV-02, §5.2) : produits par
 * famille en lignes, régions en colonnes, saisie comme dans un tableur,
 * enregistrement automatique en brouillon (RG-Q03), publication, copie
 * depuis une autre journée, import Excel (§8.1). Tenue à jour en temps réel
 * (canal `adv.{day}`, §9.6). Lecture seule sans quota.edit (Admin) ou une
 * fois la journée clôturée.
 */
export default function QuotaMatrixPage() {
  const day = useParams().day ?? ''
  // Une autre journée : écran neuf (file d'enregistrement, cellule active).
  return <QuotaMatrixScreen key={day} day={day} />
}

type Highlights = ReadonlyMap<number, ReadonlySet<number>>

const NO_HIGHLIGHTS: Highlights = new Map()

/** Cellules refusées à la publication, par produit puis par région. */
function highlightsOf(violations: readonly QuotaViolation[]): Highlights {
  const map = new Map<number, Set<number>>()
  for (const violation of violations) {
    map.set(violation.product_id, (map.get(violation.product_id) ?? new Set()).add(violation.region_id))
  }
  return map
}

/** Une cellule ressaisie n'est plus signalée. */
function withoutCells(highlights: Highlights, changes: readonly CellChange[]): Highlights {
  if (!changes.some(({ productId, regionId }) => highlights.get(productId)?.has(regionId))) return highlights
  const next = new Map(highlights)
  for (const { productId, regionId } of changes) {
    const regions = next.get(productId)
    if (!regions?.has(regionId)) continue
    const rest = new Set(regions)
    rest.delete(regionId)
    if (rest.size > 0) next.set(productId, rest)
    else next.delete(productId)
  }
  return next
}

function QuotaMatrixScreen({ day }: { day: string }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { base, can } = useBackoffice()
  const helpId = useId()

  const refetchInterval = useLiveRefresh(`adv.${day}`, MATRIX_EVENTS, [matrixKey(day)])
  const matrix = useQuotaMatrix(day, refetchInterval)
  const { queue, snapshot } = useDraftQueue(day)
  const template = useMutation({ mutationFn: () => downloadQuotaTemplate(day) })

  const [dialog, setDialog] = useState<'publish' | 'copy' | null>(null)
  const [highlights, setHighlights] = useState<Highlights>(NO_HIGHLIGHTS)
  const [notice, setNotice] = useState<string | null>(null)

  const onEdit = useCallback(
    (changes: CellChange[]) => {
      queue.edit(changes)
      setHighlights((current) => withoutCells(current, changes))
    },
    [queue],
  )
  const onRevert = useCallback((productId: number, regionId: number) => queue.revert(productId, regionId), [queue])
  const flush = useCallback(() => queue.flushAll(), [queue])

  if (matrix.isPending) return <Spinner />

  if (matrix.error) {
    if (matrix.error instanceof ApiError && matrix.error.status === 404) {
      return (
        <>
          <PageHeader title={t('backoffice.quotas.notOpen.title')} />
          <Card>
            <p className="text-sm text-slate-700">{t('backoffice.quotas.notOpen.body', { date: formatDate(day) })}</p>
            <Link to={`${base}/journees`} className="mt-4 inline-flex min-h-11 items-center font-semibold text-brand-700 hover:underline">
              {t('backoffice.quotas.notOpen.link')}
            </Link>
          </Card>
        </>
      )
    }
    return <ErrorAlert error={matrix.error} />
  }

  const data = matrix.data
  const closed = data.day.status === 'CLOSED' || snapshot.closed
  const canEdit = can('quota.edit') && !closed
  const canPublish = can('quota.publish') && !closed
  const date = formatDate(day)

  const openDialog = (name: 'publish' | 'copy') => {
    setNotice(null)
    setDialog(name)
  }
  const closeDialog = () => setDialog(null)

  return (
    <>
      <PageHeader
        title={t('backoffice.quotas.pageTitle', { date })}
        actions={
          <Link to={`${base}/journees/${day}`} className="inline-flex min-h-11 items-center text-sm text-brand-700 hover:underline">
            {t('backoffice.quotas.backToDay', { date })}
          </Link>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-700">
        <Badge tone={data.day.status === 'OPEN' ? 'success' : 'neutral'}>{data.day.status_label}</Badge>
        <span>{t('backoffice.quotas.delivery', { date: formatDate(data.day.delivery_date) })}</span>
        <span>{t('backoffice.quotas.distributionCutoff', { time: data.day.cutoffs.distribution })}</span>
      </div>

      {(canPublish || canEdit) && (
        <div role="group" aria-label={t('backoffice.quotas.actions.label')} className="mb-4 flex flex-wrap gap-2">
          {canPublish && <Button onClick={() => openDialog('publish')}>{t('backoffice.quotas.actions.publish')}</Button>}
          {canEdit && (
            <>
              <Button variant="secondary" onClick={() => openDialog('copy')}>
                {t('backoffice.quotas.actions.copy')}
              </Button>
              <Button variant="secondary" onClick={() => navigate(`${base}/imports?type=QUOTAS&day=${day}`)}>
                {t('backoffice.quotas.actions.import')}
              </Button>
              <Button variant="secondary" onClick={() => template.mutate()} disabled={template.isPending}>
                {template.isPending ? t('backoffice.quotas.actions.templateLoading') : t('backoffice.quotas.actions.template')}
              </Button>
            </>
          )}
        </div>
      )}

      <ErrorAlert error={template.error} className="mb-4" />
      {notice && (
        <Alert tone="success" className="mb-4">
          {notice}
        </Alert>
      )}
      {snapshot.closed ? (
        <Alert tone="warning" className="mb-4">
          {t('backoffice.quotas.readOnly.closedWhileEditing')}
        </Alert>
      ) : closed ? (
        <Alert className="mb-4">{t('backoffice.quotas.readOnly.closed')}</Alert>
      ) : (
        !can('quota.edit') && <Alert className="mb-4">{t('backoffice.quotas.readOnly.permission')}</Alert>
      )}

      <div className="mb-2 flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
        <MatrixLegend />
        {canEdit && <SaveIndicator snapshot={snapshot} onRetry={() => queue.retry()} />}
      </div>
      <p id={helpId} className="mb-3 text-xs text-slate-600">
        {canEdit ? t('backoffice.quotas.help') : t('backoffice.quotas.helpReadOnly')}
      </p>

      {data.regions.length === 0 ? (
        <Card>
          <EmptyState>{t('backoffice.quotas.grid.noRegions')}</EmptyState>
        </Card>
      ) : data.products.length === 0 ? (
        <Card>
          <EmptyState>{t('backoffice.quotas.grid.noProducts')}</EmptyState>
        </Card>
      ) : (
        <QuotaGrid
          matrix={data}
          edits={snapshot.edits}
          readOnly={!canEdit}
          highlights={highlights}
          onEdit={onEdit}
          onRevert={onRevert}
          label={t('backoffice.quotas.grid.label', { date })}
          describedBy={helpId}
        />
      )}

      {dialog === 'publish' && (
        <PublishDialog
          day={day}
          regions={data.regions}
          invalid={snapshot.invalid}
          flush={flush}
          onViolations={(violations) => setHighlights(highlightsOf(violations))}
          onClose={closeDialog}
        />
      )}
      {dialog === 'copy' && (
        <CopyDialog
          day={day}
          regions={data.regions}
          flush={flush}
          onClose={closeDialog}
          onDone={(changed, sourceDay) => {
            setDialog(null)
            setNotice(
              changed > 0
                ? t('backoffice.quotas.copy.done', { count: changed, date: formatDate(sourceDay) })
                : t('backoffice.quotas.copy.nothing', { date: formatDate(sourceDay) }),
            )
          }}
        />
      )}
    </>
  )
}
