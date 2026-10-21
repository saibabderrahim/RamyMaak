import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../../shared/api/client'
import type { AdvOrder } from '../../../shared/api/types'
import { ReasonSelect, requiresComment, useReasonCodes } from '../../../shared/reasons'
import { Button, ErrorAlert, Field, Textarea } from '../../../shared/ui'
import { Dialog } from '../shared/ui'
import { useCancelOrder } from './api'

const COMMENT_MAX = 500

type Props = {
  order: AdvOrder
  open: boolean
  online: boolean
  onClose: () => void
  onDone: () => void
}

/**
 * Annulation exceptionnelle d'une commande validée (RG-V07, I-75, I-76) :
 * motif d'annulation et commentaire (obligatoire avec « Autre »), depuis
 * Validée ou En préparation. Les quantités validées sont restituées au quota.
 * Les refus du serveur (étape dépassée, version changée) s'affichent dans la boîte.
 */
export function CancelDialog({ order, open, online, onClose, onDone }: Props) {
  const { t } = useTranslation()
  return (
    <Dialog open={open} title={t('backoffice.orders.cancel.title', { number: order.number })} onClose={onClose}>
      {open && <CancelForm order={order} online={online} onClose={onClose} onDone={onDone} />}
    </Dialog>
  )
}

function CancelForm({ order, online, onClose, onDone }: Omit<Props, 'open'>) {
  const { t } = useTranslation()
  const cancel = useCancelOrder(order.id)
  const reasons = useReasonCodes('CANCELLATION')
  const [reason, setReason] = useState('')
  const [comment, setComment] = useState('')
  const [attempted, setAttempted] = useState(false)

  const commentRequired = requiresComment(reasons.data, 'CANCELLATION', reason)
  const reasonError = attempted && reason === '' ? t('backoffice.orders.cancel.reasonRequired') : undefined
  const commentError = attempted && commentRequired && comment.trim() === '' ? t('backoffice.orders.cancel.commentRequired') : undefined
  const fields = cancel.error instanceof ApiError ? cancel.error.fieldErrors : {}

  const submit = () => {
    setAttempted(true)
    if (reason === '' || (commentRequired && comment.trim() === '')) return
    cancel.mutate(
      { version: order.version, reason_code: reason, comment: comment.trim() === '' ? null : comment.trim() },
      { onSuccess: onDone },
    )
  }

  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        submit()
      }}
    >
      <p className="text-sm text-slate-700">{t('backoffice.orders.cancel.intro')}</p>
      {Object.keys(fields).length === 0 && <ErrorAlert error={cancel.error} />}
      <Field label={t('backoffice.orders.cancel.reason')} error={reasonError ?? fields.reason_code?.[0]}>
        {(props) => <ReasonSelect type="CANCELLATION" {...props} value={reason} onChange={(event) => setReason(event.target.value)} />}
      </Field>
      <Field label={t('backoffice.orders.cancel.comment')} hint={t('backoffice.orders.cancel.commentHint')} error={commentError ?? fields.comment?.[0]}>
        {(props) => <Textarea {...props} value={comment} maxLength={COMMENT_MAX} onChange={(event) => setComment(event.target.value)} />}
      </Field>
      {!online && <p className="text-xs text-slate-700">{t('backoffice.orders.offline')}</p>}
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" onClick={onClose} disabled={cancel.isPending}>
          {t('backoffice.orders.cancel.back')}
        </Button>
        <Button type="submit" variant="danger" disabled={cancel.isPending || !online}>
          {cancel.isPending ? t('backoffice.orders.cancel.submitting') : t('backoffice.orders.cancel.submit')}
        </Button>
      </div>
    </form>
  )
}
