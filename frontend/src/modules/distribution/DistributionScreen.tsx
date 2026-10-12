import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../shared/api/client'
import type { DistributionGrid as Grid, DistributionResult, PublicationPreview } from '../../shared/api/types'
import { formatDate } from '../../shared/format'
import { useOnline } from '../../shared/pwa/online'
import { useLiveRefresh } from '../../shared/realtime'
import { Alert, Button, Card, ErrorAlert, Spinner } from '../../shared/ui'
import { useErrorMessage } from '../../shared/ui/useErrorMessage'
import { GRID_EVENTS, gridKey, regionsKey, useDistributionGrid } from './api'
import { GridHeader, SaveIndicator } from './components'
import { CopyPreviousDialog } from './CopyPreviousDialog'
import { Dialog } from './Dialog'
import { DistributionWorkspace } from './DistributionWorkspace'
import type { DraftQueue, DraftSnapshot } from './drafts'
import { useLimitChanges, useWideScreen } from './hooks'
import {
  type CellChange,
  formatDayMonth,
  formatTime,
  highlightsOf,
  type Highlights,
  NO_HIGHLIGHTS,
  revertChanges,
  summarize,
  withoutCells,
} from './model'
import { PhoneDistribution } from './PhoneDistribution'
import { PublishDialog } from './PublishDialog'
import { useDraftQueue } from './useDraftQueue'

/**
 * Répartition d'une région pour une journée (EF-REG-02, EF-REG-03) : grille
 * PC à partir de 768 px, vue pivot en dessous, mêmes données et même file
 * d'enregistrement. Tenue à jour en temps réel (canal
 * `region-day.{regionId}.{day}`, §9.6 ; sinon toutes les 30 s) sans jamais
 * écraser une saisie en attente. Lecture seule sans `can_edit`, une fois la
 * journée ou la région clôturée, et hors ligne (§7.6).
 */
export function DistributionScreen({ day, regionId }: { day: string; regionId: number }) {
  const { t } = useTranslation()
  const errorMessage = useErrorMessage()
  const refetchInterval = useLiveRefresh(`region-day.${regionId}.${day}`, GRID_EVENTS, [gridKey(day, regionId), regionsKey(day)])
  const query = useDistributionGrid(day, regionId, refetchInterval)
  const { queue, snapshot } = useDraftQueue(day, regionId)

  if (query.isPending) return <Spinner />

  // Premier chargement en échec : rien à afficher. Une relecture en échec
  // garde au contraire la dernière grille reçue, la saisie en cours et la file.
  const grid = query.data
  if (!grid) {
    if (query.error instanceof ApiError && query.error.status === 404) {
      return <Alert tone="warning">{t('distribution.page.notFound')}</Alert>
    }
    return (
      <div className="flex flex-col gap-3">
        <ErrorAlert error={query.error} />
        <Button variant="secondary" className="self-start" onClick={() => void query.refetch()}>
          {t('common.retry')}
        </Button>
      </div>
    )
  }

  return (
    <>
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
      <DistributionEditor
        day={day}
        regionId={regionId}
        grid={grid}
        dataUpdatedAt={query.dataUpdatedAt}
        refetch={async () => {
          const result = await query.refetch()
          return result.error ? undefined : result.data
        }}
        queue={queue}
        snapshot={snapshot}
      />
    </>
  )
}

type EditorProps = {
  day: string
  regionId: number
  grid: Grid
  dataUpdatedAt: number
  /** Relecture de la grille ; undefined si elle échoue. */
  refetch: () => Promise<Grid | undefined>
  queue: DraftQueue
  snapshot: DraftSnapshot
}

type DialogState = { kind: 'copy' } | { kind: 'revert'; count: number } | { kind: 'publish'; preview: PublicationPreview } | null

type Notice = { tone: 'success' | 'info' | 'error'; text: string } | null

