import type { QueryKey } from '@tanstack/react-query'
import { type KeyboardEvent, useEffect, useId, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { OrderStatus, RegionalOrderSummary, RegionRef } from '../../../shared/api/types'
import { formatAmount, formatDate } from '../../../shared/format'
import { useLiveRefresh } from '../../../shared/realtime'
import { Badge, Select } from '../../../shared/ui'
import { formatQuantity } from '../quotas/format'
import { type OrderCounts, ORDER_TABS, type OrderTab, orderPath, submissionTime } from './model'

const STATUS_TONES: Record<OrderStatus, 'warning' | 'success' | 'danger' | 'neutral'> = {
  SUBMITTED: 'warning',
  VALIDATED: 'success',
  IN_PREPARATION: 'success',
  PREPARED: 'success',
  SHIPPED: 'success',
  DELIVERED: 'success',
  REJECTED: 'danger',
  CANCELLED: 'neutral',
  EXPIRED: 'neutral',
}

const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

/** Statut d'une commande (§6.2) : à valider, validée et suivantes, rejetée, fins sans décision. */
export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  const { t } = useTranslation()
  return <Badge tone={STATUS_TONES[status]}>{t(`orders.status.${status}`)}</Badge>
}

/** Client bloqué (§3.5) : il ne peut plus envoyer ni modifier de commande. */
export function BlockedBadge() {
  const { t } = useTranslation()
  return <Badge tone="danger">{t('orders.regional.list.blocked')}</Badge>
}

/** Bascule Aujourd’hui / Demain (`?day=`), comme les autres écrans du régional. */
export function DayToggle({ day, today, tomorrow, onChange }: { day: string; today: string; tomorrow: string; onChange: (day: string) => void }) {
  const { t } = useTranslation()
  return (
    <div role="group" aria-label={t('orders.regional.dayChoice')} className="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 sm:max-w-md">
      {[
        { value: today, label: t('orders.regional.today') },
        { value: tomorrow, label: t('orders.regional.tomorrow') },
      ].map(({ value, label }) => (
        <button
          key={value}
          type="button"
          aria-pressed={day === value}
          onClick={() => onChange(value)}
          className={`flex min-h-12 flex-col items-center justify-center rounded-lg px-3 py-1 text-sm font-semibold ${FOCUS} ${
            day === value ? 'bg-white text-brand-800 shadow-xs' : 'text-slate-700 hover:bg-slate-200'
          }`}
        >
          {label}
          <span className="text-xs font-normal text-slate-600">{formatDate(value)}</span>
        </button>
      ))}
    </div>
  )
}

/** Choix de la région quand le périmètre du jour en compte plusieurs ; `null` : toutes. */
export function RegionFilter({
  regions,
  value,
  onChange,
}: {
  regions: RegionRef[]
  value: number | null
  onChange: (region: number | null) => void
}) {
  const { t } = useTranslation()
  const id = useId()
  return (
    <div className="flex flex-col gap-1 sm:max-w-md">
      <label htmlFor={id} className="text-sm font-medium text-slate-800">
        {t('orders.regional.region')}
      </label>
      <Select id={id} value={value ?? ''} onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))}>
        <option value="">{t('orders.regional.allRegions')}</option>
        {regions.map((region) => (
          <option key={region.id} value={region.id}>
            {t('orders.regional.regionOption', { code: region.code, name: region.name })}
          </option>
        ))}
      </Select>
    </div>
  )
}

/**
 * Onglets de la liste (motif « onglets » WAI-ARIA : flèches, Début, Fin),
 * avec leur compteur dès qu'il est connu. Deux colonnes sur téléphone.
 */
