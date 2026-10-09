import { useTranslation } from 'react-i18next'
import type { Import, ImportStatus } from '../../../shared/api/types'
import { Badge } from '../../../shared/ui'
import { formatCount } from './values'

const STATUS_TONES = {
  PENDING: 'neutral',
  ANALYZING: 'brand',
  READY: 'warning',
  INVALID: 'danger',
  APPLIED: 'success',
  FAILED: 'danger',
} as const satisfies Record<ImportStatus, string>

export function ImportStatusBadge({ status }: { status: ImportStatus }) {
  const { t } = useTranslation()
  return <Badge tone={STATUS_TONES[status]}>{t(`backoffice.imports.status.${status}`)}</Badge>
}

/**
 * Progression de l'analyse (§8.1, étape 2) : lignes traitées sur le total ;
 * barre indéterminée tant que le total n'est pas connu.
 */
export function ImportProgress({ item }: { item: Pick<Import, 'status' | 'rows_processed' | 'rows_total'> }) {
  const { t } = useTranslation()
  const known = item.status === 'ANALYZING' && item.rows_total > 0
  const percent = known ? Math.min(100, Math.round((item.rows_processed / item.rows_total) * 100)) : 0
  const label = known
    ? t('backoffice.imports.detail.analyzing', { processed: formatCount(item.rows_processed), total: formatCount(item.rows_total) })
    : t('backoffice.imports.detail.pending')

  return (
    <div className="flex flex-col gap-2">
      <div
        role="progressbar"
        aria-label={t('backoffice.imports.detail.progress')}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={known ? percent : undefined}
        aria-valuetext={label}
        className="h-3 w-full overflow-hidden rounded-full bg-slate-200"
      >
        <div
          className={`h-full rounded-full bg-brand-600 transition-[width] duration-500 ${known ? '' : 'w-1/3 animate-pulse'}`}
          style={known ? { width: `${percent}%` } : undefined}
        />
      </div>
      <p role="status" className="text-sm text-slate-700">
        {label}
      </p>
    </div>
  )
}

/** Compteurs complets de l'analyse (lignes lues, correctes, en erreur, créations…). */
export function CountTiles({ tiles }: { tiles: Array<{ label: string; value: number; tone?: 'danger' | 'success' }> }) {
  return (
    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      {tiles.map((tile) => (
        <div key={tile.label} className="rounded-lg bg-slate-50 px-3 py-2">
          <dt className="text-xs text-slate-500">{tile.label}</dt>
          <dd
            className={`text-lg font-semibold tabular-nums ${
              tile.tone === 'danger' ? 'text-red-700' : tile.tone === 'success' ? 'text-emerald-700' : 'text-slate-900'
            }`}
          >
            {formatCount(tile.value)}
          </dd>
        </div>
      ))}
    </dl>
  )
}