function DistributionEditor({ day, regionId, grid, dataUpdatedAt, refetch, queue, snapshot }: EditorProps) {
  const { t } = useTranslation()
  const errorMessage = useErrorMessage()
  const online = useOnline()
  const wide = useWideScreen()
  const { edits } = snapshot
  const [highlights, setHighlights] = useState<Highlights>(NO_HIGHLIGHTS)
  const [dialog, setDialog] = useState<DialogState>(null)
  const [notice, setNotice] = useState<Notice>(null)
  const [preparing, setPreparing] = useState(false)

  const summaries = useMemo(() => summarize(grid, edits), [grid, edits])
  const makeChange = useLimitChanges(grid, edits)
  const onEdit = useCallback(
    (changes: CellChange[]) => {
      queue.edit(changes)
      setHighlights((current) => withoutCells(current, changes))
    },
    [queue],
  )
  const onRevert = useCallback((clientId: number, productId: number) => queue.revert(clientId, productId), [queue])
  const flush = useCallback(() => queue.flushAll(), [queue])

  // Retour du réseau : les bornes dont l'envoi a échoué repartent d'elles-mêmes
  // (une seule fois ; un échec du serveur attend « Réessayer »).
  const wasOnline = useRef(online)
  useEffect(() => {
    if (online && !wasOnline.current && queue.getSnapshot().status === 'error') queue.retry()
    wasOnline.current = online
  }, [online, queue])

  const closed = grid.day.status === 'CLOSED' || grid.region.status === 'CLOSED'
  const readOnly = !grid.can_edit || snapshot.closed || !online
  const canPublish = grid.can_publish && !snapshot.closed && online
  const reverts = useMemo(() => (readOnly ? [] : revertChanges(grid, edits)), [readOnly, grid, edits])
  const nothingToSend = !grid.publication.first && grid.publication.drafts === 0 && snapshot.unsaved === 0 && reverts.length === 0

  const label = t('distribution.grid.label', { region: grid.region.name, date: formatDate(day) })

  /**
   * Avant l'envoi : file vidée, grille relue, récapitulatif à jour ; rien à
   * envoyer après relecture (brouillons revenus à la valeur envoyée) : pas
   * de dialogue.
   */
  const preparePublish = async () => {
    setNotice(null)
    setPreparing(true)
    try {
      await queue.flushAll()
    } catch (error) {
      setNotice({ tone: 'error', text: error instanceof ApiError ? errorMessage(error) : t('distribution.flushFailed') })
      setPreparing(false)
      return
    }
    const fresh = await refetch()
    setPreparing(false)
    if (!fresh) {
      setNotice({ tone: 'error', text: t('distribution.publish.refreshFailed') })
      return
    }
    if (!fresh.publication.first && fresh.publication.drafts === 0) {
      setNotice({ tone: 'info', text: t('distribution.publish.nothing') })
      return
    }
    setDialog({ kind: 'publish', preview: fresh.publication })
  }

  const onPublished = (result: DistributionResult) => {
    setDialog(null)
    setHighlights(NO_HIGHLIGHTS)
    setNotice({ tone: 'success', text: t('distribution.publish.done', { version: result.version, count: result.notified_clients }) })
  }

  const waiting = grid.products.length === 0

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
        <GridHeader grid={grid} />
        {/* Hors ligne aussi : les saisies non enregistrées restent signalées. */}
        {grid.can_edit && !snapshot.closed && <SaveIndicator snapshot={snapshot} onRetry={() => queue.retry()} />}
      </div>

      {!online && (
        <Alert tone="warning">
          <p className="font-semibold">{t('distribution.offline.title', { time: formatTime(dataUpdatedAt) })}</p>
          <p>{t('distribution.offline.hint')}</p>
        </Alert>
      )}
      {snapshot.closed ? (
        <Alert tone="warning">{t('distribution.readOnly.closedWhileEditing')}</Alert>
      ) : closed ? (
        <Alert>{t('distribution.readOnly.closed')}</Alert>
      ) : (
        !grid.can_edit && !waiting && <Alert>{t('distribution.readOnly.permission')}</Alert>
      )}

      {waiting ? (
        <Card>
          {grid.region.status === 'QUOTA_DRAFT' ? (
            <div className="flex flex-col gap-1 text-sm text-slate-700">
              <p className="text-base font-semibold text-slate-900">{t('distribution.waiting.title')}</p>
              <p>{t('distribution.waiting.hint')}</p>
            </div>
          ) : (
            <p className="text-sm text-slate-700">{t('distribution.waiting.noProducts')}</p>
          )}
        </Card>
      ) : grid.clients.length === 0 ? (
        <Card>
          <p className="text-sm text-slate-700">{t('distribution.noClients')}</p>
        </Card>
      ) : (
        <>
          {(!readOnly || canPublish) && (
            <div role="group" aria-label={t('distribution.actions.label')} className="flex flex-wrap gap-2">
              {canPublish && (
                <Button onClick={() => void preparePublish()} disabled={preparing || nothingToSend}>
                  {preparing ? t('distribution.actions.preparing') : t('distribution.actions.publish')}
                </Button>
              )}
              {!readOnly && (
                <>
                  <Button variant="secondary" onClick={() => setDialog({ kind: 'copy' })}>
                    {t('distribution.actions.copy')}
                  </Button>
                  <Button variant="secondary" onClick={() => setDialog({ kind: 'revert', count: reverts.length })} disabled={reverts.length === 0}>
                    {t('distribution.actions.revert')}
                  </Button>
                </>
              )}
            </div>
          )}
          {canPublish && nothingToSend && <p className="text-xs text-slate-600">{t('distribution.actions.nothingToSend')}</p>}

          {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

          {wide ? (
            <DistributionWorkspace
              grid={grid}
              edits={edits}
              summaries={summaries}
              readOnly={readOnly}
              highlights={highlights}
              onEdit={onEdit}
              onRevert={onRevert}
              makeChange={makeChange}
              label={label}
            />
          ) : (
            <PhoneDistribution grid={grid} edits={edits} summaries={summaries} readOnly={readOnly} highlights={highlights} onEdit={onEdit} makeChange={makeChange} />
          )}
        </>
      )}

      {dialog?.kind === 'copy' && (
        <CopyPreviousDialog
          day={day}
          regionId={regionId}
          flush={flush}
          onClose={() => setDialog(null)}
          onDone={(result) => {
            setDialog(null)
            setNotice({
              tone: result.changed > 0 ? 'success' : 'info',
              text:
                result.changed > 0
                  ? t('distribution.copy.done', { count: result.changed, date: formatDayMonth(result.source_day) })
                  : t('distribution.copy.nothing', { date: formatDayMonth(result.source_day) }),
            })
          }}
        />
      )}
      {dialog?.kind === 'revert' && (
        <Dialog title={t('distribution.revert.title')} onClose={() => setDialog(null)}>
          <div className="flex flex-col gap-4 text-sm text-slate-700">
            <p>{t('distribution.revert.body', { count: dialog.count })}</p>
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="secondary" onClick={() => setDialog(null)} data-autofocus>
                {t('common.cancel')}
              </Button>
              <Button
                variant="danger"
                onClick={() => {
                  onEdit(revertChanges(grid, edits))
                  setDialog(null)
                  setNotice({ tone: 'info', text: t('distribution.revert.done', { count: dialog.count }) })
                }}
              >
                {t('distribution.revert.confirm')}
              </Button>
            </div>
          </div>
        </Dialog>
      )}
      {dialog?.kind === 'publish' && (
        <PublishDialog
          day={day}
          regionId={regionId}
          preview={dialog.preview}
          policy={grid.policy}
          invalid={snapshot.invalid}
          onViolations={(violations) => setHighlights(violations.length > 0 ? highlightsOf(violations) : NO_HIGHLIGHTS)}
          onPublished={onPublished}
          onClose={() => setDialog(null)}
        />
      )}
    </div>
  )
}