export function OrderTabs({
  active,
  counts,
  panelId,
  onSelect,
}: {
  active: OrderTab
  counts: OrderCounts | null
  panelId: string
  onSelect: (tab: OrderTab) => void
}) {
  const { t } = useTranslation()
  const buttons = useRef<(HTMLButtonElement | null)[]>([])

  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const last = ORDER_TABS.length - 1
    const targets: Record<string, number> = {
      ArrowRight: index === last ? 0 : index + 1,
      ArrowLeft: index === 0 ? last : index - 1,
      Home: 0,
      End: last,
    }
    const target = targets[event.key]
    if (target === undefined) return
    event.preventDefault()
    buttons.current[target]?.focus()
    onSelect(ORDER_TABS[target]!)
  }

  return (
    <div
      role="tablist"
      aria-label={t('orders.regional.tabs.label')}
      className="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 sm:flex sm:flex-wrap"
    >
      {ORDER_TABS.map((tab, index) => {
        const selected = tab.key === active.key
        const count = counts?.[tab.key]
        return (
          <button
            key={tab.key}
            ref={(element) => {
              buttons.current[index] = element
            }}
            type="button"
            role="tab"
            id={`${panelId}-${tab.slug}`}
            aria-selected={selected}
            aria-controls={panelId}
            tabIndex={selected ? 0 : -1}
            onClick={() => onSelect(tab)}
            onKeyDown={(event) => onKeyDown(event, index)}
            className={`flex min-h-11 items-center justify-center gap-2 rounded-lg px-3 py-1 text-sm font-semibold sm:flex-1 ${FOCUS} ${
              selected ? 'bg-white text-brand-800 shadow-xs' : 'text-slate-700 hover:bg-slate-200'
            }`}
          >
            <span>{t(`orders.regional.tabs.${tab.key}`)}</span>
            {count !== undefined && (
              <span
                className={`min-w-6 rounded-full px-1.5 text-center text-xs tabular-nums ${
                  tab.key === 'SUBMITTED' && count > 0 ? 'bg-brand-600 text-white' : 'bg-slate-200 text-slate-700'
                }`}
              >
                {count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}

/**
 * Abonnement au canal `region-day.{regionId}.{day}` (§9.6) : les événements
 * `events` relisent les requêtes `queryKeys`, ainsi que le retour de la
 * connexion après une coupure. Signale l'intervalle de secours (30 s hors
 * connexion temps réel) ; `null` au départ.
 */
export function RegionOrdersLive({
  regionId,
  day,
  events,
  queryKeys,
  onInterval,
}: {
  regionId: number
  day: string
  events: readonly string[]
  queryKeys: readonly QueryKey[]
  onInterval: (regionId: number, interval: number | false | null) => void
}) {
  const interval = useLiveRefresh(`region-day.${regionId}.${day}`, events, queryKeys)
  useEffect(() => {
    onInterval(regionId, interval)
  }, [regionId, interval, onInterval])
  useEffect(() => () => onInterval(regionId, null), [regionId, onInterval])
  return null
}

type ListProps = {
  orders: RegionalOrderSummary[]
  day: string
  label: string
  showRegion: boolean
  /** Paramètres de la liste, repris par le lien de retour du détail. */
  listSearch: string
}

/** Client de la commande : code, raison sociale, « Bloqué ». */
function ClientCell({ client }: { client: RegionalOrderSummary['client'] }) {
  return (
    <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
      <span className="text-slate-600">{client.code}</span>
      <span className="font-medium text-slate-900">{client.name}</span>
      {client.status === 'BLOCKED' && <BlockedBadge />}
    </span>
  )
}

/** Liste sur téléphone : une carte par commande, toute la carte ouvre le détail. */
export function OrderCards({ orders, day, label, showRegion, listSearch }: ListProps) {
  const { t } = useTranslation()
  return (
    <ul aria-label={label} className="flex flex-col gap-3">
      {orders.map((order) => (
        <li key={order.id}>
          <Link
            to={orderPath(order.id)}
            state={{ list: listSearch }}
            className={`flex min-h-11 flex-col gap-1 rounded-xl border border-slate-200 bg-white p-3 shadow-xs hover:bg-slate-50 ${FOCUS}`}
          >
            <span className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-semibold text-slate-900">{order.number}</span>
              <span className="flex flex-wrap gap-1">
                {order.is_adjusted && <Badge tone="brand">{t('orders.regional.list.adjusted')}</Badge>}
                <OrderStatusBadge status={order.status} />
              </span>
            </span>
            <ClientCell client={order.client} />
            <span className="text-sm text-slate-600">
              {[
                submissionTime(order.submitted_at, day),
                t('orders.regional.list.lines', { count: order.lines_count }),
                t('orders.regional.list.totalHt', { amount: formatAmount(order.total_ht) }),
                showRegion ? order.region.name : null,
              ]
                .filter(Boolean)
                .join(' · ')}
            </span>
          </Link>
        </li>
      ))}
    </ul>
  )
}

/** Liste sur PC et tablette : tableau, le numéro ouvre le détail. */
export function OrdersTable({ orders, day, label, showRegion, listSearch }: ListProps) {
  const { t } = useTranslation()
  const header = 'px-4 py-3 text-left text-xs font-semibold text-slate-600'
  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-xs">
      <table className="w-full text-sm">
        <caption className="sr-only">{label}</caption>
        <thead className="bg-slate-50">
          <tr>
            <th scope="col" className={header}>
              {t('orders.regional.list.number')}
            </th>
            <th scope="col" className={header}>
              {t('orders.regional.list.client')}
            </th>
            {showRegion && (
              <th scope="col" className={header}>
                {t('orders.regional.list.region')}
              </th>
            )}
            <th scope="col" className={header}>
              {t('orders.regional.list.submittedAt')}
            </th>
            <th scope="col" className={`${header} text-right`}>
              {t('orders.regional.list.linesColumn')}
            </th>
            <th scope="col" className={`${header} text-right`}>
              {t('orders.regional.list.total')}
            </th>
            <th scope="col" className={header}>
              {t('orders.regional.list.status')}
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {orders.map((order) => (
            <tr key={order.id} className="hover:bg-slate-50">
              <td className="px-4 py-1">
                <Link
                  to={orderPath(order.id)}
                  state={{ list: listSearch }}
                  className={`inline-flex min-h-11 items-center whitespace-nowrap rounded font-semibold text-brand-700 hover:underline ${FOCUS}`}
                >
                  {order.number}
                </Link>
              </td>
              <td className="px-4 py-1">
                <ClientCell client={order.client} />
              </td>
              {showRegion && <td className="px-4 py-1 text-slate-700">{order.region.name}</td>}
              <td className="whitespace-nowrap px-4 py-1 tabular-nums text-slate-700">{submissionTime(order.submitted_at, day)}</td>
              <td className="px-4 py-1 text-right tabular-nums text-slate-700">{formatQuantity(order.lines_count)}</td>
              <td className="whitespace-nowrap px-4 py-1 text-right tabular-nums text-slate-900">{formatAmount(order.total_ht)}</td>
              <td className="px-4 py-1">
                <span className="flex flex-wrap gap-1">
                  <OrderStatusBadge status={order.status} />
                  {order.is_adjusted && <Badge tone="brand">{t('orders.regional.list.adjusted')}</Badge>}
                </span>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
