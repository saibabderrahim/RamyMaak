import type { TFunction } from 'i18next'
import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import type { OutboxEntry } from '../../../shared/offline/db'
import { displayStatus, removeEntry } from '../../../shared/offline/outbox'
import { Button, Icon, type IconName } from '../../../shared/ui'
import { ConfirmDialog } from '../../../shared/ui/ConfirmDialog'
import { formatDayLong } from './format'
import { type CartStore, useCart } from './store'

// Boîte d'envoi des commandes client (lot 8, contrat §6.3, point 4) : accueil, panier, « Mes commandes ».

const LINK =
  'inline-flex min-h-11 items-center font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800 ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'

/** Refus dont le texte de l'application vaut le motif du serveur, quand celui-ci manque. */
const PLAIN_REFUSALS = ['CLIENT_BLOCKED', 'DAY_NOT_OPEN', 'CUTOFF_PASSED', 'INVALID_TRANSITION', 'NOT_FOUND'] as const
type PlainRefusal = (typeof PLAIN_REFUSALS)[number]

/** Motif d'un refus : celui du serveur (`detail`), sinon le texte du code, sinon un texte général. */
export function refusalReason(t: TFunction, error: OutboxEntry['error']): string {
  if (error?.detail) return error.detail
  if (error && (PLAIN_REFUSALS as readonly string[]).includes(error.code)) return t(`orders.client.errors.${error.code as PlainRefusal}`)
  return t('offline.outbox.refusedFallback')
}

/** Panier reconstruit depuis la commande refusée : le prochain envoi aura un nouvel identifiant (RG-C07). */
export function resumeInCart(store: CartStore, entry: OutboxEntry): void {
  store.rebuild(
    entry.day,
    entry.body.lines.map((line) => ({ product_id: line.product_id, quantity: line.quantity })),
  )
  if (entry.body.comment) store.setComment(entry.body.comment)
}

const STATUS_ICONS: Record<OutboxEntry['status'], { icon: IconName; tone: string }> = {
  pending: { icon: 'clock', tone: 'text-warning' },
  sending: { icon: 'upload', tone: 'text-info' },
  sent: { icon: 'check', tone: 'text-success' },
  refused: { icon: 'alert', tone: 'text-danger' },
}

type Props = {
  entries: readonly OutboxEntry[] | undefined
  /** Après « Reprendre dans le panier » (le panier vient d'être reconstruit, l'entrée retirée). */
  onResumed?: (entry: OutboxEntry) => void
}

/**
 * « Commandes en attente d'envoi » : une ligne par commande de la boîte
 * d'envoi, avec son état (« En attente d'envoi », « Envoi en cours… »,
 * « Envoyée — RMK-… », « Refusée : motif ») ; une commande refusée se
 * reprend dans le panier ou se retire. Rien quand la boîte est vide.
 */
export function OutboxBlock({ entries, onResumed }: Props) {
  const { t } = useTranslation()
  const titleId = useId()
  const navigate = useNavigate()
  const { cart, store } = useCart()
  const [replacing, setReplacing] = useState<OutboxEntry | null>(null)
  const [removing, setRemoving] = useState<OutboxEntry | null>(null)

  if (!entries || entries.length === 0) return null

  const resume = async (entry: OutboxEntry) => {
    setReplacing(null)
    if (!(await removeEntry(entry.id))) return
    resumeInCart(store, entry)
    navigate('/client/panier', { state: { notice: t('offline.outbox.resumed') } })
    onResumed?.(entry)
  }

  const askResume = (entry: OutboxEntry) => {
    if (cart.lines.length > 0 || cart.editing !== null) setReplacing(entry)
    else void resume(entry)
  }

  return (
    <section aria-labelledby={titleId} className="flex flex-col gap-1 rounded-lg border border-slate-200 bg-white px-4 pt-3 pb-1">
      <h2 id={titleId} className="text-base font-semibold text-slate-900">
        {t('offline.outbox.title')}
      </h2>
      <ul className="divide-y divide-slate-200">
        {entries.map((entry) => (
          <OutboxRow key={entry.id} entry={entry} onResume={() => askResume(entry)} onRemove={() => setRemoving(entry)} />
        ))}
      </ul>

      <ConfirmDialog
        open={replacing !== null}
        title={t('offline.outbox.replaceTitle')}
        confirmLabel={t('offline.outbox.replaceConfirm')}
        onConfirm={() => replacing && void resume(replacing)}
        onCancel={() => setReplacing(null)}
      >
        <p>{t('offline.outbox.replace', { count: cart.lines.length })}</p>
        {cart.editing && <p className="font-semibold text-slate-900">{t('offline.outbox.replaceEditing', { number: cart.editing.number })}</p>}
      </ConfirmDialog>
      <ConfirmDialog
        open={removing !== null}
        title={t('offline.outbox.removeTitle')}
        confirmLabel={t('offline.outbox.remove')}
        tone="danger"
        onConfirm={() => {
          if (removing) void removeEntry(removing.id)
          setRemoving(null)
        }}
        onCancel={() => setRemoving(null)}
      >
        <p>{removing && t('offline.outbox.removeText', { date: formatDayLong(removing.day) })}</p>
      </ConfirmDialog>
    </section>
  )
}

function OutboxRow({ entry, onResume, onRemove }: { entry: OutboxEntry; onResume: () => void; onRemove: () => void }) {
  const { t } = useTranslation()
  const status = displayStatus(entry)
  const date = formatDayLong(entry.day)
  const { icon, tone } = STATUS_ICONS[status]
  const text =
    status === 'sent'
      ? t('offline.outbox.status.sent', { number: entry.order?.number ?? '' })
      : status === 'refused'
        ? t('offline.outbox.status.refused', { reason: refusalReason(t, entry.error) })
        : t(`offline.outbox.status.${status}`)

  return (
    <li className="flex flex-col gap-2 py-3" aria-label={t('offline.outbox.day', { date })}>
      <p className="text-sm text-slate-700">
        <span className="font-semibold text-slate-900">{t('offline.outbox.day', { date })}</span> ·{' '}
        {t('offline.outbox.lines', { count: entry.body.lines.length })}
      </p>
      <p className={`flex items-start gap-2 text-sm font-semibold ${status === 'refused' ? 'text-danger' : 'text-slate-900'}`}>
        <Icon name={icon} className={`mt-0.5 size-4 shrink-0 ${tone}`} />
        <span className="min-w-0">{text}</span>
      </p>
      {status === 'pending' && (
        <p className="text-sm text-slate-600">
          {entry.error?.code === 'UNAUTHENTICATED' ? t('offline.outbox.unauthenticated') : t('offline.outbox.pendingHint')}
        </p>
      )}
      {status === 'sent' && entry.order && (
        <Link to={`/client/commandes/${encodeURIComponent(entry.order.id)}`} className={`${LINK} self-start text-sm`}>
          {t('offline.outbox.open', { number: entry.order.number })}
        </Link>
      )}
      {status === 'refused' && (
        <div className="flex flex-wrap gap-2">
          <Button aria-label={t('offline.outbox.resumeLabel', { date })} onClick={onResume}>
            {t('offline.outbox.resume')}
          </Button>
          <Button variant="secondary" aria-label={t('offline.outbox.removeLabel', { date })} onClick={onRemove}>
            {t('offline.outbox.remove')}
          </Button>
        </div>
      )}
    </li>
  )
}
