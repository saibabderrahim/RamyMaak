import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { ApiError } from '../../../shared/api/client'
import type { ClientToday, Order } from '../../../shared/api/types'
import { formatDate } from '../../../shared/format'
import { Alert, Button, ErrorAlert } from '../../../shared/ui'
import { Dialog } from '../../../shared/ui/Dialog'
import { clientKey } from '../api'
import { useCart } from '../cart/store'
import { fetchProposedDay, useCancelOrder } from './api'
import { hasChanges, planReorder, type ReorderBlock, type ReorderPlan, reorderBlock } from './reorder'
import { type Feedback, formatQuantity, weekdayOf } from './view'

// Actions du client sur une commande (RG-C05, EF-CLT-05) — contrat : docs/lot-5-contrat-api.md §6.

const LINK_BUTTON =
  'inline-flex min-h-11 items-center justify-center rounded-lg px-1 text-sm font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'

const WARNING = 'rounded-lg bg-amber-50 px-3 py-2 text-amber-900'

const longDay = (day: string) => `${weekdayOf(day)} ${formatDate(day)}`

/** Refus définitif du serveur (4xx métier) : la commande a changé, elle est relue. */
function isRefusal(error: unknown): error is ApiError {
  return error instanceof ApiError && error.status >= 400 && error.status < 500 && ![401, 408, 419, 429].includes(error.status)
}

/**
 * « Annuler la commande » (`can_cancel`, I-52) : confirmation, puis
 * `POST …/cancel {version}`. Un refus du serveur (VERSION_CONFLICT,
 * INVALID_TRANSITION, DAY_NOT_OPEN, CUTOFF_PASSED) ferme la confirmation et
 * relit la commande ; une erreur réseau reste dans la confirmation pour
 * réessayer. Le résultat est affiché par la page (`onFeedback`) : le bouton
 * disparaît avec le droit d'annuler. La modification de cette commande en
 * cours dans le panier est abandonnée.
 */
export function CancelOrderButton({ order, onFeedback }: { order: Pick<Order, 'id' | 'number' | 'version'>; onFeedback: (feedback: Feedback | null) => void }) {
  const { t } = useTranslation()
  const { cart, store } = useCart()
  const queryClient = useQueryClient()
  const cancel = useCancelOrder(order.id)
  const [confirming, setConfirming] = useState(false)
  const editing = cart.editing?.orderId === order.id

  // mutateAsync : la suite s'exécute même si le bouton a déjà disparu (commande relue, plus annulable).
  const confirm = async () => {
    onFeedback(null)
    try {
      const cancelled = await cancel.mutateAsync(order.version)
      if (store.get().editing?.orderId === order.id) store.clear()
      setConfirming(false)
      onFeedback({ tone: 'success', text: t('orders.clientOrders.cancel.done', { number: cancelled.number }) })
    } catch (error) {
      if (!isRefusal(error)) return
      setConfirming(false)
      void queryClient.invalidateQueries({ queryKey: clientKey })
      onFeedback(
        error.code === 'VERSION_CONFLICT' ? { tone: 'warning', text: t('orders.clientOrders.cancel.VERSION_CONFLICT', { number: order.number }) } : { error },
      )
    }
  }

  const open = () => {
    cancel.reset()
    onFeedback(null)
    setConfirming(true)
  }

  return (
    <div className="flex flex-col gap-2">
      <Button variant="secondary" className="w-full sm:w-auto sm:self-start" onClick={open}>
        {t('orders.clientOrders.cancel.button')}
      </Button>
      {confirming && (
        <Dialog title={t('orders.clientOrders.cancel.title', { number: order.number })} onClose={() => (cancel.isPending ? undefined : setConfirming(false))}>
          <div className="flex flex-col gap-4 text-sm text-slate-700">
            <p>{t('orders.clientOrders.cancel.text')}</p>
            {editing && <p className="font-semibold text-slate-900">{t('orders.clientOrders.cancel.editing')}</p>}
            {cancel.error && !isRefusal(cancel.error) && <ErrorAlert error={cancel.error} />}
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="secondary" data-autofocus disabled={cancel.isPending} onClick={() => setConfirming(false)}>
                {t('orders.clientOrders.cancel.keep')}
              </Button>
              <Button variant="danger" disabled={cancel.isPending} onClick={() => void confirm()}>
                {cancel.isPending ? t('orders.clientOrders.cancel.cancelling') : t('orders.clientOrders.cancel.confirm')}
              </Button>
            </div>
          </div>
        </Dialog>
      )}
    </div>
  )
}

type Proposal = { day: string; plan: ReorderPlan }

/**
 * « Recommander » (EF-CLT-05) : la journée proposée est relue ; fermée à une
 * nouvelle commande, la raison est expliquée. Sinon, le panier est rebâti
 * avec les lignes de la commande (produits non commandables ou épuisés
 * ignorés, quantités plafonnées au disponible quand une borne existe),
 * après confirmation quand il contient déjà quelque chose ou que des
 * changements sont à signaler, puis le panier s'ouvre avec le résumé
 * (`state.notice`).
 */
