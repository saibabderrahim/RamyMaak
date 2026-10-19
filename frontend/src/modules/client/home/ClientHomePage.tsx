import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { ClientDayStatus, ClientToday, OrderSummary } from '../../../shared/api/types'
import { useAuthenticatedUser } from '../../../shared/auth/session'
import { formatAmount } from '../../../shared/format'
import { Button, ErrorAlert, PageHeader } from '../../../shared/ui'
import { useClientToday } from '../api'
import { CartNoticeAlert, DayStatusText, PRIMARY_LINK, SECONDARY_LINK, TodayLoading } from '../cart/components'
import { formatDayLong } from '../cart/format'
import { ModifyOrderButton } from '../cart/ModifyOrderButton'
import { type Cart, useCart } from '../cart/store'
import { useCartSync, useCutoffRefetch } from '../cart/sync'
import { OrderStatusBadge } from '../orders/components'

// Bandeau de l'état de la journée : ouvert en vert, en attente en ambre, clôturé en gris.
const DAY_TONES: Record<ClientDayStatus, string> = {
  WAITING_QUOTAS: 'bg-amber-50 text-amber-900',
  ORDERING_OPEN: 'bg-emerald-50 text-emerald-900',
  ORDERING_CLOSED: 'bg-slate-100 text-slate-800',
  DAY_CLOSED: 'bg-slate-100 text-slate-800',
}

/**
 * Accueil du client (EF-CLT-01, contrat du lot 5 §6) : état de la journée
 * proposée (I-42, I-43) avec l'heure limite de sa région, date de
 * livraison, commandes du jour et leur statut, bouton selon ce qui empêche
 * d'envoyer (« Commander », « Modifier ma commande » ou rien), rappel du
 * panier. Tenu à jour en temps réel (useClientLive) et relu à l'heure
 * limite, sur l'horloge du serveur.
 */
export default function ClientHomePage() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const { cart, store } = useCart()
  const query = useClientToday()
  const today = query.data
  useCutoffRefetch(today, query.dataUpdatedAt, query.refetch)
  // Nouvelle commande : panier rapproché de la journée proposée, lue ici. Une
  // modification l'est par le catalogue et le panier (journée de la commande).
  const { notice, dismissNotice } = useCartSync(store, cart.editing ? undefined : today, query.dataUpdatedAt)

  return (
    <>
      <PageHeader title={t('home.welcome', { name: user.name })} />
      <div className="flex flex-col gap-4">
        {query.error && !today && (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={query.error} />
            <Button variant="secondary" className="self-start" onClick={() => void query.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        )}
        {query.isPending && <TodayLoading paused={query.fetchStatus === 'paused'} />}
        {notice && <CartNoticeAlert notice={notice} onDismiss={dismissNotice} />}
        {today && <DayCard today={today} />}
        {cart.lines.length > 0 && <CartReminder cart={cart} />}
        {today && <TodayOrders orders={today.orders} />}
      </div>
    </>
  )
}

/** Journée proposée : date, état et heure limite, livraison, client bloqué, bouton d'action. */
function DayCard({ today }: { today: ClientToday }) {
  const { t } = useTranslation()
  const titleId = useId()
  // Maximum atteint : la commande envoyée la plus récente encore modifiable.
  const modifiable = today.submit_blocker === 'MAX_ORDERS_REACHED' ? today.orders.find((order) => order.can_modify) : undefined

  return (
    <section aria-labelledby={titleId} className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:p-6">
      <div>
        <h2 id={titleId} className="text-lg font-semibold text-slate-900">
          {t('orders.client.day.title', { date: formatDayLong(today.day) })}
        </h2>
        <p className="text-sm text-slate-600">
          {today.client.code} · {today.client.name}
        </p>
      </div>
      <p className={`rounded-lg px-3 py-2 text-base font-semibold ${DAY_TONES[today.status]}`} data-testid="day-status">
        <DayStatusText today={today} />
      </p>
      {today.delivery_date && <p className="text-sm text-slate-800">{t('orders.client.day.delivery', { date: formatDayLong(today.delivery_date) })}</p>}
      {/* Client bloqué : la mention seule, jamais le motif (contrat §6). */}
      {today.client.status !== 'ACTIVE' && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-800">{t('orders.client.home.blocked')}</p>}
      {today.submit_blocker === null ? (
        <Link to="/client/catalogue" className={`${PRIMARY_LINK} w-full sm:w-auto sm:self-start`}>
          {t('orders.client.home.order')}
        </Link>
      ) : (
        modifiable && <ModifyOrderButton order={modifiable} className="w-full sm:w-auto sm:self-start" />
      )}
    </section>
  )
}

/** Rappel du panier enregistré sur l'appareil (nouvelle commande ou modification en cours). */
function CartReminder({ cart }: { cart: Cart }) {
  const { t } = useTranslation()
  const count = cart.lines.length
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
      <p className="font-semibold">
        {cart.editing ? t('orders.client.home.cartEditing', { count, number: cart.editing.number }) : t('orders.client.home.cart', { count })}
      </p>
      <Link to="/client/panier" className={SECONDARY_LINK}>
        {t('orders.client.home.seeCart')}
      </Link>
    </div>
  )
}

/** Commandes du client pour la journée, plus récentes d'abord, avec leur statut. */
function TodayOrders({ orders }: { orders: OrderSummary[] }) {
  const { t } = useTranslation()
  const titleId = useId()
  return (
    <section aria-labelledby={titleId} className="flex flex-col gap-3">
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('orders.client.home.ordersTitle')}
      </h2>
      {orders.length === 0 ? (
        <p className="text-sm text-slate-600">{t('orders.client.home.noOrders')}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {orders.map((order) => (
            <li key={order.id}>
              <Link
                to={`/client/commandes/${order.id}`}
                className="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-xs hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
              >
                <span className="flex min-w-0 flex-col">
                  <span className="font-semibold text-slate-900">{order.number}</span>
                  <span className="text-sm text-slate-600">
                    {t('orders.client.home.orderLines', { count: order.lines_count })} · {t('orders.client.home.orderTotal', { amount: formatAmount(order.total_ttc) })}
                  </span>
                </span>
                <OrderStatusBadge status={order.status} />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
