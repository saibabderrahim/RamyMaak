import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { ApiError } from '../../../shared/api/client'
import type { Order } from '../../../shared/api/types'
import { formatDate, formatDateTime } from '../../../shared/format'
import { Alert, Button, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useClientOrder } from '../api'
import { ModifyOrderButton } from '../cart/ModifyOrderButton'
import { useCart } from '../cart/store'
import { CancelOrderButton, ReorderButton } from './actions'
import { OrderDecision, OrderHistory, OrderLines, OrderShipping, OrderStatusBadge, OrderTotals, StatusTimeline } from './components'
import { useLocationNotice } from './useLocationNotice'
import { type Feedback, formatTime, weekdayOf } from './view'

const BACK_LINK =
  'inline-flex min-h-11 items-center gap-1 self-start rounded-lg px-1 text-sm font-semibold text-brand-700 hover:text-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

const CART_LINK =
  'inline-flex min-h-11 items-center font-semibold text-brand-700 underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-brand-600'

/**
 * Détail d'une commande (EF-CLT-04) : état et actions (modifier, annuler,
 * Recommander — EF-CLT-05), décision (« Validée avec ajustement », motif du
 * rejet ou de l'annulation, avec les libellés des motifs), expédition (date
 * et bon de livraison une fois expédiée), frise de statut, lignes avec les
 * quantités demandées, validées et expédiées, leurs motifs et commentaires,
 * commentaire, totaux indicatifs et historique. Tenu à jour en temps réel
 * (useClientLive). Commande inconnue ou d'un autre client : « Commande
 * introuvable ». Contrats : docs/lot-5-contrat-api.md §3 et §6,
 * docs/lot-6-contrat-api.md §4 et §8.
 */
export default function OrderDetailPage() {
  const { id = '' } = useParams()
  // Une autre commande (lien « Voir la commande … ») repart d'un état vierge : messages, confirmations.
  return <OrderDetail key={id} id={id} />
}

function OrderDetail({ id }: { id: string }) {
  const { t } = useTranslation()
  const query = useClientOrder(id)
  // Résultat de l'action faite ici (annulation), sinon message de l'écran précédent (envoi).
  const [feedback, setFeedback] = useState<Feedback | null>(null)
  const notice = useLocationNotice()
  const order = query.data

  const backLink = (
    <Link to="/client/commandes" className={BACK_LINK}>
      <span aria-hidden="true">←</span> {t('orders.clientOrders.detail.back')}
    </Link>
  )

  if (!order) {
    const notFound = query.error instanceof ApiError && query.error.status === 404
    return (
      <div className="flex flex-col gap-4">
        {backLink}
        {notFound ? (
          <>
            <PageHeader title={t('orders.clientOrders.detail.notFound')} />
            <p className="text-sm text-slate-700">{t('orders.clientOrders.detail.notFoundHint')}</p>
            <Link to="/client/commandes" className={BACK_LINK}>
              {t('orders.clientOrders.detail.backToList')}
            </Link>
          </>
        ) : query.error ? (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={query.error} />
            <Button variant="secondary" className="self-start" onClick={() => void query.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        ) : (
          <Spinner label={t('orders.clientOrders.detail.loading')} />
        )}
      </div>
    )
  }

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-4">
      {backLink}
      <PageHeader title={t('orders.clientOrders.detail.title', { number: order.number })} />
      {feedback ? (
        'error' in feedback ? (
          <ErrorAlert error={feedback.error} />
        ) : (
          <Alert tone={feedback.tone}>{feedback.text}</Alert>
        )
      ) : (
        notice && <Alert tone="success">{notice}</Alert>
      )}
      <OrderOverview order={order} onFeedback={setFeedback} />
      <OrderDecision order={order} />
      <OrderShipping order={order} />
      <StatusTimeline order={order} />
      <OrderLines lines={order.lines} />
      {order.comment && <OrderComment comment={order.comment} />}
      <OrderTotals order={order} />
      <OrderHistory history={order.history} />
    </div>
  )
}

/** Journée, statut, envoi, heure limite de modification et actions permises. */
function OrderOverview({ order, onFeedback }: { order: Order; onFeedback: (feedback: Feedback | null) => void }) {
  const { t } = useTranslation()
  const { cart } = useCart()
  const titleId = useId()
  const editing = cart.editing?.orderId === order.id
  const until = order.modifiable_until

  return (
    <section className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:p-6" aria-labelledby={titleId}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id={titleId} className="text-base font-semibold text-slate-900">
          {t('orders.clientOrders.detail.day', { date: `${weekdayOf(order.day)} ${formatDate(order.day)}` })}
        </h2>
        <OrderStatusBadge status={order.status} />
      </div>
      <p className="text-sm text-slate-600">
        {order.submitted_by
          ? t('orders.clientOrders.detail.submittedBy', { date: formatDateTime(order.submitted_at), name: order.submitted_by.name })
          : t('orders.clientOrders.detail.submitted', { date: formatDateTime(order.submitted_at) })}
      </p>
      {until && (order.can_modify || order.can_cancel) && (
        <p className="rounded-lg bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-900">
          {t(order.can_modify ? 'orders.clientOrders.detail.modifiableUntil' : 'orders.clientOrders.detail.cancellableUntil', {
            time: formatTime(until),
          })}
        </p>
      )}
      {editing && (
        <p className="flex flex-wrap items-center gap-x-3 text-sm text-slate-800">
          {t('orders.clientOrders.detail.editing')}
          <Link to="/client/panier" className={CART_LINK}>
            {t('orders.clientOrders.detail.seeCart')}
          </Link>
        </p>
      )}
      <div role="group" aria-label={t('orders.clientOrders.detail.actions')} className="flex flex-col gap-3">
        {order.can_modify && (
          <ModifyOrderButton
            order={order}
            label={t('orders.clientOrders.detail.modify')}
            className="w-full sm:w-auto sm:self-start"
            onNotModifiable={() => onFeedback({ tone: 'warning', text: t('orders.client.modify.notModifiable') })}
          />
        )}
        {order.can_cancel && <CancelOrderButton order={order} onFeedback={onFeedback} />}
        <ReorderButton order={order} />
      </div>
    </section>
  )
}

/** Commentaire du client, tel qu'envoyé. */
function OrderComment({ comment }: { comment: string }) {
  const { t } = useTranslation()
  const titleId = useId()
  return (
    <section aria-labelledby={titleId} className="flex flex-col gap-1 rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-xs sm:p-6">
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('orders.clientOrders.detail.comment')}
      </h2>
      <p className="whitespace-pre-line text-slate-800">{comment}</p>
    </section>
  )
}