export function ReorderButton({ order }: { order: Pick<Order, 'id' | 'number' | 'lines'> }) {
  const { t } = useTranslation()
  const { cart, store } = useCart()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [blocked, setBlocked] = useState<{ reason: ReorderBlock; today: ClientToday } | null>(null)
  const [nothing, setNothing] = useState<string | null>(null)
  const [proposal, setProposal] = useState<Proposal | null>(null)

  const changes = (plan: ReorderPlan): string[] => [
    ...plan.skipped.map((skip) => t(`orders.clientOrders.reorder.${skip.reason}`, { name: skip.name })),
    ...plan.capped.map((cap) => t('orders.clientOrders.reorder.capped', { name: cap.name, from: formatQuantity(cap.from), to: formatQuantity(cap.to) })),
  ]

  const apply = ({ day, plan }: Proposal) => {
    store.rebuild(day, plan.lines)
    const kept = t('orders.clientOrders.reorder.kept', { count: plan.lines.length })
    const notice = [t('orders.clientOrders.reorder.notice', { number: order.number, kept }), ...changes(plan)].join(' ')
    navigate('/client/panier', { state: { notice } })
  }

  const start = async () => {
    setError(null)
    setBlocked(null)
    setNothing(null)
    setLoading(true)
    try {
      const today = await fetchProposedDay(queryClient)
      const reason = reorderBlock(today)
      if (reason !== null) {
        setBlocked({ reason, today })
        return
      }
      const plan = planReorder(order.lines, today.products)
      if (plan.lines.length === 0) {
        setNothing(today.day)
        return
      }
      const current = store.get()
      const next = { day: today.day, plan }
      if (current.lines.length > 0 || current.editing !== null || hasChanges(plan)) setProposal(next)
      else apply(next)
    } catch (fetchError) {
      setError(fetchError)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <Button variant="secondary" className="w-full sm:w-auto sm:self-start" disabled={loading} onClick={() => void start()}>
        {loading ? t('orders.clientOrders.reorder.loading') : t('orders.clientOrders.reorder.button')}
      </Button>
      <ErrorAlert error={error} />
      {blocked && <ReorderBlocked reason={blocked.reason} today={blocked.today} />}
      {nothing && <Alert tone="warning">{t('orders.clientOrders.reorder.none', { date: longDay(nothing) })}</Alert>}
      {proposal && (
        <Dialog title={t('orders.clientOrders.reorder.title', { number: order.number })} onClose={() => setProposal(null)}>
          <div className="flex flex-col gap-3 text-sm text-slate-700">
            <p>{t('orders.clientOrders.reorder.intro', { date: longDay(proposal.day) })}</p>
            <p className="font-semibold text-slate-900">{t('orders.clientOrders.reorder.kept', { count: proposal.plan.lines.length })}</p>
            {hasChanges(proposal.plan) && (
              <div className="flex flex-col gap-1">
                <h3 className="font-semibold text-slate-900">{t('orders.clientOrders.reorder.changes')}</h3>
                <ul className="list-disc ps-5">
                  {changes(proposal.plan).map((change) => (
                    <li key={change}>{change}</li>
                  ))}
                </ul>
              </div>
            )}
            {cart.editing ? (
              <p className={WARNING}>{t('orders.clientOrders.reorder.replaceEditing', { number: cart.editing.number })}</p>
            ) : (
              cart.lines.length > 0 && <p className={WARNING}>{t('orders.clientOrders.reorder.replace', { count: cart.lines.length })}</p>
            )}
            <div className="mt-1 flex flex-wrap justify-end gap-2">
              <Button variant="secondary" onClick={() => setProposal(null)}>
                {t('common.cancel')}
              </Button>
              <Button data-autofocus onClick={() => apply(proposal)}>
                {t('orders.clientOrders.reorder.confirm')}
              </Button>
            </div>
          </div>
        </Dialog>
      )}
    </div>
  )
}

/** Explication d'un « Recommander » impossible ; maximum atteint : lien vers la commande du jour encore modifiable. */
function ReorderBlocked({ reason, today }: { reason: ReorderBlock; today: ClientToday }) {
  const { t } = useTranslation()
  const modifiable = reason === 'MAX_ORDERS_REACHED' ? today.orders.find((order) => order.can_modify) : undefined
  return (
    <Alert tone="warning">
      <p className="font-semibold">{t('orders.clientOrders.reorder.blockedTitle')}</p>
      <p>{t(`orders.clientOrders.reorder.blocked.${reason}`, { date: longDay(today.day), count: today.max_orders_per_day })}</p>
      {modifiable && (
        <Link to={`/client/commandes/${encodeURIComponent(modifiable.id)}`} className={LINK_BUTTON}>
          {t('orders.clientOrders.reorder.seeOrder', { number: modifiable.number })}
        </Link>
      )}
    </Alert>
  )
}
