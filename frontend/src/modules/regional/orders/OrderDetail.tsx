import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { OrderHistoryEntry, RegionalOrder } from '../../../shared/api/types'
import { formatAmount, formatDate, formatDateTime } from '../../../shared/format'
import { Badge, Card } from '../../../shared/ui'
import { ProductImage } from '../../../shared/ui/ProductImage'
import { CallLink } from '../clients/components'
import { formatQuantity, weekdayOf } from '../quotas/format'
import { BlockedBadge, OrderStatusBadge } from './components'
import { historyEvent } from './model'

const LINK_CLASSES =
  'inline-flex min-h-11 items-center gap-2 rounded-lg font-medium text-brand-700 hover:underline ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

type Line = RegionalOrder['lines'][number]

/**
 * Détail d'une commande reçue, en lecture seule (EF-REG-04, EF-REG-05 sans
 * décision) : client avec appel en un geste, montants, commentaire, lignes
 * avec leur contexte publié du moment (borne, déjà validé pour ce client,
 * quota et reste de la région, §5.1) et historique, tous les auteurs nommés.
 * Écran pensé pour le téléphone.
 */
export function OrderDetail({ order }: { order: RegionalOrder }) {
  const { t } = useTranslation()
  const submitted = formatDateTime(order.submitted_at)
  const decided = order.decided_at !== null || order.decision_reason_code !== null || order.decision_comment !== null

  return (
    <>
      <header className="flex flex-col gap-1">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <h1 className="text-2xl font-bold text-slate-900">{t('orders.regional.detail.title', { number: order.number })}</h1>
          <span className="flex flex-wrap gap-1">
            <OrderStatusBadge status={order.status} />
            {order.is_adjusted && <Badge tone="brand">{t('orders.regional.list.adjusted')}</Badge>}
          </span>
        </div>
        <p className="text-sm text-slate-700">{t('orders.regional.detail.day', { date: `${weekdayOf(order.day)} ${formatDate(order.day)}` })}</p>
        <p className="text-sm text-slate-600">
          {order.submitted_by
            ? t('orders.regional.detail.submittedBy', { date: submitted, name: order.submitted_by.name })
            : t('orders.regional.detail.submitted', { date: submitted })}
        </p>
      </header>

      <div className="grid gap-4 md:grid-cols-2">
        <ClientCard order={order} />
        <TotalsCard order={order} />
      </div>

      {order.comment && (
        <Card title={t('orders.regional.detail.comment')}>
          <p className="whitespace-pre-line break-words text-sm text-slate-900">{order.comment}</p>
        </Card>
      )}

      {decided && (
        <Card title={t('orders.regional.detail.decision.title')}>
          <div className="flex flex-col gap-1 text-sm text-slate-800">
            {order.decided_at && <p>{t('orders.regional.detail.decision.at', { date: formatDateTime(order.decided_at) })}</p>}
            {order.decision_reason_code && <p>{t('orders.regional.detail.decision.reason', { reason: order.decision_reason_code })}</p>}
            {order.decision_comment && <p>{t('orders.regional.detail.decision.comment', { comment: order.decision_comment })}</p>}
          </div>
        </Card>
      )}

      <Card title={t('orders.regional.detail.lines.title')}>
        <ul aria-label={t('orders.regional.detail.lines.title')} className="flex flex-col divide-y divide-slate-100">
          {order.lines.map((line) => (
            <LineItem key={line.product_id} line={line} />
          ))}
        </ul>
      </Card>

      <Card title={t('orders.regional.detail.history.title')}>
        <ol aria-label={t('orders.regional.detail.history.title')} className="flex flex-col gap-3">
          {order.history.map((entry, index) => (
            <HistoryItem key={`${index}-${entry.version}-${entry.at}`} entry={entry} />
          ))}
        </ol>
      </Card>
    </>
  )
}

