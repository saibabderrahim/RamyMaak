import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { AdvOrder, OrderHistoryEntry } from '../../../shared/api/types'
import { formatAmount, formatDate, formatDateTime } from '../../../shared/format'
import { reasonText } from '../../../shared/reasons'
import { Alert, Card } from '../../../shared/ui'
import { useBackoffice } from '../shared/access'
import { DetailList } from '../shared/ui'
import { ClientStatusFlag, OrderFlags, OrderStatusBadge } from './components'

// Cartes du détail d'une commande de la file ADV — contrat : docs/lot-6-contrat-api.md §5 et §8.

const TEXT_LINK = 'inline-flex min-h-11 items-center font-medium text-brand-700 hover:underline'

/** Statut, journée, région, jalons du traitement et motif d'une annulation exceptionnelle. */
export function SummaryCard({ order }: { order: AdvOrder }) {
  const { t } = useTranslation()
  const { base, can } = useBackoffice()
  const reason = reasonText(order.decision_reason)

  return (
    <Card
      title={t('backoffice.orders.detail.summary')}
      actions={
        <span className="flex flex-wrap items-center gap-2">
          <OrderStatusBadge status={order.status} />
          <OrderFlags order={order} />
        </span>
      }
    >
      <div className="flex flex-col gap-4">
        {order.status === 'CANCELLED' && (
          <Alert tone="warning">
            <p className="font-semibold">{t('backoffice.orders.detail.cancelled')}</p>
            {reason && <p>{t('backoffice.orders.detail.cancelReason', { reason })}</p>}
            {order.decision_comment && <p>{t('backoffice.orders.detail.cancelComment', { comment: order.decision_comment })}</p>}
          </Alert>
        )}
        {order.is_adjusted && order.status !== 'CANCELLED' && <p className="text-sm text-amber-900">{t('backoffice.orders.detail.adjusted')}</p>}
        <DetailList
          items={[
            {
              label: t('backoffice.orders.detail.day'),
              value: can('dashboard.view') ? (
                <Link to={`${base}/journees/${order.day}/suivi`} className={TEXT_LINK}>
                  {formatDate(order.day)}
                </Link>
              ) : (
                formatDate(order.day)
              ),
            },
            { label: t('backoffice.orders.detail.region'), value: `${order.region.code} — ${order.region.name}` },
            { label: t('backoffice.orders.detail.depot'), value: order.depot ? `${order.depot.code} — ${order.depot.name}` : t('backoffice.orders.detail.noDepot') },
            { label: t('backoffice.orders.detail.submitted'), value: formatDateTime(order.submitted_at) },
            {
              label: t('backoffice.orders.detail.validated'),
              value: order.decided_at ? `${formatDateTime(order.decided_at)}${order.decided_by ? ` — ${order.decided_by.name}` : ''}` : null,
            },
            { label: t('backoffice.orders.detail.prepStarted'), value: order.prep_started_at ? formatDateTime(order.prep_started_at) : null },
            { label: t('backoffice.orders.detail.prepared'), value: order.prepared_at ? formatDateTime(order.prepared_at) : null },
            { label: t('backoffice.orders.detail.shipped'), value: order.shipped_at ? formatDateTime(order.shipped_at) : null },
            { label: t('backoffice.orders.detail.clientComment'), value: order.comment, wide: true },
          ]}
        />
      </div>
    </Card>
  )
}

/** Identité du client : adresse, téléphones (appel en un geste), NIF et RC (RG-A04). */
export function ClientCard({ order }: { order: AdvOrder }) {
  const { t } = useTranslation()
  const { client } = order
  const area = [client.wilaya, client.commune].filter(Boolean).join(' — ')
  const phones = [client.phone, client.phone2].filter((phone): phone is string => Boolean(phone))

  return (
    <Card title={t('backoffice.orders.client.title')}>
      <DetailList
        items={[
          { label: t('backoffice.orders.client.code'), value: client.code },
          { label: t('backoffice.orders.client.name'), value: client.name },
          { label: t('backoffice.orders.client.status'), value: <ClientStatusFlag status={client.status} /> },
          { label: t('backoffice.orders.client.address'), value: client.address, wide: true },
          { label: t('backoffice.orders.client.area'), value: area || null },
          {
            label: t('backoffice.orders.client.phone'),
            value:
              phones.length === 0 ? null : (
                <span className="flex flex-wrap gap-x-3">
                  {phones.map((phone) => (
                    <a key={phone} href={`tel:${phone.replace(/\s+/g, '')}`} className="text-brand-700 hover:underline">
                      {phone}
                    </a>
                  ))}
                </span>
              ),
          },
          { label: t('backoffice.orders.client.nif'), value: client.nif },
          { label: t('backoffice.orders.client.rc'), value: client.rc },
        ]}
      />
    </Card>
  )
}

/** Totaux HT, TVA et TTC du serveur : indicatifs, la facture ERP fait foi (RG-P05). */
export function TotalsCard({ order }: { order: Pick<AdvOrder, 'total_ht' | 'total_vat' | 'total_ttc'> }) {
  const { t } = useTranslation()
  const rows = [
    { label: t('backoffice.orders.totals.ht'), value: order.total_ht },
    { label: t('backoffice.orders.totals.vat'), value: order.total_vat },
    { label: t('backoffice.orders.totals.ttc'), value: order.total_ttc, strong: true },
  ]
  return (
    <Card title={t('backoffice.orders.totals.title')}>
      <dl className="flex flex-col gap-1 text-sm">
        {rows.map((row) => (
          <div key={row.label} className={`flex justify-between gap-4 ${row.strong ? 'border-t border-slate-200 pt-2 text-base font-bold' : ''}`}>
            <dt className={row.strong ? 'text-slate-900' : 'text-slate-600'}>{row.label}</dt>
            <dd className="text-slate-900 tabular-nums">{formatAmount(row.value)}</dd>
          </div>
        ))}
      </dl>
      <p className="mt-3 text-xs text-slate-500">{t('backoffice.orders.totals.hint')}</p>
    </Card>
  )
}

/** Historique dans l'ordre chronologique : date et heure, étape, auteur nommé, commentaire. */
export function HistoryCard({ history }: { history: OrderHistoryEntry[] }) {
  const { t } = useTranslation()
  if (history.length === 0) return null
  /** Envoi, modification par le client (même statut) ou statut atteint. */
  const label = (entry: OrderHistoryEntry) =>
    entry.from === null
      ? t('backoffice.orders.history.created')
      : entry.from === entry.to
        ? t('backoffice.orders.history.modified', { version: entry.version })
        : t(`orders.status.${entry.to}`)
  return (
    <Card title={t('backoffice.orders.history.title')}>
      <ol className="flex flex-col gap-3">
        {history.map((entry, index) => (
          <li key={`${index}-${entry.to}-${entry.version}`} className="flex flex-col gap-0.5 border-s-2 border-slate-200 ps-3 text-sm">
            <time dateTime={entry.at} className="text-xs text-slate-600">
              {formatDateTime(entry.at)}
            </time>
            <span className="font-semibold text-slate-900">{label(entry)}</span>
            <span className="text-slate-700">
              {entry.by_name ? t('backoffice.orders.history.by', { name: entry.by_name }) : t('backoffice.orders.history.by', { name: t(`backoffice.orders.history.actor.${entry.actor}`) })}
            </span>
            {entry.comment && <q className="text-slate-700 italic">{entry.comment}</q>}
          </li>
        ))}
      </ol>
    </Card>
  )
}
