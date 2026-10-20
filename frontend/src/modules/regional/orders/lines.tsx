import { useTranslation } from 'react-i18next'
import type { RegionalOrderLine } from '../../../shared/api/types'
import { formatAmount, formatQuantity } from '../../../shared/format'
import { reasonText } from '../../../shared/reasons/labels'
import { ProductImage } from '../../../shared/ui/ProductImage'

/**
 * Ligne d'une commande reçue, en lecture seule : produit, quantité demandée
 * (puis validée et expédiée dès qu'elles existent), contexte publié — borne
 * B(c,p) ou « Pas de borne », validé V(c,p), quota Q(r,p) et reste R(r,p) de la
 * région —, prix unitaire figé et montant HT. Une ligne ajustée à la validation
 * dit de combien, pourquoi (motif en clair) et le commentaire du régional.
 */
export function LineItem({ line }: { line: RegionalOrderLine }) {
  const { t } = useTranslation()
  const { context } = line
  const quantity = (value: number) => formatQuantity(value)
  const facts = [
    line.validated_qty !== null ? t('orders.regional.detail.lines.validated', { value: quantity(line.validated_qty) }) : null,
    line.shipped_qty !== null ? t('orders.regional.detail.lines.shipped', { value: quantity(line.shipped_qty) }) : null,
    context.limit === null ? t('orders.regional.detail.lines.noLimit') : t('orders.regional.detail.lines.limit', { value: quantity(context.limit) }),
    t('orders.regional.detail.lines.clientValidated', { value: quantity(context.client_validated) }),
    t('orders.regional.detail.lines.regionQuota', { value: quantity(context.region_quota) }),
    t('orders.regional.detail.lines.regionRemaining', { value: quantity(context.region_remaining) }),
  ].filter((fact): fact is string => fact !== null)

  const reduced = line.validated_qty !== null && line.validated_qty < line.requested_qty
  const reason = reasonText(line.adjustment_reason, line.adjustment_reason_code)

  return (
    <li className="flex gap-3 py-3 first:pt-0 last:pb-0">
      <ProductImage photo={line.photo ? { urls: line.photo } : null} alt={line.name} size={56} className="w-14 shrink-0" />
      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4">
          <p className="font-semibold text-slate-900">{line.name}</p>
          <p className="text-sm tabular-nums text-slate-900">{t('orders.regional.detail.lines.amount', { amount: formatAmount(line.amount_ht) })}</p>
        </div>
        <p className="text-xs text-slate-600">
          {line.sku} · {line.sales_unit_label} · {t('orders.regional.detail.lines.unitPrice', { amount: formatAmount(line.unit_price_ht) })}
        </p>
        <p className="text-base font-semibold text-slate-900">
          {t('orders.regional.detail.lines.requested', { value: quantity(line.requested_qty) })}
        </p>
        {reduced && line.validated_qty !== null && (
          <p className="text-sm font-medium text-slate-900">
            {t('orders.decision.view.reduced', { from: quantity(line.requested_qty), to: quantity(line.validated_qty) })}
            {reason !== null && ` · ${t('orders.decision.view.reason', { reason })}`}
          </p>
        )}
        {line.adjustment_comment && (
          <p className="break-words text-sm text-slate-700">{t('orders.decision.view.lineComment', { comment: line.adjustment_comment })}</p>
        )}
        <ul
          aria-label={t('orders.regional.detail.lines.context', { name: line.name })}
          className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-700"
        >
          {facts.map((fact) => (
            <li key={fact}>{fact}</li>
          ))}
        </ul>
      </div>
    </li>
  )
}
