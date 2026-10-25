import { Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { MONITORING_ORDER_STATUSES, type DayMonitoring, type DayMonitoringRegion, type MonitoringAlert } from '../../../shared/api/types'
import { formatDateTime, formatQuantity } from '../../../shared/format'
import { Badge, Card } from '../../../shared/ui'
import { RegionDayStatusBadge } from '../days/components'
import { useBackoffice } from '../shared/access'
import { DataTable, EmptyState, TableHead, Th } from '../shared/ui'

// Écrans du suivi de la journée (EF-ADV-03) — contrat : docs/lot-6-contrat-api.md §6 et §8.

/** Onglet de la file ADV (adresse `?onglet=`) où se trouvent les commandes d'un statut ; null : hors de la file ADV. */
const QUEUE_TAB: Partial<Record<(typeof MONITORING_ORDER_STATUSES)[number], string>> = {
  VALIDATED: 'a-preparer',
  IN_PREPARATION: 'en-preparation',
  PREPARED: 'preparees',
  SHIPPED: 'expediees',
  CANCELLED: 'annulees',
}

// Chaîne du traitement (charte Ramy, lot 7) : de l'envoi à l'expédition ; les fins de parcours à part.
const FLOW = ['SUBMITTED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED'] as const
const STEP = 'flex h-full min-h-20 flex-col gap-1 p-4'
const STEP_LINK = `${STEP} hover:bg-slate-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus`

/** Jauge validé / en attente / reste sur la quantité totale ; décorative (les nombres sont écrits à côté). */
function Meter({ total, validated, pending = 0, className = 'h-2' }: { total: number; validated: number; pending?: number; className?: string }) {
  const share = (value: number) => (total > 0 ? Math.min(100, (value / total) * 100) : 0)
  const v = share(validated)
  const p = Math.min(100 - v, share(pending))
  return (
    <span aria-hidden="true" className={`flex overflow-hidden rounded-full bg-slate-200 ${className}`}>
      <span className="h-full bg-slate-900" style={{ width: `${v}%` }} />
      <span className="h-full bg-field" style={{ width: `${p}%` }} />
    </span>
  )
}

/**
 * Chiffres clés : la chaîne des commandes par statut (lien vers la file quand
 * elle les contient), les fins de parcours, puis les clients ayant commandé et
 * les quotas validés avec leur jauge.
 */
export function KeyFigures({ data }: { data: DayMonitoring }) {
  const { t } = useTranslation()
  const { base, can } = useBackoffice()
  const quota = data.products.reduce((sum, product) => sum + product.quota, 0)
  const validated = data.products.reduce((sum, product) => sum + product.validated, 0)
  const pending = data.products.reduce((sum, product) => sum + product.pending, 0)
  const ends = MONITORING_ORDER_STATUSES.filter((status) => !(FLOW as readonly string[]).includes(status))

  const step = (status: (typeof MONITORING_ORDER_STATUSES)[number], index: number) => {
    const tab = QUEUE_TAB[status]
    const count = data.totals.orders[status]
    const content = (
      <>
        <span className={`text-sm ${count > 0 ? 'font-semibold text-slate-900' : 'text-slate-600'}`}>{t(`backoffice.monitoring.tiles.${status}`)}</span>
        <span className="text-3xl leading-none font-semibold tracking-tight tabular-nums text-slate-900">{formatQuantity(count)}</span>
      </>
    )
    return (
      <li key={status} data-tile={status} className={`relative min-w-0 ${index > 0 ? 'border-t border-slate-200 sm:border-t-0 sm:border-s' : ''}`}>
        {tab && can('order.view') ? (
          <Link to={`${base}/commandes?jour=${data.day}&onglet=${tab}`} className={STEP_LINK}>
            {content}
          </Link>
        ) : (
          <div className={STEP}>{content}</div>
        )}
      </li>
    )
  }

  return (
    <ul aria-label={t('backoffice.monitoring.tiles.label')} className="flex flex-col gap-4">
        <li>
          <ol aria-label={t('backoffice.monitoring.tiles.flow')} className="grid overflow-hidden rounded-lg border border-slate-200 bg-white sm:grid-cols-5">
            {FLOW.map(step)}
          </ol>
        </li>
        <li>
          <ul aria-label={t('backoffice.monitoring.tiles.ends')} className="flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-600">
            {ends.map((status) => (
              <li key={status} data-tile={status}>
                <span>{t(`backoffice.monitoring.tiles.${status}`)}</span>
                <span className="ms-1.5 font-semibold tabular-nums text-slate-900">{formatQuantity(data.totals.orders[status])}</span>
              </li>
            ))}
          </ul>
        </li>
        <li>
          <ul className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 sm:gap-6">
            <li data-tile="clients" className="flex flex-col gap-2">
              <span className="text-sm text-slate-600">{t('backoffice.monitoring.tiles.clients')}</span>
              <span className="text-2xl leading-none font-semibold tabular-nums text-slate-900">
                {t('backoffice.monitoring.tiles.clientsValue', {
                  ordered: formatQuantity(data.totals.clients.ordered),
                  notified: formatQuantity(data.totals.clients.notified),
                })}
              </span>
              <Meter total={data.totals.clients.notified} validated={data.totals.clients.ordered} />
            </li>
            <li data-tile="quotas" className="flex flex-col gap-2">
              <span className="text-sm text-slate-600">{t('backoffice.monitoring.tiles.quotas')}</span>
              <span className="text-2xl leading-none font-semibold tabular-nums text-slate-900">
                {t('backoffice.monitoring.tiles.quotasValue', { validated: formatQuantity(validated), quota: formatQuantity(quota) })}
              </span>
              <Meter total={quota} validated={validated} pending={pending} />
              {pending > 0 && <span className="text-xs text-slate-600">{t('backoffice.monitoring.tiles.quotasHint', { pending: formatQuantity(pending) })}</span>}
            </li>
          </ul>
        </li>
    </ul>
  )
}

/** Alerte visuelle (I-89) : couleur et texte ; la raison est dans l'info-bulle. */
function AlertBadge({ alert }: { alert: MonitoringAlert }) {
  const { t } = useTranslation()
  return (
    <span title={t(`backoffice.monitoring.regions.alertHints.${alert}`)} data-alert={alert}>
      <Badge tone="danger">{t(`backoffice.monitoring.regions.alerts.${alert}`)}</Badge>
    </span>
  )
}

function RegionRow({ day, item }: { day: string; item: DayMonitoringRegion }) {
  const { t } = useTranslation()
  const { base, can } = useBackoffice()
  const counted = MONITORING_ORDER_STATUSES.filter((status) => item.orders[status] > 0)

  return (
    <tr data-region={item.region.code}>
      <th scope="row" className="py-2 pe-4 text-start align-top font-normal">
        <span className="block font-semibold text-slate-900">{item.region.name}</span>
        <span className="block text-xs text-slate-600">{item.region.code}</span>
        <span className="block text-xs text-slate-600">{item.region.depot ? item.region.depot.name : t('backoffice.monitoring.regions.noDepot')}</span>
      </th>
      <td className="py-2 pe-4 align-top">
        <RegionDayStatusBadge status={item.status} label={item.status_label} />
      </td>
      <td className="py-2 pe-4 align-top whitespace-nowrap text-xs tabular-nums">
        <span className="block">{t('backoffice.monitoring.regions.cutoff.distribution', { time: item.cutoffs.distribution })}</span>
        <span className="block">{t('backoffice.monitoring.regions.cutoff.ordering', { time: item.cutoffs.ordering })}</span>
        <span className="block">{t('backoffice.monitoring.regions.cutoff.validation', { time: item.cutoffs.validation })}</span>
      </td>
      <td className="py-2 pe-4 align-top whitespace-nowrap tabular-nums">
        {t('backoffice.monitoring.regions.clientsValue', { ordered: item.clients.ordered, notified: item.clients.notified })}
      </td>
      <td className="py-2 pe-4 align-top">
        {counted.length === 0 ? (
          <span className="text-slate-500">{t('backoffice.monitoring.regions.noOrders')}</span>
        ) : (
          <ul className="flex flex-wrap gap-1">
            {counted.map((status) => (
              <li key={status} data-count={status}>
                <Badge tone={status === 'SUBMITTED' ? 'warning' : 'neutral'}>
                  {item.orders[status]} {t(`backoffice.monitoring.tiles.${status}`).toLowerCase()}
                </Badge>
              </li>
            ))}
          </ul>
        )}
      </td>
      <td className="py-2 pe-4 align-top whitespace-nowrap">{item.last_validated_at ? formatDateTime(item.last_validated_at) : '—'}</td>
      <td className="py-2 pe-4 align-top">
        {item.alerts.length === 0 ? (
          <span className="text-slate-500">
            <span aria-hidden="true">—</span>
            <span className="sr-only">{t('backoffice.monitoring.regions.noAlert')}</span>
          </span>
        ) : (
          <span className="flex flex-col items-start gap-1">
            {item.alerts.map((alert) => (
              <AlertBadge key={alert} alert={alert} />
            ))}
          </span>
        )}
      </td>
      <td className="py-1 align-top">
        <span className="flex flex-col items-start">
          {can('order.view') && (
            <Link
              to={`${base}/commandes?jour=${day}&region=${item.region.id}`}
              aria-label={t('backoffice.monitoring.regions.ordersLinkLabel', { name: item.region.name })}
              className="inline-flex min-h-11 items-center font-semibold text-slate-900 underline decoration-1 underline-offset-4 hover:decoration-2"
            >
              {t('backoffice.monitoring.regions.ordersLink')}
            </Link>
          )}
          {can('limit.view') && item.quota_published_at && (
            <Link
              to={`${base}/journees/${day}/repartition/${item.region.id}`}
              aria-label={t('backoffice.monitoring.regions.distributionLinkLabel', { name: item.region.name })}
              className="inline-flex min-h-11 items-center font-semibold text-slate-900 underline decoration-1 underline-offset-4 hover:decoration-2"
            >
              {t('backoffice.monitoring.regions.distributionLink')}
            </Link>
          )}
        </span>
      </td>
    </tr>
  )
}

/** Régions de la journée dans le périmètre : statut, heures limites, clients, commandes par statut, dernière validation, alertes. */
export function RegionsTable({ data }: { data: DayMonitoring }) {
  const { t } = useTranslation()
  return (
    <Card title={t('backoffice.monitoring.regions.title')}>
      {data.regions.length === 0 ? (
        <EmptyState>{t('backoffice.monitoring.regions.empty')}</EmptyState>
      ) : (
        <DataTable label={t('backoffice.monitoring.regions.title')} className="min-w-[960px]">
          <TableHead>
            <Th>{t('backoffice.monitoring.regions.columns.region')}</Th>
            <Th>{t('backoffice.monitoring.regions.columns.status')}</Th>
            <Th>{t('backoffice.monitoring.regions.columns.cutoffs')}</Th>
            <Th>{t('backoffice.monitoring.regions.columns.clients')}</Th>
            <Th>{t('backoffice.monitoring.regions.columns.orders')}</Th>
            <Th>{t('backoffice.monitoring.regions.columns.lastValidated')}</Th>
            <Th>{t('backoffice.monitoring.regions.columns.alerts')}</Th>
            <Th>{t('backoffice.monitoring.regions.columns.links')}</Th>
          </TableHead>
          <tbody className="divide-y divide-slate-200">
            {data.regions.map((item) => (
              <RegionRow key={item.region.id} day={data.day} item={item} />
            ))}
          </tbody>
        </DataTable>
      )}
    </Card>
  )
}

/**
 * Matrice produit × région des quantités validées sur quota (V/Q), avec les
 * quantités en attente de validation ; un produit épuisé dans une région
 * (Q > 0 et R = 0) est en évidence, en couleur et en texte.
 */
export function ProductMatrix({ data }: { data: DayMonitoring }) {
  const { t } = useTranslation()
  const columns = data.regions.length + 2

  // Produits regroupés par famille, dans l'ordre du serveur (famille, puis ordre).
  const families: { id: number; name: string; products: DayMonitoring['products'] }[] = []
  for (const product of data.products) {
    const last = families[families.length - 1]
    if (last && last.id === product.family.id) last.products.push(product)
    else families.push({ id: product.family.id, name: product.family.name, products: [product] })
  }

  return (
    <Card title={t('backoffice.monitoring.matrix.title')}>
      {data.products.length === 0 || data.regions.length === 0 ? (
        <EmptyState>{t('backoffice.monitoring.matrix.empty')}</EmptyState>
      ) : (
        <div className="flex flex-col gap-3">
          <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
            <span className="font-semibold text-slate-900">{t('backoffice.monitoring.matrix.legend')}</span>
            <span className="inline-flex items-center gap-1.5">
              <span aria-hidden="true" className="h-2 w-3 rounded-sm bg-slate-900" />
              {t('backoffice.monitoring.matrix.legendValidated')}
            </span>
            <span className="inline-flex items-center gap-1.5">
              <span aria-hidden="true" className="h-2 w-3 rounded-sm bg-field" />
              {t('backoffice.monitoring.matrix.legendPending')}
            </span>
            <span className="rounded bg-red-100 px-1.5 font-semibold text-red-900">{t('backoffice.monitoring.matrix.legendSoldOut')}</span>
          </p>
          <DataTable label={t('backoffice.monitoring.matrix.label')} className="min-w-[720px]">
            <TableHead>
              <Th className="sticky start-0 bg-white">{t('backoffice.monitoring.matrix.product')}</Th>
              {data.regions.map((item) => (
                <Th key={item.region.id} className="text-end">
                  <span title={item.region.name}>{item.region.code}</span>
                </Th>
              ))}
              <Th className="text-end">{t('backoffice.monitoring.matrix.total')}</Th>
            </TableHead>
            <tbody>
              {families.map((family) => (
                <Fragment key={family.id}>
                  <tr className="bg-slate-50">
                    <th scope="colgroup" colSpan={columns} className="py-1.5 ps-1 text-start text-xs font-semibold text-slate-700">
                      {family.name}
                    </th>
                  </tr>
                  {family.products.map((product) => (
                    <tr key={product.product_id} data-sku={product.sku} className="border-b border-slate-200">
                      <th scope="row" className="sticky start-0 bg-white py-2 pe-4 text-start font-normal">
                        <span className="block font-semibold text-slate-900">{product.short_name}</span>
                        <span className="block text-xs text-slate-600">{product.sku}</span>
                      </th>
                      {data.regions.map((item) => {
                        const cell = data.cells[String(item.region.id)]?.[String(product.product_id)]
                        const soldOut = product.sold_out_regions.includes(item.region.id)
                        if (!cell) {
                          return (
                            <td key={item.region.id} className="py-2 pe-3 text-end text-slate-400">
                              <span aria-hidden="true">{t('backoffice.monitoring.matrix.none')}</span>
                            </td>
                          )
                        }
                        return (
                          <td
                            key={item.region.id}
                            data-sold-out={soldOut ? 'true' : undefined}
                            aria-label={t('backoffice.monitoring.matrix.cellLabel', {
                              product: product.short_name,
                              region: item.region.name,
                              validated: cell.validated,
                              quota: cell.quota,
                              pending: cell.pending,
                            })}
                            className={`min-w-28 py-2 pe-3 text-end tabular-nums ${soldOut ? 'bg-red-100 font-semibold text-red-900' : ''}`}
                          >
                            <span>{t('backoffice.monitoring.matrix.cell', { validated: formatQuantity(cell.validated), quota: formatQuantity(cell.quota) })}</span>
                            <Meter total={cell.quota} validated={cell.validated} pending={cell.pending} className="my-1 h-1" />
                            {soldOut && <span className="block text-xs">{t('backoffice.monitoring.matrix.soldOut')}</span>}
                            {cell.pending > 0 && (
                              <span className="block text-xs font-normal text-slate-600">
                                {t('backoffice.monitoring.matrix.pending', { pending: formatQuantity(cell.pending) })}
                              </span>
                            )}
                          </td>
                        )
                      })}
                      <td className="py-2 text-end font-medium tabular-nums">
                        <span>{t('backoffice.monitoring.matrix.cell', { validated: formatQuantity(product.validated), quota: formatQuantity(product.quota) })}</span>
                        <span className="block text-xs font-normal text-slate-600">
                          {t('backoffice.monitoring.matrix.remaining', { remaining: formatQuantity(product.remaining) })}
                        </span>
                      </td>
                    </tr>
                  ))}
                </Fragment>
              ))}
            </tbody>
          </DataTable>
        </div>
      )}
    </Card>
  )
}
