import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../shared/api/client'
import type { DistributionResult, LimitViolation, NoLimitPolicy, PublicationPreview } from '../../shared/api/types'
import { Alert, Button, ErrorAlert } from '../../shared/ui'
import { usePublishDistribution } from './api'
import { Dialog } from '../../shared/ui/Dialog'
import { formatQuantity } from './model'

const ignore = () => undefined

type Props = {
  day: string
  regionId: number
  /** Récapitulatif relu juste avant l'ouverture (`publication` de la grille). */
  preview: PublicationPreview
  policy: NoLimitPolicy
  /** Cellules en erreur, non enregistrées : elles ne seront pas envoyées. */
  invalid: number
  /** Cellules refusées par la dernière tentative (liste vide au départ d'une tentative). */
  onViolations: (violations: LimitViolation[]) => void
  onPublished: (result: DistributionResult) => void
  onClose: () => void
}

/**
 * « Valider et envoyer aux clients » (RG-B06, RG-B07) : récapitulatif
 * (premier envoi ou modifications, bornes modifiées, clients notifiés,
 * surallocations permises, RG-B05), puis confirmation. Envoi tout ou rien :
 * une borne au-dessus du quota (diminué depuis) ou sous le validé refuse
 * l'ensemble, et les cellules en cause sont listées et signalées.
 */
export function PublishDialog({ day, regionId, preview, policy, invalid, onViolations, onPublished, onClose }: Props) {
  const { t } = useTranslation()
  const publish = usePublishDistribution(day, regionId)
  const [error, setError] = useState<unknown>(null)
  const [violations, setViolations] = useState<LimitViolation[] | null>(null)

  const confirm = async () => {
    setError(null)
    setViolations(null)
    // Nouvel essai : les cellules signalées par le précédent ne le sont plus.
    onViolations([])
    try {
      onPublished(await publish.mutateAsync())
    } catch (publishError) {
      const list = publishError instanceof ApiError && Array.isArray(publishError.problem.violations) ? (publishError.problem.violations as LimitViolation[]) : null
      setError(publishError)
      if (list && list.length > 0) {
        setViolations(list)
        onViolations(list)
      }
    }
  }

  const drafts =
    preview.drafts > 0
      ? t('distribution.publish.drafts', { count: preview.drafts })
      : preview.first
        ? t(policy === 'FREE' ? 'distribution.publish.noLimitsFree' : 'distribution.publish.noLimitsRequired')
        : t('distribution.publish.noDrafts')

  // Envoi en cours : il ne peut plus être arrêté, le dialogue reste ouvert
  // jusqu'à son compte rendu (Annuler, × et Échap sans effet).
  const busy = publish.isPending

  return (
    <Dialog title={t('distribution.publish.title')} onClose={busy ? ignore : onClose} size="lg">
      <div className="flex flex-col gap-4 text-sm text-slate-700">
        <section aria-label={t('distribution.publish.recap')} className="flex flex-col gap-2">
          <p className="text-base font-semibold text-slate-900">{preview.first ? t('distribution.publish.first') : t('distribution.publish.changes')}</p>
          <p>{preview.first ? t('distribution.publish.firstHint') : t('distribution.publish.changesHint')}</p>
          <ul className="list-disc ps-5">
            <li>{drafts}</li>
            <li>{preview.notified_clients > 0 ? t('distribution.publish.notified', { count: preview.notified_clients }) : t('distribution.publish.nobodyNotified')}</li>
          </ul>
          {preview.overallocated.length > 0 && (
            <div className="rounded-lg border border-orange-200 bg-orange-50 px-4 py-3 text-orange-950">
              <p className="font-semibold">{t('distribution.publish.overallocatedTitle')}</p>
              <ul className="mt-1 list-disc ps-5">
                {preview.overallocated.map((item) => (
                  <li key={item.product_id}>
                    {t('distribution.publish.overallocated', { sku: item.sku, sum: formatQuantity(item.sum_limits), quota: formatQuantity(item.quota) })}
                  </li>
                ))}
              </ul>
              <p className="mt-1">{t('distribution.publish.overallocatedHint')}</p>
            </div>
          )}
        </section>

        {invalid > 0 && <Alert tone="warning">{t('distribution.publish.invalid', { count: invalid })}</Alert>}
        {violations ? <ViolationList detail={error instanceof ApiError ? (error.problem.detail ?? null) : null} violations={violations} /> : <ErrorAlert error={error} />}

        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            {t('common.cancel')}
          </Button>
          <Button onClick={() => void confirm()} disabled={busy} data-autofocus>
            {busy ? t('distribution.publish.publishing') : t('distribution.publish.confirm')}
          </Button>
        </div>
      </div>
    </Dialog>
  )
}

function ViolationList({ detail, violations }: { detail: string | null; violations: LimitViolation[] }) {
  const { t } = useTranslation()
  const requested = (value: number | null) => (value === null ? t('distribution.grid.noLimit') : formatQuantity(value))
  return (
    <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-900">
      <p className="font-semibold">{detail ?? t('distribution.publish.violationsTitle')}</p>
      <ul className="mt-2 list-disc ps-5">
        {violations.map((violation) => (
          <li key={`${violation.client_id}:${violation.product_id}`}>
            {violation.quota !== undefined
              ? t('distribution.publish.aboveQuota', {
                  client: violation.client,
                  sku: violation.sku,
                  requested: requested(violation.requested),
                  quota: formatQuantity(violation.quota),
                })
              : t('distribution.publish.belowValidated', {
                  client: violation.client,
                  sku: violation.sku,
                  requested: requested(violation.requested),
                  validated: formatQuantity(violation.validated ?? 0),
                })}
          </li>
        ))}
      </ul>
      <p className="mt-2">{t('distribution.publish.violationsHint')}</p>
    </div>
  )
}
