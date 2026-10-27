import { useQueryClient } from '@tanstack/react-query'
import type { TFunction } from 'i18next'
import { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigate } from 'react-router'
import { ApiError, NetworkError, type ProblemDocument } from '../../../shared/api/client'
import type { ClientToday, Order, OrderStatus, SubmitOrderInput } from '../../../shared/api/types'
import { useAuthenticatedUser } from '../../../shared/auth/session'
import { formatAmount, formatQuantity } from '../../../shared/format'
import type { Totals } from '../../../shared/money'
import type { OutboxEntry } from '../../../shared/offline/db'
import { OfflineDataNotice } from '../../../shared/offline/OfflineDataNotice'
import { enqueue, isWaiting, OutboxBusyError, type ProcessResult, removeEntry } from '../../../shared/offline/outbox'
import { registerBackgroundSync, sendOutboxEntry, useOutboxEntries } from '../../../shared/offline/useOutbox'
import { useOnline } from '../../../shared/pwa/online'
import { Alert, Button, ErrorAlert, PageHeader } from '../../../shared/ui'
import { Dialog } from '../../../shared/ui/Dialog'
import { ProductImage } from '../../../shared/ui/ProductImage'
import { QuantityStepper } from '../../../shared/ui/QuantityStepper'
import { clientKey } from '../api'
import { locationNotice } from '../orders/view'
import { fetchOrder, isRetryable, type LineIssue, sameContent, type SendResult, sendIssues, useSendCart } from './api'
import { CartNoticeAlert, DaySummary, EditingBanner, PRIMARY_LINK, TodayLoading, TotalsList } from './components'
import { limitText } from './format'
import { OutboxBlock, resumeInCart } from './OutboxBlock'
import { type CartItem, cartItems, cartTotals, lineAmount, normalizeComment, orderLines, pricedLine } from './reconcile'
import type { Cart, CartLine } from './store'
import { useCartToday } from './sync'

/** Longueur maximale du commentaire (I-60). */
const MAX_COMMENT = 500

const ignore = () => undefined

/** Compte rendu d'un envoi qui n'a pas abouti. */
/** Commandes closes : un renvoi qui les rend n'a rien créé. */
const DEAD_STATUSES: readonly OrderStatus[] = ['CANCELLED', 'EXPIRED', 'REJECTED']

type Outcome =
  | { kind: 'retry'; network: boolean }
  | { kind: 'refused'; error: ApiError; fields: string[] }
  | { kind: 'conflict'; number: string; order: Order | null }
  | { kind: 'dead'; number: string; status: string }
  | { kind: 'busy' }
  | { kind: 'storage' }

/** Refus de l'envoi fait depuis cet écran : détail du serveur, rapporté aux lignes à la reprise dans le panier. */
type Refusal = { id: string; problem: ProblemDocument; lines: CartLine[] }

/** Lignes et commentaire d'un envoi, comparables à une commande (`sameContent`). */
const contentOf = (body: SubmitOrderInput): Pick<Cart, 'day' | 'lines' | 'comment'> => ({
  day: body.day,
  lines: body.lines.map((line) => ({ product_id: line.product_id, quantity: line.quantity })),
  comment: body.comment ?? '',
})

/**
 * Panier (EF-CLT-03 ; contrat du lot 5 §6) : lignes avec photo, quantité et
 * montant, totaux HT, TVA et TTC indicatifs, commentaire, puis « Envoyer la
 * commande » (POST) ou « Enregistrer les modifications » (PATCH de la
 * commande modifiée), après confirmation.
 *
 * Lot 8 (contrat §6.3, I-102) : une nouvelle commande passe par la boîte
 * d'envoi de l'appareil, en ligne comme hors ligne. Après confirmation, elle
 * y est déposée avec son identifiant (enregistré d'abord dans le panier,
 * I-40), le panier est vidé et l'envoi tenté aussitôt : réussi, la commande
 * s'ouvre comme avant ; sans réseau, elle partira d'elle-même
 * (« Commandes en attente d'envoi ») ; refusée, elle se reprend dans le
 * panier (nouvel identifiant), avec les lignes signalées, ou se retire. Une
 * seule commande en attente par journée (I-112). Modifier une commande
 * envoyée reste possible en ligne seulement (D-13).
 *
 * Envoi (RG-C07, I-40) : l'identifiant est enregistré avant la requête.
 * - Réseau, 5xx ou 429 : identifiant gardé, la nouvelle tentative le réutilise ;
 *   tant qu'il l'est, l'envoi reste possible quoi que dise la journée (maximum
 *   atteint, heure limite…) : le serveur reconnaît un renvoi avant tout contrôle
 *   (I-50) et rend la commande déjà créée.
 * - 2xx : panier vidé, détail de la commande avec un message (`state.notice`) :
 *   201 nouvelle commande ; 200 renvoi, avec un avertissement si la commande
 *   enregistrée ne porte pas le panier envoyé (changé depuis la coupure, I-54).
 * - Refus métier : identifiant abandonné, violations rapportées aux lignes.
 * - VERSION_CONFLICT : commande relue ; si elle contient déjà le panier (réponse
 *   perdue d'une tentative précédente), c'est un succès, sinon reprise proposée.
 * Seules les lignes affichées (produits du catalogue du jour) sont envoyées.
 */
