import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import type { Order, OrderHistoryEntry, OrderLine, OrderStatus } from '../../../shared/api/types'
import { formatAmount, formatDateTime, formatQuantity } from '../../../shared/format'
import { reasonText } from '../../../shared/reasons/labels'
import { ProductImage } from '../../../shared/ui/ProductImage'
import { type StepState, timelineSteps } from './timeline'
import {
  cancelledByAdv,
  type DecisionKind,
  decisionInstant,
  decisionKind,
  hasShippingGap,
  historyLabelKey,
  isReduced,
  isShipped,
  reducedCount,
  STATUS_CLASSES,
} from './view'

// Éléments du détail d'une commande du client (EF-CLT-04) — contrat : docs/lot-5-contrat-api.md §3 et §6 ;
// motifs avec libellé, ajustement, expédition : docs/lot-6-contrat-api.md §4 et §8. RG-C09 : jamais Q, R ni V(r,p).

const SECTION = 'flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:p-6'

/** Statut d'une commande : libellé commun (orders.status.*) sur une couleur propre au statut. */
export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  const { t } = useTranslation()
  return (
    <span className={`inline-flex min-h-6 items-center rounded px-2 text-xs font-semibold whitespace-nowrap ${STATUS_CLASSES[status]}`} data-status={status}>
      {t(`orders.status.${status}`)}
    </span>
  )
}

const STEP_MARKS: Record<StepState, string> = {
  done: 'border-brand-600 bg-brand-600 text-white',
  current: 'border-brand-600 bg-white text-brand-700 ring-4 ring-brand-100',
  upcoming: 'border-slate-300 bg-white text-slate-400',
}

/**
 * Frise de statut (§6.2) : étapes franchies cochées, étape actuelle marquée
 * (`aria-current="step"`), étapes à venir en gris ; une fin sans livraison
 * (rejet, annulation, expiration) remplace la suite du parcours.
 */
export function StatusTimeline({ order }: { order: Pick<Order, 'status' | 'submitted_at' | 'history'> }) {
  const { t } = useTranslation()
  const titleId = useId()
  const steps = timelineSteps(order)

  return (
    <section aria-labelledby={titleId} className={SECTION}>
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('orders.clientOrders.timeline.title')}
      </h2>
      <ol className="flex flex-col">
        {steps.map((step, index) => {
          const last = index === steps.length - 1
          const mark = step.terminal
            ? step.status === 'REJECTED'
              ? 'border-red-600 bg-red-600 text-white'
              : 'border-slate-500 bg-slate-500 text-white'
            : STEP_MARKS[step.state]
          return (
            <li key={step.status} aria-current={step.state === 'current' ? 'step' : undefined} className="flex gap-3" data-state={step.state}>
              <div className="flex flex-col items-center" aria-hidden="true">
                <span className={`flex size-7 shrink-0 items-center justify-center rounded-full border-2 text-sm font-bold ${mark}`}>
                  {step.terminal ? '×' : step.state === 'done' ? '✓' : step.state === 'current' ? '●' : ''}
                </span>
                {!last && <span className={`w-0.5 grow ${step.state === 'done' ? 'bg-brand-600' : 'bg-slate-200'}`} />}
              </div>
              <div className={`flex min-h-11 flex-col ${last ? '' : 'pb-3'}`}>
                <span className={`text-sm ${step.state === 'upcoming' ? 'text-slate-500' : 'font-semibold text-slate-900'}`}>
                  {t(`orders.status.${step.status}`)}
                  <span className="sr-only"> — {t(`orders.clientOrders.timeline.${step.state}`)}</span>
                </span>
                {step.at && <span className="text-xs text-slate-600">{formatDateTime(step.at)}</span>}
              </div>
            </li>
          )
        })}
      </ol>
    </section>
  )
}

/** Lignes : quantités demandée, validée (et motif d'ajustement), expédiée ; prix figé et montant HT. */
export function OrderLines({ lines }: { lines: OrderLine[] }) {
  const { t } = useTranslation()
  const titleId = useId()
  return (
    <section aria-labelledby={titleId} className={SECTION}>
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('orders.clientOrders.lines.title')}
      </h2>
      <ul className="flex flex-col divide-y divide-slate-100">
        {lines.map((line) => (
          <li key={line.product_id} className="py-3 first:pt-0 last:pb-0">
            <OrderLineItem line={line} />
          </li>
        ))}
      </ul>
    </section>
  )
}

