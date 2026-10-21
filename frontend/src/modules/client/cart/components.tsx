import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { ClientToday } from '../../../shared/api/types'
import { formatAmount, formatQuantity } from '../../../shared/format'
import type { Totals } from '../../../shared/money'
import { Alert, Button, Spinner } from '../../../shared/ui'
import { formatDayLong } from './format'
import { type CartItem, cartTotals, lineAmount } from './reconcile'
import type { CartNotice } from './sync'

// Éléments communs à l'accueil, au catalogue et au panier du client.

const LINK_BUTTON =
  'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold transition-colors ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'
export const PRIMARY_LINK = `${LINK_BUTTON} bg-brand-600 text-white hover:bg-brand-700`
export const SECONDARY_LINK = `${LINK_BUTTON} border border-slate-300 bg-white text-slate-800 hover:bg-slate-50`

/** État de la journée (EF-CLT-01, I-43) : « Commandes ouvertes jusqu'à 13:00 (prolongée) », etc. */
export function DayStatusText({ today }: { today: Pick<ClientToday, 'status' | 'ordering_cutoff' | 'ordering_cutoff_extended'> }) {
  const { t } = useTranslation()
  if (today.status !== 'ORDERING_OPEN') return <>{t(`orders.dayStatus.${today.status}`, { time: today.ordering_cutoff ?? '' })}</>
  return (
    <>
      {t('orders.dayStatus.ORDERING_OPEN', { time: today.ordering_cutoff ?? '' })}
      {today.ordering_cutoff_extended && ` (${t('orders.extended')})`}
    </>
  )
}

/** « Commande du jeudi 08/10/2026 · Livraison le dimanche 11/10/2026 » et l'état de la journée. */
export function DaySummary({ today }: { today: ClientToday }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-0.5 text-sm text-slate-700">
      <p>
        <span className="font-semibold text-slate-900">{t('orders.client.day.title', { date: formatDayLong(today.day) })}</span>
        {today.delivery_date && <> · {t('orders.client.day.delivery', { date: formatDayLong(today.delivery_date) })}</>}
      </p>
      <p>
        <DayStatusText today={today} />
      </p>
    </div>
  )
}

/**
 * Journée en cours de lecture. Hors ligne sans données en mémoire, la
 * lecture attend le réseau (`fetchStatus` « paused ») : un avis remplace
 * l'attente, le panier restant sur l'appareil (cache des données au lot 7).
 */
export function TodayLoading({ paused, label }: { paused: boolean; label?: string }) {
  const { t } = useTranslation()
  return paused ? <Alert tone="warning">{t('orders.client.offlineData')}</Alert> : <Spinner label={label} />
}

/** Avis après un changement du panier venu du serveur (rapprochement, journée changée). */
export function CartNoticeAlert({ notice, onDismiss }: { notice: CartNotice; onDismiss: () => void }) {
  const { t } = useTranslation()
  return (
    <div role="status" className="flex items-start justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      <div className="min-w-0">
        {notice.kind === 'reconciled' ? (
          <>
            <p className="font-semibold">{t('orders.client.sync.title')}</p>
            <ul className="mt-1 list-disc ps-5">
              {notice.changes.map((change) => (
                <li key={change.productId}>
                  {change.kind === 'capped'
                    ? t('orders.client.sync.capped', { name: change.name, from: formatQuantity(change.from), to: formatQuantity(change.to) })
                    : change.name === null
                      ? t('orders.client.sync.removedUnknown')
                      : t('orders.client.sync.removed', { name: change.name })}
                </li>
              ))}
            </ul>
          </>
        ) : (
          <p>{t(notice.kind === 'dayChanged' ? 'orders.client.sync.dayChanged' : 'orders.client.sync.dayClosed', { date: formatDayLong(notice.day) })}</p>
        )}
      </div>
      <button
        type="button"
        onClick={onDismiss}
        aria-label={t('orders.client.sync.dismiss')}
        className="-me-2 -mt-2 inline-flex size-11 shrink-0 items-center justify-center rounded-lg text-xl hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-brand-600"
      >
        <span aria-hidden="true">×</span>
      </button>
    </div>
  )
}

/** Bandeau de la modification d'une commande envoyée, avec « Abandonner la modification ». */
export function EditingBanner({ number, modifiable, onAbandon }: { number: string; modifiable: boolean; onAbandon: () => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
      <div className="min-w-0">
        <p className="font-semibold">{t('orders.client.editing.banner', { number })}</p>
        <p>{modifiable ? t('orders.client.editing.hint') : t('orders.client.editing.notModifiable')}</p>
      </div>
      <Button variant="secondary" onClick={onAbandon}>
        {t('orders.client.editing.abandon')}
      </Button>
    </div>
  )
}

/** Totaux HT, TVA et TTC, indicatifs (RG-P05). */
export function TotalsList({ totals }: { totals: Totals }) {
  const { t } = useTranslation()
  const rows = [
    { label: t('orders.client.cart.totalHt'), value: totals.ht },
    { label: t('orders.client.cart.totalVat'), value: totals.vat },
    { label: t('orders.client.cart.totalTtc'), value: totals.ttc, strong: true },
  ]
  return (
    <div className="flex flex-col gap-1">
      <dl className="flex flex-col gap-1 text-sm" aria-label={t('orders.client.cart.totals')}>
        {rows.map((row) => (
          <div key={row.label} className={`flex justify-between gap-3 ${row.strong ? 'text-base font-semibold text-slate-900' : 'text-slate-700'}`}>
            <dt>{row.label}</dt>
            <dd className="tabular-nums">{formatAmount(row.value)}</dd>
          </div>
        ))}
      </dl>
      <p className="text-xs text-slate-500">{t('orders.amountsHint')}</p>
    </div>
  )
}

/** Panier en panneau latéral du catalogue, sur PC (à partir de 1 024 px). */
export function CartPanel({ items }: { items: readonly CartItem[] }) {
  const { t } = useTranslation()
  const titleId = useId()
  return (
    <aside aria-labelledby={titleId} className="sticky top-20 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('orders.client.panel.title')}
      </h2>
      {items.length === 0 ? (
        <p className="text-sm text-slate-600">{t('orders.client.panel.empty')}</p>
      ) : (
        <>
          <ul className="flex max-h-[50dvh] flex-col gap-2 overflow-y-auto text-sm">
            {items.map((item) => (
              <li key={item.product.id} className="flex justify-between gap-3">
                <span className="min-w-0">{t('orders.client.panel.line', { quantity: formatQuantity(item.line.quantity), name: item.product.name })}</span>
                <span className="shrink-0 tabular-nums text-slate-700">{formatAmount(lineAmount(item))}</span>
              </li>
            ))}
          </ul>
          <TotalsList totals={cartTotals(items)} />
          <Link to="/client/panier" className={PRIMARY_LINK}>
            {t('orders.client.panel.open')}
          </Link>
        </>
      )}
    </aside>
  )
}
