import { zodResolver } from '@hookform/resolvers/zod'
import type { TFunction } from 'i18next'
import { useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { Client } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { ErrorAlert, Field } from '../../../shared/ui'
import { Dialog, DialogActions, Textarea } from '../shared/ui'
import { useUpdateClient } from './api'
import { STATUS_TARGETS, type StatusAction, requiresReason } from './status'

function makeSchema(t: TFunction, required: boolean) {
  return z.object({
    status_reason: z
      .string()
      .trim()
      .max(255)
      .refine((value) => !required || value !== '', t('backoffice.clients.statusDialog.reasonRequired')),
  })
}

type FormValues = { status_reason: string }

type Props = {
  client: Client
  action: StatusAction
  onClose: () => void
  onDone: (client: Client) => void
}

/**
 * Changement de statut d'un client (EF-CLI-04), à effet immédiat : blocage
 * avec motif obligatoire, déblocage, mise en inactivité (comptes
 * désactivés, §3.5), réactivation, ou modification du seul motif.
 */
export function StatusDialog({ client, action, onClose, onDone }: Props) {
  const { t } = useTranslation()
  const required = requiresReason(action)
  const schema = useMemo(() => makeSchema(t, required), [t, required])
  const updateClient = useUpdateClient(client.id)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { status_reason: action === 'editReason' ? (client.status_reason ?? '') : '' },
  })

  const submit = handleSubmit(async ({ status_reason }) => {
    const reason = status_reason === '' ? null : status_reason
    const target = STATUS_TARGETS[action]
    try {
      const updated = await updateClient.mutateAsync(target === null ? { status_reason: reason } : { status: target, status_reason: reason })
      onDone(updated)
    } catch (submitError) {
      applyServerErrors(submitError, setError, ['status_reason'])
    }
  })

  return (
    <Dialog open title={t(`backoffice.clients.statusDialog.${action}.title`)} onClose={onClose}>
      <form onSubmit={submit} noValidate className="flex flex-col gap-4">
        <p className="text-sm text-slate-700">
          <span className="font-semibold">{`${client.code} — ${client.name}`}</span>
          <br />
          {t(`backoffice.clients.statusDialog.${action}.intro`)}
        </p>
        {!errors.status_reason && <ErrorAlert error={updateClient.error} />}
        <Field
          label={t('backoffice.clients.statusDialog.reason')}
          hint={required ? t('backoffice.clients.statusDialog.reasonRequiredHint') : t('backoffice.clients.statusDialog.reasonOptionalHint')}
          error={errors.status_reason?.message}
        >
          {(props) => <Textarea {...props} {...register('status_reason')} maxLength={255} required={required} rows={3} />}
        </Field>
        <DialogActions submitting={updateClient.isPending} onCancel={onClose} submitLabel={t(`backoffice.clients.statusDialog.${action}.confirm`)} />
      </form>
    </Dialog>
  )
}