function OrderLineItem({ line }: { line: OrderLine }) {
  const { t } = useTranslation()
  const nameId = useId()
  const reduced = isReduced(line)
  // Motif d'ajustement : son libellé (motif désactivé compris), à défaut le code rendu lisible.
  const reason = reasonText(line.adjustment_reason, line.adjustment_reason_code)
  const gap = hasShippingGap(line)
  const gapReason = reasonText(line.shipping_gap_reason)

  return (
    <article aria-labelledby={nameId} className="flex gap-3">
      <ProductImage photo={line.photo ? { urls: line.photo } : null} alt={line.name} size={64} className="size-16 shrink-0" />
      <div className="flex min-w-0 grow flex-col gap-1">
        <h3 id={nameId} className="font-semibold text-slate-900">
          {line.name}
        </h3>
        <p className="text-sm text-slate-600">
          {line.sales_unit_label} · {t('orders.clientOrders.lines.unitPrice', { amount: formatAmount(line.unit_price_ht) })}
        </p>
        <dl className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <div className="flex gap-1">
            <dt className="text-slate-600">{t('orders.clientOrders.lines.requested')}</dt>
            <dd className={`font-semibold ${reduced ? 'text-slate-500 line-through' : 'text-slate-900'}`}>{formatQuantity(line.requested_qty)}</dd>
          </div>
          {line.validated_qty !== null && (
            <div className="flex gap-1">
              <dt className="text-slate-600">{t('orders.clientOrders.lines.validated')}</dt>
              <dd className={`font-semibold ${reduced ? 'text-amber-800' : 'text-slate-900'}`}>{formatQuantity(line.validated_qty)}</dd>
            </div>
          )}
          {line.shipped_qty !== null && (
            <div className="flex gap-1">
              <dt className="text-slate-600">{t('orders.clientOrders.lines.shipped')}</dt>
              <dd className={`font-semibold ${gap ? 'text-amber-800' : 'text-slate-900'}`}>{formatQuantity(line.shipped_qty)}</dd>
            </div>
          )}
          <div className="flex gap-1">
            <dt className="text-slate-600">{t('orders.clientOrders.lines.amount')}</dt>
            <dd className="font-semibold text-slate-900">{formatAmount(line.amount_ht)}</dd>
          </div>
        </dl>
        {reduced && (
          <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
            {t('orders.clientOrders.lines.reduced', { from: formatQuantity(line.requested_qty), to: formatQuantity(line.validated_qty ?? 0) })}
            {reason && <> · {t('orders.clientOrders.lines.reason', { reason })}</>}
            {line.adjustment_comment && <span className="mt-1 block">{t('orders.clientOrders.lines.comment', { comment: line.adjustment_comment })}</span>}
          </p>
        )}
        {!reduced && reason && <p className="text-sm text-slate-700">{t('orders.clientOrders.lines.reason', { reason })}</p>}
        {!reduced && line.adjustment_comment && (
          <p className="text-sm text-slate-700">{t('orders.clientOrders.lines.comment', { comment: line.adjustment_comment })}</p>
        )}
        {gap && (
          <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
            {t('orders.clientOrders.lines.shortShipped', { shipped: formatQuantity(line.shipped_qty ?? 0), validated: formatQuantity(line.validated_qty ?? 0) })}
            {gapReason && <> · {t('orders.clientOrders.lines.reason', { reason: gapReason })}</>}
          </p>
        )}
      </div>
    </article>
  )
}

/** Totaux HT, TVA et TTC du serveur, indicatifs (la facture fait foi). */
export function OrderTotals({ order }: { order: Pick<Order, 'total_ht' | 'total_vat' | 'total_ttc'> }) {
  const { t } = useTranslation()
  const titleId = useId()
  const rows = [
    { label: t('orders.clientOrders.totals.ht'), value: order.total_ht },
    { label: t('orders.clientOrders.totals.vat'), value: order.total_vat },
    { label: t('orders.clientOrders.totals.ttc'), value: order.total_ttc, strong: true },
  ]
  return (
    <section aria-labelledby={titleId} className={SECTION}>
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('orders.clientOrders.totals.title')}
      </h2>
      <dl className="flex flex-col gap-1 text-sm">
        {rows.map((row) => (
          <div key={row.label} className={`flex justify-between gap-4 ${row.strong ? 'border-t border-slate-200 pt-2 text-base font-bold' : ''}`}>
            <dt className={row.strong ? 'text-slate-900' : 'text-slate-600'}>{row.label}</dt>
            <dd className="text-slate-900 tabular-nums">{formatAmount(row.value)}</dd>
          </div>
        ))}
      </dl>
      <p className="text-xs text-slate-500">{t('orders.amountsHint')}</p>
    </section>
  )
}

const DECISION_TONES: Record<DecisionKind, string> = {
  REJECTED: 'border-red-200 bg-red-50 text-red-900',
  CANCELLED: 'border-slate-200 bg-slate-50 text-slate-900',
  ADJUSTED: 'border-amber-200 bg-amber-50 text-amber-900',
  other: 'border-slate-200 bg-slate-50 text-slate-900',
}

