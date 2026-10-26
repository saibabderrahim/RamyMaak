import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import type { OrderSummary, Paginated } from '../../../shared/api/types'
import { formatAmount, formatDate } from '../../../shared/format'
import { Alert, Button, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useClientOrders } from '../api'
import { OrderStatusBadge } from './components'
import { useLocationNotice } from './useLocationNotice'
import { pageParam } from './view'

const PRIMARY_LINK =
  'inline-flex min-h-11 items-center justify-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'

/**
 * « Mes commandes » (EF-CLT-04) : commandes du client (tous ses comptes,
 * Q-11), toutes journées, la plus récente d'abord, 25 par page (`?page=`) ;
 * numéro, journée, statut, nombre de produits et total TTC, lien vers le
 * détail. Tenue à jour en temps réel (useClientLive). Contrat :
 * docs/lot-5-contrat-api.md §3 et §6.
 */
export default function OrdersPage() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const page = pageParam(searchParams.get('page'))
  const query = useClientOrders({ page })
  const notice = useLocationNotice()
  const data = query.data

  const goTo = (next: number) =>
    setSearchParams((params) => {
      const updated = new URLSearchParams(params)
      if (next <= 1) updated.delete('page')
      else updated.set('page', String(next))
      return updated
    })

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-4">
      <PageHeader title={t('orders.clientOrders.list.title')} />
      {notice && <Alert tone="success">{notice}</Alert>}
      {query.error && !data && (
        <div className="flex flex-col gap-3">
          <ErrorAlert error={query.error} />
          <Button variant="secondary" className="self-start" onClick={() => void query.refetch()}>
            {t('common.retry')}
          </Button>
        </div>
      )}
      {query.isPending && <Spinner label={t('orders.clientOrders.list.loading')} />}
      {data &&
        (data.meta.total === 0 ? (
          <EmptyOrders />
        ) : (
          <>
            {data.data.length === 0 ? (
              <div className="flex flex-col gap-2">
                <p className="text-sm text-slate-700">{t('orders.clientOrders.list.pageEmpty')}</p>
                <Button variant="secondary" className="self-start" onClick={() => goTo(1)}>
                  {t('orders.clientOrders.list.firstPage')}
                </Button>
              </div>
            ) : (
              <ul aria-label={t('orders.clientOrders.list.label')} className="flex flex-col gap-2">
                {data.data.map((order) => (
                  <li key={order.id}>
                    <OrderRow order={order} />
                  </li>
                ))}
              </ul>
            )}
            <Pages meta={data.meta} onPage={goTo} />
          </>
        ))}
    </div>
  )
}

/** Une commande de la liste : lien vers son détail. */
function OrderRow({ order }: { order: OrderSummary }) {
  const { t } = useTranslation()
  return (
    <Link
      to={`/client/commandes/${encodeURIComponent(order.id)}`}
      className="flex min-h-11 flex-col gap-1 rounded-lg border border-slate-200 bg-white px-4 py-3 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
    >
      <span className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-semibold text-slate-900">{order.number}</span>
        <OrderStatusBadge status={order.status} />
      </span>
      <span className="text-sm text-slate-700">{t('orders.clientOrders.list.day', { date: formatDate(order.day) })}</span>
      <span className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-sm text-slate-600">
        <span>
          {t('orders.clientOrders.list.lines', { count: order.lines_count })}
          {order.is_adjusted && <> · {t('orders.clientOrders.list.adjusted')}</>}
        </span>
        <span className="font-semibold text-slate-900">{t('orders.clientOrders.list.totalTtc', { amount: formatAmount(order.total_ttc) })}</span>
      </span>
    </Link>
  )
}

/** Aucune commande : invitation à commander. */
function EmptyOrders() {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed border-slate-300 bg-white p-6">
      <p className="font-semibold text-slate-900">{t('orders.clientOrders.list.empty')}</p>
      <p className="text-sm text-slate-600">{t('orders.clientOrders.list.emptyHint')}</p>
      <Link to="/client/catalogue" className={PRIMARY_LINK}>
        {t('orders.clientOrders.list.browse')}
      </Link>
    </div>
  )
}

/** Pages précédente et suivante, avec la position dans la liste ; rien s'il n'y a qu'une page. */
function Pages({ meta, onPage }: { meta: Paginated<OrderSummary>['meta']; onPage: (page: number) => void }) {
  const { t } = useTranslation()
  if (meta.last_page <= 1 && meta.current_page <= 1) return null
  return (
    <nav aria-label={t('orders.clientOrders.list.pages')} className="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-700">
      <Button variant="secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
        <span aria-hidden="true">←</span> {t('orders.clientOrders.list.previous')}
      </Button>
      <span aria-live="polite">{t('orders.clientOrders.list.page', { page: meta.current_page, pages: meta.last_page })}</span>
      <Button variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
        {t('orders.clientOrders.list.next')} <span aria-hidden="true">→</span>
      </Button>
    </nav>
  )
}
