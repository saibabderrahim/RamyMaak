import { type FormEvent, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { RegionalOrder, RejectOrderBody } from '../../../../shared/api/types'
import { useOnline } from '../../../../shared/pwa/online'
import { ReasonSelect, requiresComment, useReasonCodes } from '../../../../shared/reasons'
import { Alert, Button, Field, Textarea } from '../../../../shared/ui'
import { Dialog } from '../../../../shared/ui/Dialog'
import { decisionErrorText, fieldMessages } from './messages'
import { REJECTION_COMMENT_MAX } from './model'

type Props = {
  order: Pick<RegionalOrder, 'number' | 'version'>
  pending: boolean
  /** Refus du serveur à la dernière tentative. */
  error: unknown
  onSubmit: (body: RejectOrderBody) => void
  onClose: () => void
}

/**
 * Rejet d'une commande (EF-REG-05, RG-V06) : motif REJECTION obligatoire,
 * commentaire — visible du client — obligatoire si le motif l'exige (AUTRE).
 * Jamais bloqué par l'heure limite de validation ni par le statut du client (I-71).
 */
export function RejectDialog({ order, pending, error, onSubmit, onClose }: Props) {
  const { t } = useTranslation()
  const online = useOnline()
  const formId = useId()
  const reasons = useReasonCodes('REJECTION')
  const [reason, setReason] = useState('')
  const [comment, setComment] = useState('')
  const [attempted, setAttempted] = useState(false)

  const messages = fieldMessages(error)
  const reasonMissing = reason === ''
  const commentMissing = !reasonMissing && requiresComment(reasons.data, 'REJECTION', reason) && comment.trim() === ''

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (reasonMissing || commentMissing) {
      setAttempted(true)
      return
    }
    const text = comment.trim()
    onSubmit({ version: order.version, reason_code: reason, comment: text === '' ? null : text })
  }

  return (
    <Dialog title={t('orders.decision.reject.title', { number: order.number })} onClose={pending ? () => undefined : onClose}>
      <form id={formId} onSubmit={submit} noValidate className="flex flex-col gap-4 text-sm text-slate-700">
        <p>{t('orders.decision.reject.intro')}</p>
        <Field label={t('orders.decision.reject.reason')} error={attempted && reasonMissing ? t('orders.decision.reject.reasonRequired') : undefined}>
          {(props) => <ReasonSelect type="REJECTION" {...props} value={reason} disabled={pending} onChange={(event) => setReason(event.target.value)} />}
        </Field>
        <Field
          label={t('orders.decision.reject.comment')}
          hint={t('orders.decision.reject.commentHint', { count: comment.length, max: REJECTION_COMMENT_MAX })}
          error={attempted && commentMissing ? t('orders.decision.reject.commentRequired') : undefined}
        >
          {(props) => (
            <Textarea
              {...props}
              rows={3}
              maxLength={REJECTION_COMMENT_MAX}
              value={comment}
              disabled={pending}
              onChange={(event) => setComment(event.target.value)}
            />
          )}
        </Field>
        {error !== null && error !== undefined && (
          <Alert tone="error">
            {messages.length > 0 ? (
              <ul className="list-disc ps-5">
                {messages.map((message) => (
                  <li key={message}>{message}</li>
                ))}
              </ul>
            ) : (
              decisionErrorText(t, error)
            )}
          </Alert>
        )}
        {!online && <Alert tone="warning">{t('orders.decision.offline')}</Alert>}
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="danger" disabled={pending || !online}>
            {pending ? t('orders.decision.reject.rejecting') : t('orders.decision.reject.confirm')}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