type DecisionOrder = Pick<
  Order,
  'status' | 'is_adjusted' | 'decided_at' | 'decision_reason' | 'decision_reason_code' | 'decision_comment' | 'history' | 'lines'
>

/**
 * Décision du régional ou de l'ADV (lot 6) : « Validée avec ajustement » (nombre de lignes ajustées, commentaire),
 * motif du rejet, motif de l'annulation exceptionnelle (annulée par l'ADV, I-75) — libellé du motif, commentaire
 * et date. Rien pour une commande envoyée, expirée, annulée par le client ou validée telle quelle.
 */
export function OrderDecision({ order }: { order: DecisionOrder }) {
  const { t } = useTranslation()
  const titleId = useId()
  const kind = decisionKind(order)
  if (kind === null) return null
  const reason = reasonText(order.decision_reason, order.decision_reason_code)
  const at = decisionInstant(order)
  return (
    <section aria-labelledby={titleId} className={`flex flex-col gap-1 rounded-lg border px-4 py-3 text-sm ${DECISION_TONES[kind]}`}>
      <h2 id={titleId} className="font-semibold">
        {t(`orders.clientOrders.decision.${kind}`)}
      </h2>
      {kind === 'ADJUSTED' && (
        <>
          <p>{t('orders.clientOrders.decision.adjustedText')}</p>
          <p>{t('orders.clientOrders.decision.adjustedLines', { count: reducedCount(order.lines), total: order.lines.length })}</p>
        </>
      )}
      {kind === 'CANCELLED' && cancelledByAdv(order) && <p>{t('orders.clientOrders.decision.cancelledByAdv')}</p>}
      {reason && <p>{t('orders.clientOrders.decision.reason', { reason })}</p>}
      {order.decision_comment && <p>{t('orders.clientOrders.decision.comment', { comment: order.decision_comment })}</p>}
      {at && <p className="text-xs opacity-80">{t('orders.clientOrders.decision.at', { date: formatDateTime(at) })}</p>}
    </section>
  )
}

/**
 * Expédition (I-79) : date d'expédition et bon de livraison, une fois la commande expédiée seulement (le serveur
 * ne les envoie pas avant) ; rappelle qu'une quantité expédiée inférieure à la quantité validée est expliquée ligne par ligne.
 */
export function OrderShipping({ order }: { order: Pick<Order, 'status' | 'shipped_at' | 'delivery_note_ref' | 'lines'> }) {
  const { t } = useTranslation()
  const titleId = useId()
  if (!isShipped(order.status) || (!order.shipped_at && !order.delivery_note_ref)) return null
  return (
    <section aria-labelledby={titleId} className={SECTION}>
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('orders.clientOrders.shipping.title')}
      </h2>
      <dl className="flex flex-col gap-1 text-sm">
        {order.shipped_at && (
          <div className="flex flex-wrap gap-x-2">
            <dt className="text-slate-600">{t('orders.clientOrders.shipping.shippedAt')}</dt>
            <dd className="font-semibold text-slate-900">
              <time dateTime={order.shipped_at}>{formatDateTime(order.shipped_at)}</time>
            </dd>
          </div>
        )}
        {order.delivery_note_ref && (
          <div className="flex flex-wrap gap-x-2">
            <dt className="text-slate-600">{t('orders.clientOrders.shipping.deliveryNote')}</dt>
            <dd className="font-semibold text-slate-900">{order.delivery_note_ref}</dd>
          </div>
        )}
      </dl>
      {order.lines.some(hasShippingGap) && <p className="text-sm text-amber-900">{t('orders.clientOrders.shipping.partial')}</p>}
    </section>
  )
}

/** Historique (§6.3), dans l'ordre chronologique : date et heure, transition, auteur (nommé pour les comptes du client), commentaire. */
export function OrderHistory({ history }: { history: OrderHistoryEntry[] }) {
  const { t } = useTranslation()
  const titleId = useId()
  if (history.length === 0) return null
  return (
    <section aria-labelledby={titleId} className={SECTION}>
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('orders.clientOrders.history.title')}
      </h2>
      <ol className="flex flex-col gap-3">
        {history.map((entry, index) => (
          <li key={`${index}-${entry.to}-${entry.version}`} className="flex flex-col gap-0.5 border-s-2 border-slate-200 ps-3 text-sm">
            <span className="text-xs text-slate-600">
              <time dateTime={entry.at}>{formatDateTime(entry.at)}</time>
            </span>
            <span className="font-semibold text-slate-900">{t(historyLabelKey(entry), { version: entry.version })}</span>
            <span className="text-slate-700">
              {entry.by_name ? t('orders.clientOrders.history.by', { name: entry.by_name }) : t(`orders.clientOrders.history.actor.${entry.actor}`)}
            </span>
            {entry.comment && <q className="text-slate-700 italic">{entry.comment}</q>}
          </li>
        ))}
      </ol>
    </section>
  )
}
