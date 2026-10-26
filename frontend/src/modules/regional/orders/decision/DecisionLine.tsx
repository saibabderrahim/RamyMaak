import { memo, type ReactNode, useId } from 'react'
import { useTranslation } from 'react-i18next'
import type { RegionalOrderLine } from '../../../../shared/api/types'
import { formatAmount, formatQuantity } from '../../../../shared/format'
import { ReasonSelect } from '../../../../shared/reasons'
import { Button, Textarea } from '../../../../shared/ui'
import { ProductImage } from '../../../../shared/ui/ProductImage'
import { QuantityStepper } from '../../../../shared/ui/QuantityStepper'
import { ADJUSTMENT_COMMENT_MAX, isAboveMax, isReduced, type LineIssue, limitRemainingAfter, maxOf, regionRemainingAfter } from './model'

type FieldProps = {
  /** Libellé visible. */
  label: string
  /** Produit, ajouté au libellé pour les lecteurs d'écran (plusieurs lignes portent le même champ). */
  name: string
  hint?: string
  error?: string
  children: (props: { id: string; 'aria-invalid': boolean; 'aria-describedby'?: string }) => ReactNode
}

/** Champ d'une ligne : libellé complété du nom du produit pour les lecteurs d'écran, aide et erreur. */
function LineField({ label, name, hint, error, children }: FieldProps) {
  const id = useId()
  const describedBy = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ') || undefined
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-sm font-semibold text-slate-900">
        {label}
        <span className="sr-only"> — {name}</span>
      </label>
      {children({ id, 'aria-invalid': Boolean(error), 'aria-describedby': describedBy })}
      {hint && (
        <p id={`${id}-hint`} className="text-xs text-slate-600">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="text-sm text-danger">
          {error}
        </p>
      )}
    </div>
  )
}

type Props = {
  line: RegionalOrderLine
  /** Saisie du brouillon pour cette ligne. */
  quantity: number
  reason: string
  comment: string
  /** Saisie manquante, signalée après une tentative de validation. */
  reasonMissing: boolean
  commentMissing: boolean
  /** Refus du serveur pour cette ligne, valable tant que la quantité envoyée n'a pas changé. */
  issue: LineIssue | undefined
  /** Lecture seule : validation impossible, commande modifiée par le client, décision en cours d'envoi. */
  disabled: boolean
  onQuantity: (line: RegionalOrderLine, quantity: number) => void
  onReason: (line: RegionalOrderLine, reason: string) => void
  onComment: (line: RegionalOrderLine, comment: string) => void
}

/**
 * Ligne de la commande à décider (EF-REG-05, contrat du lot 6 §8) : demandé,
 * borne, déjà validé pour ce client, reste de la région, sélecteur de quantité
 * (au plus le demandé), reste après validation (région, et borne s'il y en a
 * une) ; motif d'ajustement et commentaire — visible du client — dès que la
 * ligne est réduite. Les lignes au-dessus du maximum validable sont signalées,
 * avec « Appliquer n » ; le serveur reste seul juge.
 */
