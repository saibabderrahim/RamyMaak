import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import type { AdvOrderStatus, AdvOrderSummary, ProcessingStatus } from '../../../shared/api/types'
import { businessToday, formatAmount, formatDate, formatDateTime } from '../../../shared/format'
import { ADV_TO_PREPARE_KEY, useCoalescedRefresh } from '../../../shared/orders'
import { useOnline } from '../../../shared/pwa/online'
import { FALLBACK_REFRESH_MS, useRealtimeStatus } from '../../../shared/realtime'
import { Alert, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useDebouncedValue } from '../../../shared/useDebouncedValue'
import { Pagination } from '../../admin/components'
import { useBackoffice } from '../shared/access'
import { DataTable, EmptyState, TabPanel, Tabs, TableHead, Th } from '../shared/ui'
import { ordersListsKey, QUEUE_EVENTS, useAdvOrders, useBulkStatus } from './api'
import { BulkBar, type BulkReport, BulkReportPanel } from './BulkBar'
import { ClientStatusFlag, OrderFlags, OrderStatusBadge } from './components'
import {
  activeFilters,
  daysOf,
  MAX_SELECTION,
  pageToggled,
  planBulk,
  QUEUE_TABS,
  type QueueParams,
  type QueueTabId,
  readQueueParams,
  type Selection,
  tabById,
  toggled,
  writeQueueParams,
} from './model'
import { QueueFilters } from './QueueFilters'

const CHECKBOX_CLASSES = 'size-5 cursor-pointer rounded border-slate-300 accent-brand-600 disabled:cursor-not-allowed'

const NO_SELECTION: Selection = new Map()

/** Abonnement à `adv.{jour}` d'une journée affichée par la file (aucun rendu). */
function DayLive({ day }: { day: string }) {
  useCoalescedRefresh(`adv.${day}`, QUEUE_EVENTS, [ordersListsKey, ADV_TO_PREPARE_KEY])
  return null
}

/**
 * File des commandes validées (EF-ADV-04, RG-A01, RG-A02) : onglets par étape
 * avec leurs compteurs, filtres (journée, région, dépôt, client, produit),
 * sélection multiple et changement de statut en masse avec compte rendu.
 * Lecture seule pour l'Admin (sans `order.process`). Tenue à jour par
 * `adv.{jour}` de chaque journée affichée ; hors ligne, le traitement est
 * désactivé.
 */
