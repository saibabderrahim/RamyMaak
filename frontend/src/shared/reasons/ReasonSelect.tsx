import { type SelectHTMLAttributes, forwardRef } from 'react'
import { useTranslation } from 'react-i18next'
import '../i18n/orders'
import type { ReasonRef, ReasonType } from '../api/types'
import { Select } from '../ui'
import { reasonsOfType, useReasonCodes } from './api'

type Props = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> & {
  type: ReasonType
  /**
   * Motif déjà enregistré (reprise d'une saisie) : gardé dans la liste même
   * s'il a été désactivé depuis, pour ne pas changer la valeur en silence.
   */
  current?: ReasonRef | null
  /** Texte de l'option vide ; « Choisir un motif » par défaut. */
  placeholder?: string
}

/**
 * Liste des motifs actifs d'un type (ajustement, rejet, écart d'expédition,
 * annulation), valeur = code, option vide = aucun motif. À placer dans un
 * `Field` (libellé, aide, erreur) ; compatible avec `register()` de
 * react-hook-form. Désactivée pendant le chargement des motifs.
 */
export const ReasonSelect = forwardRef<HTMLSelectElement, Props>(function ReasonSelect(
  { type, current = null, placeholder, disabled, ...props },
  ref,
) {
  const { t } = useTranslation()
  const reasons = useReasonCodes(type)
  const options = reasonsOfType(reasons.data, type)
  const keepCurrent = current !== null && !options.some((reason) => reason.code === current.code)
  const empty = reasons.isPending ? t('orders.reasons.loading') : reasons.isError ? t('orders.reasons.unavailable') : (placeholder ?? t('orders.reasons.placeholder'))

  return (
    <Select ref={ref} disabled={disabled || reasons.isPending} {...props}>
      <option value="">{empty}</option>
      {keepCurrent && (
        <option value={current.code}>{t('orders.reasons.inactive', { label: current.label })}</option>
      )}
      {options.map((reason) => (
        <option key={reason.code} value={reason.code}>
          {reason.label}
        </option>
      ))}
    </Select>
  )
})