export const DecisionLine = memo(function DecisionLine({
  line,
  quantity,
  reason,
  comment,
  reasonMissing,
  commentMissing,
  issue,
  disabled,
  onQuantity,
  onReason,
  onComment,
}: Props) {
  const { t } = useTranslation()
  const { context } = line
  const reduced = isReduced(line, quantity)
  const regionAfter = regionRemainingAfter(line, quantity)
  const limitAfter = limitRemainingAfter(line, quantity)
  const max = maxOf(line)

  // Refus du serveur pour la quantité envoyée, sinon maximum validable dépassé (contexte relu en direct).
  const shownIssue = issue && issue.sent === quantity ? issue : undefined
  const applicable = shownIssue?.kind === 'violation' ? shownIssue.violation.max_allowed : isAboveMax(line, quantity) ? max : null
  const message =
    shownIssue?.kind === 'field'
      ? shownIssue.messages.join(' ')
      : shownIssue?.kind === 'violation'
        ? t(`orders.decision.line.${shownIssue.violation.cause}`, { max: formatQuantity(shownIssue.violation.max_allowed) })
        : applicable !== null
          ? t('orders.decision.line.above', { max: formatQuantity(applicable) })
          : null

  const facts = [
    t('orders.regional.detail.lines.requested', { value: formatQuantity(line.requested_qty) }),
    context.limit === null ? t('orders.regional.detail.lines.noLimit') : t('orders.regional.detail.lines.limit', { value: formatQuantity(context.limit) }),
    t('orders.regional.detail.lines.clientValidated', { value: formatQuantity(context.client_validated) }),
    t('orders.regional.detail.lines.regionRemaining', { value: formatQuantity(context.region_remaining) }),
    t('orders.decision.line.max', { value: formatQuantity(max) }),
  ]

  // Charte Ramy (lot 7) : chiffres côte à côte, libellé au-dessus de la valeur ; le texte de chaque
  // élément reste « Libellé : valeur » pour les lecteurs d'écran.
  const cell = (text: string, strong = false, negative = false) => {
    const [label, value] = text.split(' : ')
    return (
      <li key={text} className="flex min-w-0 flex-col">
        {value === undefined ? (
          <span className="text-sm text-slate-700">{label}</span>
        ) : (
          <>
            <span className="text-xs leading-snug text-slate-600">{label}</span>
            <span className="sr-only"> : </span>
            <span className={`text-base tabular-nums ${negative ? 'font-semibold text-danger' : strong ? 'font-semibold text-slate-900' : 'text-slate-900'}`}>
              {value}
            </span>
          </>
        )}
      </li>
    )
  }

  return (
    <li>
      <article aria-label={line.name} className={`-mx-4 flex flex-col gap-3 px-4 py-4 sm:-mx-5 sm:px-5 ${message !== null ? 'bg-danger-soft' : ''}`}>
        <div className="flex gap-3">
          <ProductImage photo={line.photo ? { urls: line.photo } : null} alt="" size={56} className="w-14 shrink-0 self-start" />
          <div className="flex min-w-0 flex-1 flex-col gap-0.5">
            <p className="font-semibold text-slate-900">{line.name}</p>
            <p className="text-xs text-slate-600">
              {line.sku} · {line.sales_unit_label} · {t('orders.regional.detail.lines.unitPrice', { amount: formatAmount(line.unit_price_ht) })}
            </p>
          </div>
        </div>

        <ul
          aria-label={t('orders.regional.detail.lines.context', { name: line.name })}
          className="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3 lg:grid-cols-5"
        >
          {facts.map((fact, index) => cell(fact, index === 0 || index === facts.length - 1))}
        </ul>

        <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
          <QuantityStepper name={line.name} value={quantity} max={line.requested_qty} disabled={disabled} onChange={(next) => onQuantity(line, next)} />
          <ul aria-label={t('orders.decision.line.afterLabel', { name: line.name })} className="flex flex-wrap gap-x-6 gap-y-2">
            {cell(t('orders.decision.line.regionAfter', { value: formatQuantity(regionAfter) }), false, regionAfter < 0)}
            {limitAfter !== null && cell(t('orders.decision.line.limitAfter', { value: formatQuantity(limitAfter) }), false, limitAfter < 0)}
          </ul>
        </div>

        {message !== null && (
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm font-semibold text-danger">
            <p>{message}</p>
            {applicable !== null && !disabled && (
              <Button
                variant="secondary"
                aria-label={t('orders.decision.line.applyLabel', { max: formatQuantity(applicable), name: line.name })}
                onClick={() => onQuantity(line, applicable)}
              >
                {t('orders.decision.line.apply', { max: formatQuantity(applicable) })}
              </Button>
            )}
          </div>
        )}

        {reduced && (
          <div className="grid gap-3 rounded-lg bg-slate-100 p-3 sm:grid-cols-2">
            <LineField
              label={t('orders.decision.line.reason')}
              name={line.name}
              error={reasonMissing ? t('orders.decision.line.reasonRequired') : undefined}
            >
              {(props) => (
                <ReasonSelect type="ADJUSTMENT" {...props} value={reason} disabled={disabled} onChange={(event) => onReason(line, event.target.value)} />
              )}
            </LineField>
            <LineField
              label={t('orders.decision.line.comment')}
              name={line.name}
              hint={t('orders.decision.line.commentHint', { count: comment.length, max: ADJUSTMENT_COMMENT_MAX })}
              error={commentMissing ? t('orders.decision.line.commentRequired') : undefined}
            >
              {(props) => (
                <Textarea
                  {...props}
                  rows={2}
                  maxLength={ADJUSTMENT_COMMENT_MAX}
                  value={comment}
                  disabled={disabled}
                  onChange={(event) => onComment(line, event.target.value)}
                />
              )}
            </LineField>
          </div>
        )}
      </article>
    </li>
  )
})