export default function CartPage() {
  const { t } = useTranslation()
  const { cart, store, query, notice, dismissNotice } = useCartToday()
  // Résumé transmis par « Recommander » (produits ignorés ou plafonnés).
  const arrival = locationNotice(useLocation().state)
  const today = query.data
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const online = useOnline()
  const user = useAuthenticatedUser()
  const entries = useOutboxEntries(user.id)
  const send = useSendCart()
  const linesId = useId()
  const [confirming, setConfirming] = useState(false)
  const [queueing, setQueueing] = useState(false)
  const [outcome, setOutcome] = useState<Outcome | null>(null)
  const [issues, setIssues] = useState<ReadonlyMap<number, LineIssue>>(() => new Map())
  // Commande déposée depuis cet écran, et son refus éventuel (détail du serveur).
  const [queuedId, setQueuedId] = useState<string | null>(null)
  const [refusal, setRefusal] = useState<Refusal | null>(null)
  const mounted = useRef(true)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  const editing = cart.editing
  const items = today ? cartItems(cart.lines, today.products, editing?.prices) : []
  const sums = cartTotals(items)
  const editingOrder = editing && today ? today.orders.find((order) => order.id === editing.orderId) : undefined
  // Une seule commande en attente d'envoi par journée sur l'appareil (I-112).
  const busy = !editing && today !== undefined && (entries ?? []).some((entry) => entry.day === today.day && isWaiting(entry))
  const blocker = !today
    ? null
    : editing
      ? editingOrder?.can_modify
        ? null
        : t('orders.client.editing.notModifiable')
      : busy
        ? t('offline.cart.busyDay')
        : cart.pending
          ? null
          : today.submit_blocker && t(`orders.client.blocker.${today.submit_blocker}`, { count: today.max_orders_per_day })
  // Hors ligne : seule une modification attend le réseau ; une nouvelle commande part dans la boîte d'envoi.
  const canSend = (online || !editing) && !send.isPending && !queueing && today !== undefined && blocker === null && items.length > 0
  const queued = queuedId === null ? undefined : entries?.find((entry) => entry.id === queuedId)

  const succeed = ({ order, kind }: SendResult, sent: Cart) => {
    // Renvoi qui rend une commande close depuis (annulée, expirée, rejetée) : rien n'a été créé ; le panier
    // est gardé et le prochain envoi aura un nouvel identifiant.
    if (kind === 'replayed' && DEAD_STATUSES.includes(order.status)) {
      store.abandonSend()
      void queryClient.invalidateQueries({ queryKey: clientKey })
      setOutcome({ kind: 'dead', number: order.number, status: order.status_label })
      return
    }
    // Renvoi d'un envoi interrompu (même identifiant, 200) : le serveur rend la commande déjà créée sans
    // comparer son contenu (I-54) ; si le panier a changé depuis, ses changements n'y figurent pas.
    const notice =
      kind === 'updated'
        ? 'orders.client.sent.updated'
        : kind === 'created' || sameContent(order, sent)
          ? 'orders.client.sent.created'
          : 'orders.client.sent.replayed'
    store.clear()
    void queryClient.invalidateQueries({ queryKey: clientKey })
    navigate(`/client/commandes/${encodeURIComponent(order.id)}`, { state: { notice: t(notice, { number: order.number }) } })
  }

  const fail = async (error: unknown, sent: Cart) => {
    if (isRetryable(error)) {
      setOutcome({ kind: 'retry', network: error instanceof NetworkError })
      return
    }
    store.abandonSend()
    // Journée relue : disponibles, statut et droits ont pu changer (le rapprochement suit).
    void queryClient.invalidateQueries({ queryKey: clientKey })
    if (!(error instanceof ApiError)) return
    if (error.code === 'VERSION_CONFLICT' && sent.editing) {
      const number = sent.editing.number
      try {
        const current = await fetchOrder(queryClient, sent.editing.orderId)
        if (current.status === 'SUBMITTED' && sameContent(current, sent)) succeed({ order: current, kind: 'updated' }, sent)
        else setOutcome({ kind: 'conflict', number, order: current })
      } catch {
        setOutcome({ kind: 'conflict', number, order: null })
      }
      return
    }
    const found = sendIssues(error, sent.lines)
    setIssues(found.lines)
    setOutcome({ kind: 'refused', error, fields: found.fields })
  }

  /** Résultat de la première tentative, l'écran encore ouvert. */
  const settleQueued = (result: ProcessResult, body: SubmitOrderInput) => {
    const { outcome: sent } = result
    if (sent.status === 'refused') {
      setRefusal({ id: result.entry.id, problem: sent.problem as ProblemDocument, lines: contentOf(body).lines })
      return
    }
    if (sent.status !== 'sent') return
    const { order, created } = sent
    // Renvoi d'un envoi interrompu (lot 5) qui rend une commande close depuis : rien n'a été créé ; le
    // panier est rendu, et le prochain envoi aura un nouvel identifiant.
    if (!created && DEAD_STATUSES.includes(order.status)) {
      void removeEntry(result.entry.id)
      resumeInCart(store, result.entry)
      setQueuedId(null)
      setOutcome({ kind: 'dead', number: order.number, status: order.status_label })
      return
    }
    const notice = created || sameContent(order, contentOf(body)) ? 'orders.client.sent.created' : 'orders.client.sent.replayed'
    navigate(`/client/commandes/${encodeURIComponent(order.id)}`, { state: { notice: t(notice, { number: order.number }) } })
  }

  /**
   * Nouvelle commande : identifiant enregistré dans le panier (I-40), commande
   * déposée dans la boîte d'envoi, panier vidé, puis envoi tenté aussitôt.
   */
  const queue = async (products: ClientToday['products']) => {
    const current = store.get()
    if (current.day === null) return
    setOutcome(null)
    setIssues(new Map())
    setRefusal(null)
    setQueueing(true)
    const body: SubmitOrderInput = {
      client_request_id: store.beginSend(),
      day: current.day,
      comment: normalizeComment(current.comment),
      lines: orderLines(cartItems(current.lines, products).map((item) => item.line)),
    }
    try {
      await enqueue({ user_id: user.id, client_id: user.client_id ?? 0, body })
    } catch (error) {
      // Panier et identifiant gardés : un nouvel essai ne crée pas de seconde commande.
      setOutcome(error instanceof OutboxBusyError ? { kind: 'busy' } : { kind: 'storage' })
      return
    } finally {
      setQueueing(false)
      setConfirming(false)
    }
    store.clear()
    setQueuedId(body.client_request_id)
    void registerBackgroundSync()
    const result = await sendOutboxEntry(queryClient, body.client_request_id)
    if (result && mounted.current) settleQueued(result, body)
  }

  /** « Reprendre dans le panier » de la commande refusée ici : refus rapporté aux lignes, comme au lot 5. */
  const resumed = (entry: OutboxEntry) => {
    if (refusal?.id !== entry.id) return
    const error = new ApiError(refusal.problem)
    const found = sendIssues(error, refusal.lines)
    setIssues(found.lines)
    setOutcome({ kind: 'refused', error, fields: found.fields })
    setRefusal(null)
    setQueuedId(null)
  }

  const confirm = async (products: ClientToday['products']) => {
    if (!store.get().editing) {
      await queue(products)
      return
    }
    // Panier relu au moment de l'envoi ; seules ses lignes affichées partent.
    const current = store.get()
    const sent: Cart = { ...current, lines: cartItems(current.lines, products).map((item) => item.line) }
    setOutcome(null)
    setIssues(new Map())
    try {
      succeed(await send.mutateAsync(sent), sent)
    } catch (error) {
      setConfirming(false)
      await fail(error, sent)
    }
  }

  const abandon = () => {
    store.clear()
    setOutcome(null)
  }

  const restart = (order: Order) => {
    store.startEditing(order)
    setOutcome(null)
  }

  return (
    <>
      <PageHeader title={editing ? t('orders.client.cart.editTitle', { number: editing.number }) : t('nav.cart')} />
      <div className="flex flex-col gap-4">
        {today && <OfflineDataNotice updatedAt={query.dataUpdatedAt} hint={editing ? t('offline.cart.offlineEditing') : t('offline.cart.offlineNew')} />}
        {editing && <EditingBanner number={editing.number} modifiable={today === undefined || blocker === null} onAbandon={abandon} />}
        {arrival && <Alert>{arrival}</Alert>}
        {notice && <CartNoticeAlert notice={notice} onDismiss={dismissNotice} />}
        {queued?.status === 'pending' && <Alert tone="success">{t('offline.cart.queued')}</Alert>}
        <OutboxBlock entries={entries} onResumed={resumed} />

        {cart.lines.length === 0 ? (
          <div className="flex flex-col items-start gap-3 rounded-lg border border-slate-200 bg-white p-4">
            <p className="text-slate-700">{editing ? t('orders.client.cart.noLines') : t('orders.client.cart.empty')}</p>
            <Link to="/client/catalogue" className={PRIMARY_LINK}>
              {t('orders.client.cart.browse')}
            </Link>
          </div>
        ) : query.error && !today ? (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={query.error} />
            <Button variant="secondary" className="self-start" onClick={() => void query.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        ) : !today ? (
          <TodayLoading paused={query.fetchStatus === 'paused'} label={t('orders.client.cart.loading')} hint={t('offline.cart.kept')} />
        ) : (
          <div className="flex flex-col gap-4 lg:grid lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
            <section aria-labelledby={linesId}>
              <h2 id={linesId} className="sr-only">
                {t('orders.client.cart.lines')}
              </h2>
              <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white px-4">
                {items.map((item) => (
                  <CartLineRow
                    key={item.product.id}
                    item={item}
                    issue={issues.get(item.product.id)}
                    onQuantity={(quantity) => store.setQuantity(today.day, item.product.id, quantity)}
                  />
                ))}
              </ul>
            </section>

            {/* Téléphone : enfants dans la colonne de la page (display: contents), la barre d'envoi reste
                visible au-dessus des onglets ; PC : colonne latérale collante. */}
            <div className="contents lg:sticky lg:top-20 lg:flex lg:flex-col lg:gap-4">
              <div className="flex flex-col gap-4 rounded-lg border border-slate-200 bg-white p-4">
                <DaySummary today={today} />
                <TotalsList totals={sums} />
                {today.min_amount_ht !== null && sums.ht < today.min_amount_ht && (
                  <Alert tone="warning">{t('orders.client.cart.minimum', { amount: formatAmount(today.min_amount_ht) })}</Alert>
                )}
                <CommentField value={cart.comment} onChange={(comment) => store.setComment(comment)} />
                {blocker && <Alert tone="warning">{blocker}</Alert>}
                {cart.pending && !outcome && !send.isPending && !queueing && <Alert>{t('orders.client.cart.pending')}</Alert>}
              </div>
              <div className="sticky bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-30 -mx-4 flex flex-col gap-3 border-t border-slate-200 bg-white px-4 py-3 lg:static lg:mx-0 lg:border-0 lg:bg-transparent lg:p-0">
                {/* Compte rendu à côté du bouton : visible là où le client vient d'envoyer, sur téléphone aussi. */}
                {outcome && <OutcomeAlert outcome={outcome} editing={editing !== null} onRestart={restart} onAbandon={abandon} />}
                <div className="flex items-center gap-3">
                  <p className="flex shrink-0 flex-col text-xs leading-tight text-slate-600 lg:hidden">
                    {t('orders.client.cart.totalTtc')}
                    <span className="text-base font-semibold tabular-nums text-slate-900">{formatAmount(sums.ttc)}</span>
                  </p>
                  <Button className="min-h-12 flex-1 text-base" disabled={!canSend} onClick={() => setConfirming(true)}>
                    {send.isPending ? t('orders.client.cart.sending') : editing ? t('orders.client.cart.save') : t('orders.client.cart.send')}
                  </Button>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>

      {confirming && today && (
        <ConfirmDialog
          today={today}
          editing={editing !== null}
          count={items.length}
          totals={sums}
          comment={cart.comment.trim()}
          sending={send.isPending || queueing}
          onConfirm={() => void confirm(today.products)}
          onClose={() => setConfirming(false)}
        />
      )}
    </>
  )
}

/** Ligne du panier : photo, nom, UV et prix, quantité, montant HT ; problème signalé par le dernier envoi. */
function CartLineRow({ item, issue, onQuantity }: { item: CartItem; issue: LineIssue | undefined; onQuantity: (quantity: number) => void }) {
  const { t } = useTranslation()
  const { product, line } = item
  // Signalement valable tant que la quantité envoyée n'a pas été changée.
  const shown = issue && issue.quantity === line.quantity ? issue : undefined

  return (
    <li>
      <article aria-label={product.name} className={`-mx-4 flex gap-3 px-4 py-4 ${shown ? 'bg-danger-soft' : ''}`}>
        <ProductImage photo={product.photos[0] ?? null} alt="" size={56} format={product.format} className="w-14 shrink-0 self-start" />
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <p className="font-semibold leading-snug text-slate-900">{product.name}</p>
          <p className="text-xs text-slate-600">
            {product.sales_unit_label} · {t('orders.client.cart.unitPrice', { amount: formatAmount(pricedLine(item).unit_price_ht) })}
          </p>
          {product.limit !== null && <p className="text-xs font-semibold text-slate-900">{limitText(t, product)}</p>}
          {shown && <LineIssueText issue={shown} onQuantity={onQuantity} />}
          <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
            <QuantityStepper name={product.name} value={line.quantity} onChange={onQuantity} max={product.available} />
            <span className="text-sm font-semibold tabular-nums text-slate-900">{t('orders.client.cart.amount', { amount: formatAmount(lineAmount(item)) })}</span>
          </div>
          <Button
            variant="ghost"
            className="-ms-2 self-start px-2 underline decoration-1 underline-offset-4"
            aria-label={t('orders.client.cart.removeLabel', { name: product.name })}
            onClick={() => onQuantity(0)}
          >
            {t('orders.client.cart.remove')}
          </Button>
        </div>
      </article>
    </li>
  )
}

/** Refus d'une ligne : produit indisponible, quantité au-dessus du disponible (« Maximum : n » si une borne existe), format. */
function LineIssueText({ issue, onQuantity }: { issue: LineIssue; onQuantity: (quantity: number) => void }) {
  const { t } = useTranslation()
  const max = issue.kind === 'limit' ? issue.max : null
  return (
    <div className="flex flex-col items-start gap-1 text-sm text-danger">
      <p>
        {issue.kind === 'unavailable'
          ? t('orders.client.line.unavailable')
          : issue.kind === 'field'
            ? issue.messages.join(' ')
            : max === null
              ? t('orders.client.line.tooMany')
              : t('orders.client.line.max', { max: formatQuantity(max) })}
      </p>
      {max !== null && max > 0 && (
        <Button variant="secondary" onClick={() => onQuantity(max)}>
          {t('orders.client.line.setMax', { max: formatQuantity(max) })}
        </Button>
      )}
    </div>
  )
}

function CommentField({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const { t } = useTranslation()
  const id = useId()
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-sm font-semibold text-slate-900">
        {t('orders.client.cart.comment')}
      </label>
      <textarea
        id={id}
        rows={3}
        maxLength={MAX_COMMENT}
        value={value}
        aria-describedby={`${id}-hint`}
        onChange={(event) => onChange(event.target.value.slice(0, MAX_COMMENT))}
        className="w-full rounded-lg border border-field bg-white px-3 py-2 text-base text-slate-900 focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-focus"
      />
      <p id={`${id}-hint`} className="text-sm text-slate-600">
        {t('orders.client.cart.commentHint', { count: value.length, max: MAX_COMMENT })}
      </p>
    </div>
  )
}

/** Refus dont le message ne dépend que du code (contrat §3). */
const PLAIN_REFUSALS = ['CLIENT_BLOCKED', 'DAY_NOT_OPEN', 'CUTOFF_PASSED', 'INVALID_TRANSITION', 'NOT_FOUND'] as const
type PlainRefusal = (typeof PLAIN_REFUSALS)[number]

/** Message d'un refus métier, sinon le `detail` du serveur (violations, format). */
function refusalText(t: TFunction, error: ApiError): string {
  if ((PLAIN_REFUSALS as readonly string[]).includes(error.code)) return t(`orders.client.errors.${error.code as PlainRefusal}`)
  switch (error.code) {
    case 'MAX_ORDERS_REACHED':
      return t('orders.client.errors.MAX_ORDERS_REACHED', { count: Number(error.problem.max_orders_per_day ?? 1) })
    case 'ORDER_BELOW_MINIMUM':
      return t('orders.client.errors.ORDER_BELOW_MINIMUM', {
        min: formatAmount(Number(error.problem.min_amount_ht)),
        total: formatAmount(Number(error.problem.total_ht)),
      })
    default:
      return error.problem.detail ?? t('errors.unexpected')
  }
}

function OutcomeAlert({
  outcome,
  editing,
  onRestart,
  onAbandon,
}: {
  outcome: Outcome
  editing: boolean
  onRestart: (order: Order) => void
  onAbandon: () => void
}) {
  const { t } = useTranslation()

  if (outcome.kind === 'busy') return <Alert tone="warning">{t('offline.cart.busyDay')}</Alert>
  if (outcome.kind === 'storage') return <Alert tone="error">{t('offline.cart.storageFailed')}</Alert>

  if (outcome.kind === 'retry') {
    return <Alert tone="warning">{outcome.network ? t('orders.client.errors.network') : t('orders.client.errors.server')}</Alert>
  }

  if (outcome.kind === 'dead') {
    return <Alert tone="warning">{t('orders.client.errors.deadReplay', { number: outcome.number, status: outcome.status.toLocaleLowerCase('fr') })}</Alert>
  }

  if (outcome.kind === 'conflict') {
    const order = outcome.order
    return (
      <Alert tone="error">
        <p className="font-semibold">{t('orders.client.errors.VERSION_CONFLICT', { number: outcome.number })}</p>
        <p>{order?.can_modify ? t('orders.client.errors.conflictRestart') : t('orders.client.editing.notModifiable')}</p>
        <div className="mt-3 flex flex-wrap gap-2">
          {order?.can_modify && <Button onClick={() => onRestart(order)}>{t('orders.client.errors.restart')}</Button>}
          <Button variant="secondary" onClick={onAbandon}>
            {t('orders.client.editing.abandon')}
          </Button>
        </div>
      </Alert>
    )
  }

  const { error, fields } = outcome
  const lineCodes = ['PRODUCT_NOT_AVAILABLE', 'CLIENT_LIMIT_EXCEEDED', 'VALIDATION_FAILED']
  return (
    <Alert tone="error">
      <p className="font-semibold">{refusalText(t, error)}</p>
      {fields.length > 0 && (
        <ul className="mt-1 list-disc ps-5">
          {fields.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      )}
      {lineCodes.includes(error.code) && fields.length === 0 && <p>{t('orders.client.errors.lines')}</p>}
      {editing && (error.code === 'INVALID_TRANSITION' || error.code === 'NOT_FOUND') && (
        <div className="mt-3">
          <Button variant="secondary" onClick={onAbandon}>
            {t('orders.client.editing.abandon')}
          </Button>
        </div>
      )}
    </Alert>
  )
}

function ConfirmDialog({
  today,
  editing,
  count,
  totals,
  comment,
  sending,
  onConfirm,
  onClose,
}: {
  today: ClientToday
  editing: boolean
  count: number
  totals: Totals
  comment: string
  sending: boolean
  onConfirm: () => void
  onClose: () => void
}) {
  const { t } = useTranslation()
  // Envoi en cours : la boîte reste ouverte jusqu'au compte rendu.
  return (
    <Dialog title={editing ? t('orders.client.confirm.editTitle') : t('orders.client.confirm.title')} onClose={sending ? ignore : onClose}>
      <div className="flex flex-col gap-4 text-sm text-slate-700">
        <DaySummary today={today} />
        <p className="font-semibold text-slate-900">{t('orders.client.confirm.lines', { count })}</p>
        <TotalsList totals={totals} />
        {comment !== '' && <p className="break-words">{t('orders.client.confirm.comment', { comment })}</p>}
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" disabled={sending} onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button data-autofocus disabled={sending} onClick={onConfirm}>
            {sending ? t('orders.client.cart.sending') : editing ? t('orders.client.confirm.confirmEdit') : t('orders.client.confirm.confirm')}
          </Button>
        </div>
      </div>
    </Dialog>
  )
}
