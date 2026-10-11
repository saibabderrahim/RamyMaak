import { type FormEvent, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../../shared/api/client'
import type { PublicationResult, QuotaMatrixRegion, QuotaViolation } from '../../../shared/api/types'
import { Alert, Button, ErrorAlert } from '../../../shared/ui'
import { Dialog } from '../shared/ui'
import { usePublishQuotas } from './api'
import { formatQuantity } from './matrix'

type Props = {
  day: string
  regions: QuotaMatrixRegion[]
  /** Cellules en erreur, non enregistrées : elles ne seront pas publiées. */
  invalid: number
  /** Enregistre les saisies en attente avant la publication. */
  flush: () => Promise<void>
  /** Cellules refusées par la dernière tentative (liste vide au départ d'une tentative). */
  onViolations: (violations: QuotaViolation[]) => void
  onClose: () => void
}

type Scope = 'all' | 'selection'

/**
 * « Publier… » (RG-Q03, RG-Q04) : toutes les régions ou une sélection ; pour
 * chacune, ses brouillons et s'il s'agit d'une première publication (N-01)
 * ou de modifications (N-02). Les saisies en attente sont enregistrées
 * d'abord. Publication tout ou rien : un quota sous le validé (RG-Q05)
 * refuse l'ensemble, et les cellules en cause sont signalées.
 */
export function PublishDialog({ day, regions, invalid, flush, onViolations, onClose }: Props) {
  const { t } = useTranslation()
  const scopeName = useId()
  const publish = usePublishQuotas(day)
  const open = regions.filter((region) => region.status !== 'CLOSED')
  const [scope, setScope] = useState<Scope>('all')
  const [selected, setSelected] = useState<ReadonlySet<number>>(() => new Set(open.filter((region) => region.pending_drafts > 0).map((region) => region.id)))
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [violations, setViolations] = useState<QuotaViolation[] | null>(null)
  const [result, setResult] = useState<PublicationResult | null>(null)

  const toggle = (regionId: number, checked: boolean) =>
    setSelected((current) => {
      const next = new Set(current)
      if (checked) next.add(regionId)
      else next.delete(regionId)
      return next
    })

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setMessage(null)
    setError(null)
    setViolations(null)
    if (scope === 'selection' && selected.size === 0) {
      setMessage(t('backoffice.quotas.publish.noSelection'))
      return
    }

    setBusy(true)
    // Nouvel essai : les cellules signalées par le précédent ne le sont plus.
    onViolations([])
    try {
      await flush()
    } catch (flushError) {
      if (flushError instanceof ApiError) setError(flushError)
      else setMessage(t('backoffice.quotas.flushFailed'))
      setBusy(false)
      return
    }

    try {
      const ids = open.filter((region) => selected.has(region.id)).map((region) => region.id)
      setResult(await publish.mutateAsync(scope === 'all' ? { all: true } : { region_ids: ids }))
    } catch (publishError) {
      if (publishError instanceof ApiError && publishError.code === 'QUOTA_BELOW_VALIDATED') {
        const list = Array.isArray(publishError.problem.violations) ? (publishError.problem.violations as QuotaViolation[]) : []
        setViolations(list)
        onViolations(list)
      } else {
        setError(publishError)
      }
    } finally {
      setBusy(false)
    }
  }

  if (result) {
    return (
      // Autre clé : le focus passe sur le compte rendu.
      <Dialog key="result" open title={t('backoffice.quotas.publish.title')} onClose={onClose}>
        <PublicationReport result={result} />
        <div className="mt-4 flex justify-end">
          <Button onClick={onClose} data-autofocus>
            {t('backoffice.quotas.publish.back')}
          </Button>
        </div>
      </Dialog>
    )
  }

  return (
    <Dialog key="form" open title={t('backoffice.quotas.publish.title')} onClose={onClose} size="lg">
      <form onSubmit={submit} noValidate className="flex flex-col gap-4 text-sm text-slate-700">
        <p>{t('backoffice.quotas.publish.intro')}</p>

        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 font-semibold text-slate-900">{t('backoffice.quotas.publish.scope')}</legend>
          {(['all', 'selection'] as const).map((option) => (
            <label key={option} className="flex min-h-11 cursor-pointer items-center gap-3">
              <input
                type="radio"
                name={scopeName}
                value={option}
                checked={scope === option}
                onChange={() => setScope(option)}
                className="size-5 accent-brand-600"
              />
              {t(`backoffice.quotas.publish.${option}`)}
            </label>
          ))}
        </fieldset>

        <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200" aria-label={t('backoffice.quotas.publish.scope')}>
          {regions.map((region) => {
            const closed = region.status === 'CLOSED'
            const details = [
              closed
                ? t('backoffice.quotas.publish.closed')
                : region.quota_version === 0
                  ? t('backoffice.quotas.publish.first')
                  : t('backoffice.quotas.publish.changes', { version: region.quota_version }),
              region.pending_drafts > 0 ? t('backoffice.quotas.grid.drafts', { count: region.pending_drafts }) : t('backoffice.quotas.grid.noDrafts'),
            ].join(' · ')
            const content = (
              <span className="flex flex-col">
                <span className="font-semibold text-slate-900">{`${region.code} — ${region.name}`}</span>
                <span className="text-xs text-slate-600">{details}</span>
              </span>
            )
            return (
              <li key={region.id} className="px-3 py-1">
                {scope === 'selection' ? (
                  <label className="flex min-h-11 cursor-pointer items-center gap-3">
                    <input
                      type="checkbox"
                      checked={selected.has(region.id)}
                      disabled={closed}
                      onChange={(event) => toggle(region.id, event.target.checked)}
                      className="size-5 shrink-0 rounded border-slate-300 accent-brand-600"
                    />
                    {content}
                  </label>
                ) : (
                  <div className="flex min-h-11 items-center">{content}</div>
                )}
              </li>
            )
          })}
        </ul>

        {invalid > 0 && <Alert tone="warning">{t('backoffice.quotas.publish.invalid', { count: invalid })}</Alert>}
        {message && <Alert tone="error">{message}</Alert>}
        <ErrorAlert error={error} />
        {violations && <ViolationList violations={violations} />}

        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? t('backoffice.quotas.publish.publishing') : t('backoffice.quotas.publish.confirm')}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function PublicationReport({ result }: { result: PublicationResult }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-4 text-sm text-slate-700">
      <Alert tone={result.published.length > 0 ? 'success' : 'warning'}>
        {result.published.length > 0 ? t('backoffice.quotas.publish.done') : t('backoffice.quotas.publish.nothing')}
      </Alert>
      {result.published.length > 0 && (
        <section>
          <h3 className="mb-1 font-semibold text-slate-900">{t('backoffice.quotas.publish.publishedTitle')}</h3>
          <ul className="list-disc ps-5">
            {result.published.map((item) => (
              <li key={item.region_id}>
                {item.first
                  ? t('backoffice.quotas.publish.publishedFirst', { code: item.code, version: item.version })
                  : t('backoffice.quotas.publish.publishedChanges', { code: item.code, version: item.version, count: item.changes })}
              </li>
            ))}
          </ul>
        </section>
      )}
      {result.skipped.length > 0 && (
        <section>
          <h3 className="mb-1 font-semibold text-slate-900">{t('backoffice.quotas.publish.skippedTitle')}</h3>
          <ul className="list-disc ps-5">
            {result.skipped.map((item) => (
              <li key={item.region_id}>
                {t('backoffice.quotas.publish.skipped', { code: item.code, reason: t(`backoffice.quotas.publish.reasons.${item.reason}`) })}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}

function ViolationList({ violations }: { violations: QuotaViolation[] }) {
  const { t } = useTranslation()
  return (
    <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-900">
      <p className="font-semibold">{t('backoffice.quotas.publish.violationsTitle')}</p>
      <ul className="mt-2 list-disc ps-5">
        {violations.map((violation) => (
          <li key={`${violation.region_id}:${violation.product_id}`}>
            {t('backoffice.quotas.publish.violation', {
              region: violation.region,
              sku: violation.sku,
              validated: formatQuantity(violation.validated),
              requested: formatQuantity(violation.requested),
            })}
          </li>
        ))}
      </ul>
      <p className="mt-2">{t('backoffice.quotas.publish.violationsHint')}</p>
    </div>
  )
}