export default function OrdersQueuePage() {
  const { t } = useTranslation()
  const { can } = useBackoffice()
  const online = useOnline()
  const [searchParams, setSearchParams] = useSearchParams()
  const params = readQueueParams(searchParams)
  const tab = tabById(params.tab)
  const selectable = can('order.process')

  const [search, setSearch] = useState('')
  const [selection, setSelection] = useState<Selection>(NO_SELECTION)
  const [report, setReport] = useState<BulkReport | null>(null)

  const status = useRealtimeStatus()
  // Recherche libre retardée : une requête par pause de frappe, pas par touche.
  const orders = useAdvOrders(params, useDebouncedValue(search), status === 'connected' ? false : FALLBACK_REFRESH_MS)
  const bulk = useBulkStatus()

  const rows: AdvOrderSummary[] = orders.data?.data ?? []
  const counts = orders.data?.meta.counts
  // Autre onglet en cours de chargement : ses compteurs seuls sont déjà connus.
  const switching = orders.isPlaceholderData && rows.length === 0
  // Journées affichées (la file court sur plusieurs journées, I-90), plus aujourd'hui : nouvelles validations du jour.
  const liveDays = [...new Set([businessToday(), ...daysOf(rows)])].sort()

  const update = (patch: Partial<QueueParams>) => {
    setSearchParams(writeQueueParams(searchParams, patch), { replace: true })
    if (!('page' in patch)) {
      setSelection(NO_SELECTION)
      setReport(null)
      bulk.reset()
    }
  }

  const plan = planBulk(selection, rows, tab.next ?? 'IN_PREPARATION')

  const run = (target: ProcessingStatus) => {
    const current = planBulk(selection, rows, target)
    if (current.items.length === 0) return
    bulk.mutate(
      { status: target, orders: current.items },
      {
        onSuccess: (result) => {
          setReport({ target, result })
          setSelection(NO_SELECTION)
        },
      },
    )
  }

  const pageIds = rows.map((row) => row.id)
  const selectedOnPage = pageIds.filter((id) => selection.has(id))
  const allOnPage = rows.length > 0 && selectedOnPage.length === rows.length

  return (
    <>
      <PageHeader title={t('backoffice.orders.title')} />
      <p className="-mt-3 mb-4 text-sm text-slate-600">{t('backoffice.orders.intro')}</p>
      {!selectable && (
        <Alert className="mb-4" tone="info">
          {t('backoffice.orders.readOnly')}
        </Alert>
      )}
      {!online && (
        <Alert className="mb-4" tone="warning">
          {t('backoffice.orders.offline')}
        </Alert>
      )}

      <Tabs
        id="queue"
        label={t('backoffice.orders.tabs.label')}
        tabs={QUEUE_TABS.map((item) => ({
          id: item.id,
          label: counts
            ? t('backoffice.orders.tabs.withCount', { label: t(`backoffice.orders.tabs.${item.id}`), count: counts[item.status] })
            : t(`backoffice.orders.tabs.${item.id}`),
        }))}
        value={params.tab}
        onChange={(id: QueueTabId) => update({ tab: id })}
      />

      <TabPanel id="queue" tab={params.tab}>
        <Card>
          <QueueFilters
            params={params}
            search={search}
            onSearch={(text) => {
              setSearch(text)
              update({})
            }}
            onChange={update}
            onReset={() => {
              setSearch('')
              update({ day: '', region: '', depot: '', product: '' })
            }}
          />

          {report && <BulkReportPanel report={report} onClose={() => setReport(null)} />}
          {selectable && (
            <BulkBar
              target={tab.next}
              selected={selection.size}
              plan={plan}
              pending={bulk.isPending}
              online={online}
              error={bulk.error}
              onRun={() => tab.next && run(tab.next)}
              onClear={() => setSelection(NO_SELECTION)}
            />
          )}

          <ErrorAlert error={orders.error} className="mb-4" />
          {(orders.isPending || switching) && <Spinner label={t('backoffice.orders.list.loading')} />}

          {orders.data && !switching && (
            <>
              <DataTable label={t('backoffice.orders.list.label')} className="min-w-[960px]">
                <TableHead>
                  {selectable && (
                    <Th className="w-10">
                      <input
                        type="checkbox"
                        className={CHECKBOX_CLASSES}
                        aria-label={t('backoffice.orders.bulk.selectPage')}
                        checked={allOnPage}
                        disabled={rows.length === 0}
                        ref={(element) => {
                          if (element) element.indeterminate = selectedOnPage.length > 0 && !allOnPage
                        }}
                        onChange={() => setSelection((current) => pageToggled(current, rows))}
                      />
                    </Th>
                  )}
                  <Th>{t('backoffice.orders.columns.number')}</Th>
                  <Th>{t('backoffice.orders.columns.day')}</Th>
                  <Th>{t('backoffice.orders.columns.client')}</Th>
                  <Th>{t('backoffice.orders.columns.region')}</Th>
                  <Th className="text-end">{t('backoffice.orders.columns.lines')}</Th>
                  <Th className="text-end">{t('backoffice.orders.columns.total')}</Th>
                  <Th>{t('backoffice.orders.columns.validated')}</Th>
                  {(tab.status === 'IN_PREPARATION' || tab.status === 'PREPARED' || tab.status === 'SHIPPED') && (
                    <Th>{t(`backoffice.orders.columns.${tab.status}`)}</Th>
                  )}
                </TableHead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((order) => (
                    <OrderRow
                      key={order.id}
                      order={order}
                      tab={tab.status}
                      selectable={selectable}
                      checked={selection.has(order.id)}
                      full={selection.size >= MAX_SELECTION}
                      onToggle={() => setSelection((current) => toggled(current, order))}
                    />
                  ))}
                </tbody>
              </DataTable>
              {rows.length === 0 && (
                <EmptyState>{activeFilters(params, search) > 0 ? t('backoffice.orders.list.emptyFiltered') : t('backoffice.orders.list.empty')}</EmptyState>
              )}
              <Pagination meta={orders.data.meta} onPage={(page) => update({ page })} />
            </>
          )}
        </Card>
      </TabPanel>

      {liveDays.map((day) => (
        <DayLive key={day} day={day} />
      ))}
    </>
  )
}