/** Client : raison sociale, code, région de la commande (figée à l'envoi), téléphones avec appel en un geste. */
function ClientCard({ order }: { order: RegionalOrder }) {
  const { t } = useTranslation()
  const { client, region } = order
  const phones = [
    { label: t('orders.regional.detail.client.phone'), phone: client.phone },
    { label: t('orders.regional.detail.client.phone2'), phone: client.phone2 },
  ].filter((entry): entry is { label: string; phone: string } => Boolean(entry.phone?.trim()))

  return (
    <Card title={t('orders.regional.detail.client.title')}>
      <div className="flex flex-col gap-3">
        <div className="flex flex-col gap-1">
          <p className="flex flex-wrap items-center gap-2">
            <span className="text-base font-semibold text-slate-900">{client.name}</span>
            {client.status === 'BLOCKED' && <BlockedBadge />}
          </p>
          <p className="text-sm text-slate-600">
            {client.code} · {t('orders.regional.detail.client.region', { name: region.name, code: region.code })}
          </p>
        </div>

        {phones.length === 0 ? (
          <p className="text-sm text-slate-600">{t('orders.regional.detail.client.noPhone')}</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {phones.map(({ label, phone }) => (
              <li key={label} className="flex items-center justify-between gap-3">
                <span className="flex flex-col text-sm">
                  <span className="text-slate-500">{label}</span>
                  <span dir="ltr" className="text-base font-medium text-slate-900">
                    {phone}
                  </span>
                </span>
                <CallLink phone={phone} name={client.name} />
              </li>
            ))}
          </ul>
        )}

        <Link to={`/regional/clients/${client.id}`} className={`${LINK_CLASSES} self-start`}>
          {t('orders.regional.detail.client.fiche')}
        </Link>
      </div>
    </Card>
  )
}

/** Montants de la commande, indicatifs (prix figés à l'envoi, TVA au centime). */
function TotalsCard({ order }: { order: RegionalOrder }) {
  const { t } = useTranslation()
  const rows = [
    { label: t('orders.regional.detail.totals.ht'), value: order.total_ht },
    { label: t('orders.regional.detail.totals.vat'), value: order.total_vat },
    { label: t('orders.regional.detail.totals.ttc'), value: order.total_ttc },
  ]
  return (
    <Card title={t('orders.regional.detail.totals.title')}>
      <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
        {rows.map(({ label, value }, index) => {
          const total = index === rows.length - 1
          return (
            <div key={label} className="contents">
              <dt className={total ? 'font-semibold text-slate-900' : 'text-slate-600'}>{label}</dt>
              <dd className={`text-right tabular-nums text-slate-900 ${total ? 'font-semibold' : ''}`}>{formatAmount(value)}</dd>
            </div>
          )
        })}
      </dl>
      <p className="mt-3 text-xs text-slate-500">{t('orders.amountsHint')}</p>
    </Card>
  )
}

/**
 * Ligne : produit, quantité demandée (puis validée et expédiée dès qu'elles
 * existent), contexte publié — borne B(c,p) ou « Pas de borne », validé
 * V(c,p), quota Q(r,p) et reste R(r,p) de la région —, prix unitaire figé et
 * montant HT.
 */
function LineItem({ line }: { line: Line }) {
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

/** Entrée de l'historique : événement, date, auteur nommé (ou « automatiquement »), commentaire. */
function HistoryItem({ entry }: { entry: OrderHistoryEntry }) {
  const { t } = useTranslation()
  const event = historyEvent(entry)
  const label =
    event === 'created'
      ? t('orders.regional.detail.history.created')
      : event === 'modified'
        ? t('orders.regional.detail.history.modified', { version: entry.version })
        : t(`orders.regional.detail.history.to.${event}`)
  const role = t(`orders.regional.detail.history.role.${entry.actor}`)
  const by =
    entry.by_name !== null
      ? t('orders.regional.detail.history.by', { name: entry.by_name, role })
      : entry.actor === 'SYSTEM'
        ? t('orders.regional.detail.history.automatic')
        : t('orders.regional.detail.history.byRole', { role })

  return (
    <li className="flex flex-col gap-0.5 border-s-2 border-slate-200 ps-3 text-sm">
      <p className="font-medium text-slate-900">{label}</p>
      <p className="text-xs text-slate-600">
        {formatDateTime(entry.at)} · {by}
      </p>
      {entry.comment && <p className="text-slate-700">{t('orders.regional.detail.history.comment', { comment: entry.comment })}</p>}
    </li>
  )
}
