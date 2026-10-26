import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { ClientDayStatus, ClientProduct, ClientToday, OrderSummary } from '../../../shared/api/types'
import { useAuthenticatedUser } from '../../../shared/auth/session'
import { formatAmount } from '../../../shared/format'
import { Badge, Button, ErrorAlert, Icon, PageHeader } from '../../../shared/ui'
import { businessMinutes, minutesOf, useServerNow } from '../../../shared/ui/clock'
import { DayRail } from '../../../shared/ui/DayRail'
import { ProductImage } from '../../../shared/ui/ProductImage'
import { QuantityStepper } from '../../../shared/ui/QuantityStepper'
import { useClientToday } from '../api'
import { CartNoticeAlert, DayStatusText, PRIMARY_LINK, SECONDARY_LINK, TodayLoading } from '../cart/components'
import { formatDayLong, limitText } from '../cart/format'
import { ModifyOrderButton } from '../cart/ModifyOrderButton'
import { type Cart, type CartStore, useCart } from '../cart/store'
import { useCartSync, useCutoffRefetch } from '../cart/sync'
import { OrderStatusBadge } from '../orders/components'

// État de la journée : ouvert en vert, en attente en ambre, clôturé en gris ; toujours avec son libellé.
const DAY_TONES: Record<ClientDayStatus, string> = {
  WAITING_QUOTAS: 'bg-warning-soft text-warning',
  ORDERING_OPEN: 'bg-success-soft text-success',
  ORDERING_CLOSED: 'bg-slate-100 text-slate-800',
  DAY_CLOSED: 'bg-slate-100 text-slate-800',
}

const shortDayFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'short', day: '2-digit', month: '2-digit' })

/** « dim. 11/10 » pour « 2026-10-11 » : la livraison sur le fil de la journée. */
function shortDay(day: string): string {
  const text = shortDayFormat.format(new Date(`${day.slice(0, 10)}T00:00:00Z`))
  return text.charAt(0).toUpperCase() + text.slice(1)
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
      <div className="grid gap-4 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:items-start lg:gap-5">
        <div className="flex min-w-0 flex-col gap-4">
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
          {today && <DayCard today={today} receivedAt={query.dataUpdatedAt} />}
          {cart.lines.length > 0 && <CartReminder cart={cart} />}
          {today && <TodayOrders orders={today.orders} />}
        </div>
        {today && !cart.editing && <Featured today={today} cart={cart} store={store} />}
      </div>
    </>
  )
}

/** Temps restant avant l'heure limite : « 1 h 18 », « 45 min » ; null sans heure limite à venir. */
function useTimeLeft(today: ClientToday, receivedAt: number): string | null {
  const { t } = useTranslation()
  const now = useServerNow(today.server_time, receivedAt)
  const cutoff = today.ordering_cutoff_at ? Date.parse(today.ordering_cutoff_at) : Number.NaN
  if (now === null || Number.isNaN(cutoff) || cutoff <= now) return null
  const minutes = Math.ceil((cutoff - now) / 60_000)
  return minutes >= 60
    ? t('orders.client.day.leftHours', { hours: Math.floor(minutes / 60), minutes: String(minutes % 60).padStart(2, '0') })
    : t('orders.client.day.leftMinutes', { minutes })
}

/**
 * Journée proposée : date, état et heure limite, temps restant, fil de la
 * journée (heure limite, validation, livraison), client bloqué, bouton d'action.
 */
