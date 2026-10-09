import { type ReactNode, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import type { Import, ImportPreviewRow } from '../../../shared/api/types'
import { formatDateTime } from '../../../shared/format'
import { Alert, Badge, Button, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useBackoffice } from '../shared/access'
import { ConfirmDialog } from '../shared/ui'
import { errorReportUrl, IMPORT_PERMISSIONS, IN_PROGRESS, useApplyImport, useImport } from './api'
import { CountTiles, ImportProgress, ImportStatusBadge } from './components'
import { formatChangeValue, formatCount } from './values'

/**
 * Suivi d'un import (§8.1, étapes 2 à 5) : progression interrogée chaque
 * seconde pendant l'analyse ; rapport d'erreurs et fichier annoté (aucune
 * application possible) ; ou aperçu des changements et application tout
 * ou rien après confirmation.
 */
export default function ImportDetailPage() {
  const { t } = useTranslation()
  const id = Number(useParams().id)
  const { base, can } = useBackoffice()
  const query = useImport(id)
  const apply = useApplyImport(id)
  const [confirming, setConfirming] = useState(false)

  const back = (
    <Link to={`${base}/imports`} className="text-sm text-brand-700 hover:underline">
      {t('common.back')}
    </Link>
  )

  if (!Number.isInteger(id) || id <= 0) return <Alert tone="warning">{t('backoffice.imports.detail.notFound')}</Alert>
  if (query.isPending) return <Spinner />
  if (query.error) {
    return (
      <>
        <PageHeader title={t('backoffice.imports.title')} actions={back} />
        <ErrorAlert error={query.error} />
      </>
    )
  }

  const item = query.data
  const summary = item.summary ?? { create: 0, update: 0, unchanged: 0 }
  const canApply = can(IMPORT_PERMISSIONS[item.type])

  // Refus du serveur (erreurs apparues entre-temps) : affiché sur la page, qui relit l'import.
  const confirmApply = () => apply.mutate(undefined, { onSettled: () => setConfirming(false) })

  const newUpload = (
    <Link to={`${base}/imports`} className="text-sm font-medium text-brand-700 hover:underline">
      {t('backoffice.imports.detail.newUpload')}
    </Link>
  )

  return (
    <>
      <PageHeader title={t('backoffice.imports.detail.title', { id: item.id, type: item.type_label })} actions={back} />

      <div className="flex flex-col gap-6">
        <Card>
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <Fact label={t('backoffice.imports.columns.status')}>
              <ImportStatusBadge status={item.status} />
            </Fact>
            <Fact label={t('backoffice.imports.detail.file')}>{item.original_name}</Fact>
            <Fact label={t('backoffice.imports.detail.user')}>{item.user?.name ?? t('common.none')}</Fact>
            <Fact label={t('backoffice.imports.detail.createdAt')}>{formatDateTime(item.created_at)}</Fact>
            <Fact label={t('backoffice.imports.detail.analyzedAt')}>{formatDateTime(item.analyzed_at)}</Fact>
            <Fact label={t('backoffice.imports.detail.appliedAt')}>{formatDateTime(item.applied_at)}</Fact>
          </dl>
        </Card>

        <ErrorAlert error={apply.error} />

        {IN_PROGRESS.includes(item.status) && (
          <Card>
            <ImportProgress item={item} />
          </Card>
        )}

        {item.status === 'FAILED' && (
          <div className="flex flex-col gap-3">
            <Alert tone="error">
              {item.failure_message
                ? t('backoffice.imports.detail.failed', { message: item.failure_message })
                : t('backoffice.imports.detail.failedDefault')}
            </Alert>
            {newUpload}
          </div>
        )}

        {item.status === 'INVALID' && (
          <>
            <Alert tone="error">{t('backoffice.imports.detail.invalid', { count: item.rows_error })}</Alert>
            <Counters item={item} />
            <ErrorsCard item={item} />
            {newUpload}
          </>
        )}

        {item.status === 'READY' && (
          <>
            <Alert>{t('backoffice.imports.detail.ready')}</Alert>
            <Counters item={item} />
            <PreviewCard
              item={item}
              actions={
                canApply ? (
                  <Button onClick={() => setConfirming(true)} disabled={apply.isPending}>
                    {apply.isPending ? t('backoffice.imports.detail.applying') : t('backoffice.imports.detail.apply')}
                  </Button>
                ) : undefined
              }
            />
            {!canApply && <Alert tone="warning">{t('backoffice.imports.detail.noApplyRight')}</Alert>}
            <ConfirmDialog
              open={confirming}
              title={t('backoffice.imports.detail.applyTitle')}
              confirmLabel={t('backoffice.imports.detail.apply')}
              pending={apply.isPending}
              onConfirm={confirmApply}
              onCancel={() => setConfirming(false)}
            >
              <p>
                {t('backoffice.imports.detail.applyConfirm', { create: formatCount(summary.create), update: formatCount(summary.update) })}
              </p>
            </ConfirmDialog>
          </>
        )}

        {item.status === 'APPLIED' && (
          <>
            <Alert tone="success">
              {t('backoffice.imports.detail.applied', {
                date: formatDateTime(item.applied_at),
                create: formatCount(summary.create),
                update: formatCount(summary.update),
              })}
            </Alert>
            <Counters item={item} />
            <PreviewCard item={item} />
          </>
        )}
      </div>
    </>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col">
      <dt className="text-slate-500">{label}</dt>
      <dd className="font-medium break-words text-slate-900">{children}</dd>
    </div>
  )
}

/** Compteurs complets, même quand erreurs et aperçu sont tronqués. */
function Counters({ item }: { item: Import }) {
  const { t } = useTranslation()
  const tiles: Parameters<typeof CountTiles>[0]['tiles'] = [
    { label: t('backoffice.imports.detail.counts.total'), value: item.rows_total },
    { label: t('backoffice.imports.detail.counts.ok'), value: item.rows_ok, tone: 'success' },
    { label: t('backoffice.imports.detail.counts.errors'), value: item.rows_error, tone: item.rows_error > 0 ? 'danger' : undefined },
  ]
  if (item.summary) {
    tiles.push(
      { label: t('backoffice.imports.detail.summary.create'), value: item.summary.create },
      { label: t('backoffice.imports.detail.summary.update'), value: item.summary.update },
      { label: t('backoffice.imports.detail.summary.unchanged'), value: item.summary.unchanged },
    )
  }
  return (
    <Card>
      <CountTiles tiles={tiles} />
    </Card>
  )
}

/** Rapport d'erreurs par ligne et par colonne, et fichier d'origine annoté. */
function ErrorsCard({ item }: { item: Import }) {
  const { t } = useTranslation()
  return (
    <Card
      title={t('backoffice.imports.detail.errors')}
      actions={
        item.has_error_report ? (
          <a
            href={errorReportUrl(item.id)}
            download
            className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-brand-700 hover:bg-slate-50"
          >
            {t('backoffice.imports.detail.errorReport')}
          </a>
        ) : undefined
      }
    >
      {item.errors_truncated && (
        <Alert tone="warning" className="mb-4">
          {t('backoffice.imports.detail.errorsTruncated')}
        </Alert>
      )}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[560px] text-start text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.detail.errorColumns.row')}</th>
              <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.detail.errorColumns.column')}</th>
              <th className="py-2 text-start font-medium">{t('backoffice.imports.detail.errorColumns.message')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100" data-testid="import-errors">
            {item.errors.map((error, index) => (
              <tr key={`${error.row ?? 'x'}-${error.column ?? 'x'}-${index}`}>
                <td className="py-2 pe-4 tabular-nums">{error.row ?? t('common.none')}</td>
                <td className="py-2 pe-4 font-mono text-xs">{error.column ?? t('common.none')}</td>
                <td className="py-2">{error.message}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  )
}

/** Aperçu des changements : valeur actuelle → nouvelle valeur (§8.1, étape 3). */
function PreviewCard({ item, actions }: { item: Import; actions?: ReactNode }) {
  const { t } = useTranslation()
  return (
    <Card title={t('backoffice.imports.detail.preview')} actions={actions}>
      {item.preview_truncated && (
        <Alert tone="warning" className="mb-4">
          {t('backoffice.imports.detail.previewTruncated')}
        </Alert>
      )}
      {item.preview.length === 0 ? (
        <p className="py-4 text-sm text-slate-500">{t('backoffice.imports.detail.previewEmpty')}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-start text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
              <tr>
                <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.detail.previewColumns.row')}</th>
                <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.detail.previewColumns.key')}</th>
                <th className="py-2 pe-4 text-start font-medium">{t('backoffice.imports.detail.previewColumns.action')}</th>
                <th className="py-2 text-start font-medium">{t('backoffice.imports.detail.previewColumns.changes')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100" data-testid="import-preview">
              {item.preview.map((row) => (
                <tr key={`${row.row}-${row.key}`} className="align-top">
                  <td className="py-2 pe-4 tabular-nums">{row.row}</td>
                  <td className="py-2 pe-4 font-mono text-xs">{row.key}</td>
                  <td className="py-2 pe-4">
                    <Badge tone={row.action === 'create' ? 'success' : 'brand'}>{t(`backoffice.imports.detail.actions.${row.action}`)}</Badge>
                  </td>
                  <td className="py-2">
                    <ChangeList type={item.type} row={row} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

function ChangeList({ type, row }: { type: Import['type']; row: ImportPreviewRow }) {
  const { t } = useTranslation()
  const fieldLabels: Partial<Record<string, string>> = t(`backoffice.imports.fields.${type}`, { returnObjects: true })
  const labels = { empty: t('backoffice.imports.detail.emptyValue'), yes: t('common.yes'), no: t('common.no') }

  return (
    <ul className="flex flex-col gap-0.5">
      {Object.entries(row.changes).map(([field, change]) => (
        <li key={field}>
          <span className="font-medium text-slate-700">{fieldLabels[field] ?? field}</span>
          {' : '}
          {row.action === 'update' && (
            <>
              <span className="text-slate-500">{formatChangeValue(field, change.old, labels)}</span>
              {' → '}
            </>
          )}
          <span className="font-medium text-slate-900">{formatChangeValue(field, change.new, labels)}</span>
        </li>
      ))}
    </ul>
  )
}
