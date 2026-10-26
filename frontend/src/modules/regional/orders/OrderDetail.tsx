import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { OrderHistoryEntry, RegionalOrder } from '../../../shared/api/types'
import { formatAmount, formatDate, formatDateTime } from '../../../shared/format'
import { Badge, Card } from '../../../shared/ui'
import { CallLink } from '../clients/components'
import { weekdayOf } from '../quotas/format'
import { BlockedBadge, OrderStatusBadge } from './components'
import type { Outcome } from './decision/api'
import { BlockerAlert } from './decision/banners'
import { DecisionPanel } from './decision/DecisionPanel'
import { DecisionSummary } from './decision/DecisionSummary'
import { LineItem } from './lines'
import { historyEvent } from './model'

const LINK_CLASSES =
  'inline-flex min-h-11 items-center gap-2 rounded-lg font-medium text-brand-700 hover:underline ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'

/**
 * Détail d'une commande reçue (EF-REG-04, EF-REG-05) : client avec appel en un
 * geste, montants, commentaire, décision prise (auteur, motif), lignes avec leur
 * contexte publié du moment (borne, déjà validé pour ce client, quota et reste de
 * la région, §5.1) et historique, tous les auteurs nommés. Une commande envoyée
 * que le régional peut décider (valider, rejeter) montre le panneau de décision à
 * la place des lignes en lecture seule. Écran pensé pour le téléphone.
 */
export function OrderDetail({ order, onOutcome }: { order: RegionalOrder; onOutcome: (outcome: Outcome) => void }) {
  const { t } = useTranslation()
  const submitted = formatDateTime(order.submitted_at)
  const decided = order.decided_at !== null || order.decision_reason_code !== null || order.decision_comment !== null
  const decidable = order.status === 'SUBMITTED' && (order.can_validate || order.can_reject)

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

      {decided && <DecisionSummary order={order} />}

      {/* Envoyée mais pas décidable (journée clôturée, droit absent) : lecture seule, et la raison si le serveur la donne. */}
      {order.status === 'SUBMITTED' && !decidable && order.blocked_reason !== null && (
        <BlockerAlert blocker={order.blocked_reason} order={order} canReject={false} />
      )}

      {decidable ? (
        <DecisionPanel order={order} onOutcome={onOutcome} />
      ) : (
        <Card title={t('orders.regional.detail.lines.title')}>
          <ul aria-label={t('orders.regional.detail.lines.title')} className="flex flex-col divide-y divide-slate-100">
            {order.lines.map((line) => (
              <LineItem key={line.product_id} line={line} />
            ))}
          </ul>
        </Card>
      )}

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