function OrderRow({
  order,
  tab,
  selectable,
  checked,
  full,
  onToggle,
}: {
  order: AdvOrderSummary
  tab: AdvOrderStatus
  selectable: boolean
  checked: boolean
  full: boolean
  onToggle: () => void
}) {
  const { t } = useTranslation()
  const { base, can } = useBackoffice()
  const milestone = tab === 'IN_PREPARATION' ? order.prep_started_at : tab === 'PREPARED' ? order.prepared_at : tab === 'SHIPPED' ? order.shipped_at : null

  return (
    <tr className={checked ? 'bg-brand-50' : 'hover:bg-slate-50'} data-order={order.number}>
      {selectable && (
        <td className="py-2 pe-4">
          <input
            type="checkbox"
            className={CHECKBOX_CLASSES}
            aria-label={t('backoffice.orders.bulk.selectOne', { number: order.number })}
            checked={checked}
            disabled={!checked && full}
            onChange={onToggle}
          />
        </td>
      )}
      <td className="py-2 pe-4 align-top">
        <span className="flex flex-col items-start gap-1">
          <Link to={`${base}/commandes/${order.id}`} className="inline-flex min-h-11 items-center font-medium text-brand-700 hover:underline">
            {order.number}
          </Link>
          <span className="flex flex-wrap gap-1">
            <OrderStatusBadge status={order.status} />
            <OrderFlags order={order} />
          </span>
        </span>
      </td>
      <td className="py-2 pe-4 align-top whitespace-nowrap tabular-nums">
        {can('dashboard.view') ? (
          <Link to={`${base}/journees/${order.day}/suivi`} className="text-brand-700 hover:underline">
            {formatDate(order.day)}
          </Link>
        ) : (
          formatDate(order.day)
        )}
      </td>
      <td className="py-2 pe-4 align-top">
        <span className="font-medium">{order.client.code}</span> <span className="text-slate-700">{order.client.name}</span>{' '}
        <ClientStatusFlag status={order.client.status} />
      </td>
      <td className="py-2 pe-4 align-top">
        {order.region.name}
        {order.depot && <span className="block text-xs text-slate-600">{order.depot.name}</span>}
      </td>
      <td className="py-2 pe-4 text-end align-top tabular-nums">{order.lines_count}</td>
      <td className="py-2 pe-4 text-end align-top whitespace-nowrap tabular-nums">{formatAmount(order.total_ttc)}</td>
      <td className="py-2 pe-4 align-top whitespace-nowrap">
        {formatDateTime(order.decided_at)}
        {order.decided_by && <span className="block text-xs text-slate-600">{t('backoffice.orders.list.validatedBy', { name: order.decided_by.name })}</span>}
      </td>
      {(tab === 'IN_PREPARATION' || tab === 'PREPARED' || tab === 'SHIPPED') && (
        <td className="py-2 pe-4 align-top whitespace-nowrap">
          {formatDateTime(milestone)}
          {tab === 'SHIPPED' && order.delivery_note_ref && (
            <span className="block text-xs text-slate-600">{t('backoffice.orders.list.deliveryNote', { ref: order.delivery_note_ref })}</span>
          )}
        </td>
      )}
    </tr>
  )
}
