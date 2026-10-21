import type { ComponentProps } from 'react'
import { useTranslation } from 'react-i18next'
import type { RegionDayStatus } from '../../../shared/api/types'
import { Badge, Button } from '../../../shared/ui'
import { useErrorMessage } from '../../../shared/ui/useErrorMessage'
import type { DraftSnapshot } from './drafts'
import { formatTime } from './matrix'

type Tone = ComponentProps<typeof Badge>['tone']

const STATUS_TONES: Record<RegionDayStatus, Tone> = {
  QUOTA_DRAFT: 'warning',
  QUOTA_PUBLISHED: 'success',
  DISTRIBUTION_DRAFT: 'brand',
  DISTRIBUTION_PUBLISHED: 'brand',
  CLOSED: 'neutral',
}

/** Statut d'une journée régionale (§6.1). */
export function RegionStatusBadge({ status }: { status: RegionDayStatus }) {
  const { t } = useTranslation()
  return <Badge tone={STATUS_TONES[status]}>{t(`backoffice.quotas.regionStatus.${status}`)}</Badge>
}

/**
 * Indicateur d'enregistrement automatique : « Enregistrement… »,
 * « Brouillons enregistrés à 09:12 », ou échec avec « Réessayer » ; nombre
 * de cellules en erreur, qui ne sont pas envoyées.
 */
export function SaveIndicator({ snapshot, onRetry }: { snapshot: DraftSnapshot; onRetry: () => void }) {
  const { t } = useTranslation()
  const errorMessage = useErrorMessage()
  const { status, savedAt, invalid } = snapshot

  let message: string | null = null
  if (status === 'pending' || status === 'saving') message = t('backoffice.quotas.save.saving')
  else if (status === 'saved' && savedAt) message = t('backoffice.quotas.save.saved', { time: formatTime(savedAt) })
  else if (status === 'error') message = `${t('backoffice.quotas.save.error')} ${errorMessage(snapshot.error)}`

  return (
    <div className="flex min-h-11 flex-wrap items-center gap-x-4 gap-y-1 text-sm">
      <p role="status" className={status === 'error' ? 'font-semibold text-red-700' : 'text-slate-600'}>
        {message}
      </p>
      {status === 'error' && (
        <Button variant="secondary" onClick={onRetry}>
          {t('backoffice.quotas.save.retry')}
        </Button>
      )}
      {invalid > 0 && <p className="font-semibold text-red-700">{t('backoffice.quotas.save.invalid', { count: invalid })}</p>}
    </div>
  )
}

/** Légende des repères visuels de la matrice. */
export function MatrixLegend() {
  const { t } = useTranslation()
  const items = [
    { label: t('backoffice.quotas.legend.draft'), swatch: 'bg-amber-100' },
    { label: t('backoffice.quotas.legend.below'), swatch: 'bg-white text-red-700 font-semibold', sample: '12' },
    { label: t('backoffice.quotas.legend.error'), swatch: 'bg-red-50 ring-2 ring-inset ring-red-600' },
  ]
  return (
    <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-700">
      <span className="font-semibold">{t('backoffice.quotas.legend.label')}</span>
      {items.map((item) => (
        <span key={item.label} className="flex items-center gap-2">
          <span aria-hidden="true" className={`inline-flex h-5 w-8 items-center justify-center rounded border border-slate-300 tabular-nums ${item.swatch}`}>
            {item.sample}
          </span>
          {item.label}
        </span>
      ))}
    </div>
  )
}