function DayCard({ today, receivedAt }: { today: ClientToday; receivedAt: number }) {
  const { t } = useTranslation()
  const titleId = useId()
  const serverNow = useServerNow(today.server_time, receivedAt)
  const timeLeft = useTimeLeft(today, receivedAt)
  // Maximum atteint : la commande envoyée la plus récente encore modifiable.
  const modifiable = today.submit_blocker === 'MAX_ORDERS_REACHED' ? today.orders.find((order) => order.can_modify) : undefined
  const open = today.status === 'ORDERING_OPEN'

  return (
    <section aria-labelledby={titleId} className="flex flex-col gap-4 rounded-lg border border-slate-200 bg-white p-4 sm:p-5">
      <div>
        <h2 id={titleId} className="text-lg font-semibold text-slate-900">
          {t('orders.client.day.title', { date: formatDayLong(today.day) })}
        </h2>
        <p className="text-sm text-slate-600">
          {today.client.code} · {today.client.name}
        </p>
      </div>
      <p className={`self-start rounded px-2 py-1 text-sm font-semibold ${DAY_TONES[today.status]}`} data-testid="day-status">
        <DayStatusText today={today} />
      </p>
      {open && timeLeft && (
        <p className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
          <span className="text-3xl leading-none font-semibold tracking-tight tabular-nums text-slate-900">{timeLeft}</span>
          <span className="text-sm text-slate-600">{t('orders.client.day.leftHint')}</span>
        </p>
      )}
      {today.status !== 'WAITING_QUOTAS' && today.ordering_cutoff && (
        <DayRail
          label={t('orders.client.day.rail.label')}
          now={serverNow === null ? null : businessMinutes(serverNow)}
          leadMinutes={360}
          stops={[
            {
              label: t('orders.client.day.rail.cutoff'),
              time: today.ordering_cutoff,
              minutes: minutesOf(today.ordering_cutoff),
              note: today.ordering_cutoff_extended ? t('orders.extended') : undefined,
            },
            { label: t('orders.client.day.rail.validation'), time: t('orders.client.day.rail.validationTime'), minutes: null },
            ...(today.delivery_date ? [{ label: t('orders.client.day.rail.delivery'), time: shortDay(today.delivery_date), minutes: null }] : []),
          ]}
        />
      )}
      {today.delivery_date && (
        <p className="flex items-center gap-2 text-sm text-slate-900">
          <Icon name="truck" />
          <span>{t('orders.client.day.delivery', { date: formatDayLong(today.delivery_date) })}</span>
        </p>
      )}
      {/* Client bloqué : la mention seule, jamais le motif (contrat §6). */}
      {today.client.status !== 'ACTIVE' && <p className="rounded bg-danger-soft px-3 py-2 text-sm font-semibold text-danger">{t('orders.client.home.blocked')}</p>}
      {today.submit_blocker === null ? (
        <Link to="/client/catalogue" className={`${PRIMARY_LINK} min-h-12 w-full text-base sm:w-auto sm:self-start`}>
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
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-900">
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
        <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white">
          {orders.map((order) => (
            <li key={order.id}>
              <Link
                to={`/client/commandes/${order.id}`}
                className="flex min-h-14 items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus"
              >
                <span className="flex min-w-0 flex-col">
                  <span className="font-semibold tabular-nums text-slate-900">{order.number}</span>
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

/**
 * « Mis en avant » (charte Ramy, lot 7) : les produits du jour marqués « mis en
 * avant » par l'ADV (`is_featured`, déjà renvoyé par l'API du jour), avec leur
 * photo et la quantité liée au panier. Lecture seule quand l'envoi est impossible.
 */
function Featured({ today, cart, store }: { today: ClientToday; cart: Cart; store: CartStore }) {
  const { t } = useTranslation()
  const titleId = useId()
  const products = today.products.filter((product) => product.is_featured && !product.sold_out).slice(0, 2)
  if (products.length === 0) return null
  const quantities = new Map(cart.lines.map((line) => [line.product_id, line.quantity]))
  return (
    <section aria-labelledby={titleId} className="flex min-w-0 flex-col gap-3">
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('orders.client.home.featured')}
      </h2>
      {products.map((product) => (
        <FeaturedProduct
          key={product.id}
          product={product}
          quantity={quantities.get(product.id) ?? 0}
          readOnly={!today.can_submit}
          onQuantity={(quantity) => store.setQuantity(today.day, product.id, quantity)}
        />
      ))}
    </section>
  )
}

function FeaturedProduct({
  product,
  quantity,
  readOnly,
  onQuantity,
}: {
  product: ClientProduct
  quantity: number
  readOnly: boolean
  onQuantity: (quantity: number) => void
}) {
  const { t } = useTranslation()
  const limit = limitText(t, product)
  return (
    <article aria-label={product.name} className="grid grid-cols-[7rem_minmax(0,1fr)] gap-4 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-[9rem_minmax(0,1fr)]">
      <ProductImage photo={product.photos[0] ?? null} alt="" size={144} format={product.format} className="aspect-[4/5]" />
      <div className="flex min-w-0 flex-col gap-1">
        {product.is_new && (
          <span>
            <Badge tone="brand">{t('orders.client.catalogue.isNew')}</Badge>
          </span>
        )}
        <h3 className="font-semibold leading-snug text-slate-900">
          <Link
            to={`/client/catalogue?produit=${product.id}`}
            aria-label={t('orders.client.home.featuredOpen', { name: product.name })}
            className="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
          >
            {product.name}
          </Link>
        </h3>
        <p className="text-xs text-slate-600">{product.sales_unit_label}</p>
        <p className="text-sm tabular-nums text-slate-900">
          <span className="font-semibold">{formatAmount(product.price.price_ht)}</span> HT
          <span className="block text-slate-600">{formatAmount(product.price.price_ttc)} TTC</span>
        </p>
        {limit && <p className="text-xs font-semibold text-slate-900">{limit}</p>}
        <div className="mt-2">
          <QuantityStepper name={product.name} value={quantity} onChange={onQuantity} max={product.available} disabled={readOnly} />
        </div>
      </div>
    </article>
  )
}
