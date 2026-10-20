import { useTranslation } from 'react-i18next'
import type { BulkStatusResult, ProcessingStatus } from '../../../shared/api/types'
import { Alert, Button, ErrorAlert } from '../../../shared/ui'
import { type BulkPlan, MAX_SELECTION } from './model'

type BarProps = {
  /** Étape cible de l'onglet ; null : pas de changement de statut en masse (Expédiées, Annulées). */
  target: ProcessingStatus | null
  selected: number
  plan: BulkPlan
  pending: boolean
  online: boolean
  error: unknown
  onRun: () => void
  onClear: () => void
}

/**
 * Barre de la sélection multiple (100 commandes au plus, I-78) : l'étape
 * suivante de l'onglet, appliquée aux seules commandes qui peuvent la
 * recevoir ; hors ligne, le bouton est désactivé.
 */
export function BulkBar({ target, selected, plan, pending, online, error, onRun, onClear }: BarProps) {
  const { t } = useTranslation()
  if (selected === 0) return <ErrorAlert error={error} className="mb-4" />

  return (
    <div className="mb-4 flex flex-col gap-2">
      <div
        role="region"
        aria-label={t('backoffice.orders.bulk.region')}
        className="flex flex-col gap-2 rounded-lg border border-brand-200 bg-brand-50 p-3"
      >
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-sm font-semibold text-brand-900" aria-live="polite">
            {t('backoffice.orders.bulk.selected', { count: selected })}
          </span>
          {target && (
            <Button disabled={pending || !online || plan.items.length === 0} onClick={onRun}>
              {pending ? t('backoffice.orders.bulk.pending') : t(`backoffice.orders.bulk.actions.${target}`, { count: plan.items.length })}
            </Button>
          )}
          <Button variant="ghost" onClick={onClear} disabled={pending}>
            {t('backoffice.orders.bulk.clear')}
          </Button>
        </div>
        {plan.skipped.length > 0 && <p className="text-xs font-medium text-amber-800">{t('backoffice.orders.bulk.skipped', { count: plan.skipped.length })}</p>}
        {selected >= MAX_SELECTION && <p className="text-xs font-medium text-amber-800">{t('backoffice.orders.bulk.max', { max: MAX_SELECTION })}</p>}
        {!online && <p className="text-xs text-slate-700">{t('backoffice.orders.offline')}</p>}
      </div>
      <ErrorAlert error={error} />
    </div>
  )
}

export type BulkReport = { target: ProcessingStatus; result: BulkStatusResult }

/**
 * Compte rendu d'un changement de statut en masse : « n appliquées / m
 * refusées », puis chaque commande refusée avec son motif (I-78 : un refus
 * n'annule pas les autres).
 */
export function BulkReportPanel({ report, onClose }: { report: BulkReport; onClose: () => void }) {
  const { t } = useTranslation()
  const { applied, refused } = report.result
  return (
    <section aria-label={t('backoffice.orders.report.region')} className="mb-4">
      <Alert tone={refused.length === 0 ? 'success' : 'warning'}>
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="flex flex-col gap-1">
            <p className="font-semibold">
              {t(`backoffice.orders.report.title.${report.target}`)} :{' '}
              {t('backoffice.orders.report.summary', {
                applied: t('backoffice.orders.report.applied', { count: applied.length }),
                refused: t('backoffice.orders.report.refused', { count: refused.length }),
              })}
            </p>
            {refused.length > 0 && (
              <ul aria-label={t('backoffice.orders.report.refusedList')} className="list-disc ps-5">
                {refused.map((item) => (
                  <li key={item.id}>
                    <span className="font-medium">{item.number ?? item.id}</span> : {t(`backoffice.orders.report.refusal.${item.code}`)}
                    {item.detail && <span className="block text-xs opacity-80">{item.detail}</span>}
                  </li>
                ))}
              </ul>
            )}
          </div>
          <Button variant="ghost" onClick={onClose} aria-label={t('backoffice.orders.report.close')}>
            <span aria-hidden="true">×</span>
          </Button>
        </div>
      </Alert>
    </section>
  )
}
