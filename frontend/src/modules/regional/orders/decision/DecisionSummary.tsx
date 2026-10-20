import { useTranslation } from 'react-i18next'
import type { RegionalOrder } from '../../../../shared/api/types'
import { formatDateTime } from '../../../../shared/format'
import { reasonText } from '../../../../shared/reasons/labels'
import { Badge, Card } from '../../../../shared/ui'

/**
 * Décision prise sur une commande (contrat du lot 6, §3) : validation ou
 * rejet, avec la date et l'auteur réel (titulaire ou suppléant, RG-S03), le
 * motif en clair (rejet, ou annulation par l'ADV, I-75) et son commentaire ;
 * « Validée avec ajustement » quand des lignes ont été réduites (leur motif est sur
 * chaque ligne).
 */
export function DecisionSummary({ order }: { order: RegionalOrder }) {
  const { t } = useTranslation()
  const rejected = order.status === 'REJECTED'
  const cancelled = order.status === 'CANCELLED'
  const reason = reasonText(order.decision_reason, order.decision_reason_code)
  const adjusted = order.lines.filter((line) => line.validated_qty !== null && line.validated_qty < line.requested_qty).length
  const decidedBy = order.decided_by?.name ?? null

  return (
    <Card title={t('orders.decision.title')}>
      <div className="flex flex-col gap-1 text-sm text-slate-800">
        {order.decided_at && (
          <p>
            {t(`orders.decision.view.${rejected ? 'rejected' : 'validated'}`, { date: formatDateTime(order.decided_at) })}
            {decidedBy !== null && ` ${t('orders.decision.view.by', { name: decidedBy })}`}
          </p>
        )}
        {!rejected && order.is_adjusted && (
          <p className="flex flex-wrap items-center gap-2">
            <Badge tone="brand">{t('orders.decision.view.adjusted')}</Badge>
            {adjusted > 0 && <span>{t('orders.decision.view.adjustedLines', { count: adjusted })}</span>}
          </p>
        )}
        {reason !== null && <p>{t(`orders.decision.view.${rejected ? 'reasonRejected' : cancelled ? 'reasonCancelled' : 'reason'}`, { reason })}</p>}
        {order.decision_comment && <p className="break-words">{t('orders.decision.view.comment', { comment: order.decision_comment })}</p>}
      </div>
    </Card>
  )
}
