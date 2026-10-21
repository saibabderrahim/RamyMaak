import { useTranslation } from 'react-i18next'
import type { AdvOrderSummary, ClientStatus, OrderStatus } from '../../../shared/api/types'
import { Badge } from '../../../shared/ui'
import { STATUS_CLASSES } from './model'

// Éléments communs de la file ADV et du détail d'une commande — contrat : docs/lot-6-contrat-api.md §5 et §8.

/** Statut d'une commande : libellé commun (orders.status.*) sur une couleur propre au statut. */
export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  const { t } = useTranslation()
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${STATUS_CLASSES[status]}`} data-status={status}>
      {t(`orders.status.${status}`)}
    </span>
  )
}

/** Un client bloqué ou inactif est signalé : ses commandes déjà validées restent à préparer. */
export function ClientStatusFlag({ status }: { status: ClientStatus }) {
  const { t } = useTranslation()
  if (status === 'ACTIVE') return null
  return <Badge tone={status === 'BLOCKED' ? 'danger' : 'neutral'}>{t(`backoffice.clients.status.${status}`)}</Badge>
}

/** Repères d'une commande : quantités ajustées à la validation, écart d'expédition (couleur et texte). */
export function OrderFlags({ order }: { order: Pick<AdvOrderSummary, 'is_adjusted' | 'has_shipping_gap'> }) {
  const { t } = useTranslation()
  if (!order.is_adjusted && !order.has_shipping_gap) return null
  return (
    <span className="inline-flex flex-wrap gap-1">
      {order.is_adjusted && (
        <span title={t('backoffice.orders.flags.adjustedHint')}>
          <Badge tone="warning">{t('backoffice.orders.flags.adjusted')}</Badge>
        </span>
      )}
      {order.has_shipping_gap && (
        <span title={t('backoffice.orders.flags.gapHint')}>
          <Badge tone="danger">{t('backoffice.orders.flags.gap')}</Badge>
        </span>
      )}
    </span>
  )
}
