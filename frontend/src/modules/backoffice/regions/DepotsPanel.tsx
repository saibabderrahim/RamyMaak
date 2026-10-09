import { zodResolver } from '@hookform/resolvers/zod'
import type { TFunction } from 'i18next'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { Depot } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { Alert, Button, Card, Checkbox, ErrorAlert, Field, Input, Spinner } from '../../../shared/ui'
import { ActiveBadge, DataTable, Dialog, DialogActions, EmptyState, TableHead, Th } from '../shared/ui'
import { type DepotInput, useCreateDepot, useDepots, useUpdateDepot } from './api'

function makeSchema(t: TFunction) {
  return z.object({
    code: z
      .string()
      .trim()
      .toUpperCase()
      .regex(/^[A-Z0-9_-]{2,20}$/, t('backoffice.regions.codeFormat')),
    name: z.string().trim().min(1).max(255),
    address: z
      .string()
      .trim()
      .max(255)
      .transform((value) => (value === '' ? null : value)),
    is_active: z.boolean(),
  })
}

type FormInput = z.input<ReturnType<typeof makeSchema>>

const FIELDS = ['code', 'name', 'address', 'is_active'] as const

type DepotFormProps = {
  depot?: Depot
  submitting: boolean
  error: unknown
  onSubmit: (values: DepotInput) => Promise<unknown>
  onCancel: () => void
}

/** Dépôt d'expédition (option, EF-ADM-03) : le code ne change pas après la création. */
function DepotForm({ depot, submitting, error, onSubmit, onCancel }: DepotFormProps) {
  const { t } = useTranslation()
  const schema = useMemo(() => makeSchema(t), [t])
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormInput, unknown, DepotInput>({
    resolver: zodResolver(schema),
    defaultValues: {
      code: depot?.code ?? '',
      name: depot?.name ?? '',
      address: depot?.address ?? '',
      is_active: depot?.is_active ?? true,
    },
  })

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  })

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {Object.keys(errors).length === 0 && <ErrorAlert error={error} />}
      <Field
        label={t('backoffice.regions.depots.fields.code')}
        hint={depot ? t('backoffice.regions.fields.codeLocked') : t('backoffice.regions.fields.codeHint')}
        error={errors.code?.message}
      >
        {(props) => (
          <Input
            {...props}
            {...register('code')}
            readOnly={Boolean(depot)}
            className={depot ? 'bg-slate-100' : 'uppercase'}
            autoCapitalize="characters"
            spellCheck={false}
          />
        )}
      </Field>
      <Field label={t('backoffice.regions.depots.fields.name')} error={errors.name?.message}>
        {(props) => <Input {...props} {...register('name')} />}
      </Field>
      <Field label={t('backoffice.regions.depots.fields.address')} error={errors.address?.message}>
        {(props) => <Input {...props} {...register('address')} />}
      </Field>
      <Checkbox label={t('backoffice.regions.depots.fields.active')} {...register('is_active')} />
      {errors.is_active?.message && (
        <p role="alert" className="text-sm text-red-700">
          {errors.is_active.message}
        </p>
      )}
      <DialogActions submitting={submitting} onCancel={onCancel} />
    </form>
  )
}

function CreateDepotDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const createDepot = useCreateDepot()
  return (
    <Dialog open title={t('backoffice.regions.depots.create')} onClose={onClose}>
      <DepotForm
        submitting={createDepot.isPending}
        error={createDepot.error}
        onCancel={onClose}
        onSubmit={async (values) => {
          await createDepot.mutateAsync(values)
          onDone()
        }}
      />
    </Dialog>
  )
}

function EditDepotDialog({ depot, onClose, onDone }: { depot: Depot; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const updateDepot = useUpdateDepot(depot.id)
  return (
    <Dialog open title={t('backoffice.regions.depots.edit', { code: depot.code })} onClose={onClose}>
      <DepotForm
        depot={depot}
        submitting={updateDepot.isPending}
        error={updateDepot.error}
        onCancel={onClose}
        onSubmit={async ({ code: _code, ...values }) => {
          await updateDepot.mutateAsync(values)
          onDone()
        }}
      />
    </Dialog>
  )
}

/** Onglet « Dépôts » des régions, réservé à region.manage : liste, création, modification. */
export function DepotsPanel() {
  const { t } = useTranslation()
  const depots = useDepots()
  const [dialog, setDialog] = useState<'create' | Depot | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const close = () => setDialog(null)

  return (
    <Card
      title={t('backoffice.regions.tabs.depots')}
      actions={
        <Button
          onClick={() => {
            setNotice(null)
            setDialog('create')
          }}
        >
          {t('backoffice.regions.depots.create')}
        </Button>
      }
    >
      <p className="mb-4 text-sm text-slate-600">{t('backoffice.regions.depots.intro')}</p>
      {notice && (
        <Alert tone="success" className="mb-4">
          {notice}
        </Alert>
      )}
      <ErrorAlert error={depots.error} />
      {depots.isPending && <Spinner />}

      {depots.data && (
        <>
          <DataTable label={t('backoffice.regions.tabs.depots')}>
            <TableHead>
              <Th>{t('backoffice.regions.depots.columns.code')}</Th>
              <Th>{t('backoffice.regions.depots.columns.name')}</Th>
              <Th>{t('backoffice.regions.depots.columns.address')}</Th>
              <Th>{t('backoffice.regions.depots.columns.regions')}</Th>
              <Th>{t('backoffice.regions.depots.columns.status')}</Th>
              <Th>
                <span className="sr-only">{t('backoffice.common.actions')}</span>
              </Th>
            </TableHead>
            <tbody className="divide-y divide-slate-100">
              {depots.data.map((depot) => (
                <tr key={depot.id} className="hover:bg-slate-50">
                  <td className="py-2 pe-4 font-medium">{depot.code}</td>
                  <td className="py-2 pe-4">{depot.name}</td>
                  <td className="py-2 pe-4 text-slate-600">{depot.address ?? '—'}</td>
                  <td className="py-2 pe-4">{depot.regions_count}</td>
                  <td className="py-2 pe-4">
                    <ActiveBadge
                      active={depot.is_active}
                      labels={{ active: t('backoffice.regions.depots.status.active'), inactive: t('backoffice.regions.depots.status.inactive') }}
                    />
                  </td>
                  <td className="py-1 text-end">
                    <Button
                      variant="ghost"
                      aria-label={t('backoffice.regions.depots.edit', { code: depot.code })}
                      onClick={() => {
                        setNotice(null)
                        setDialog(depot)
                      }}
                    >
                      {t('backoffice.common.edit')}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          {depots.data.length === 0 && <EmptyState>{t('backoffice.regions.depots.empty')}</EmptyState>}
        </>
      )}

      {dialog === 'create' && (
        <CreateDepotDialog
          onClose={close}
          onDone={() => {
            close()
            setNotice(t('backoffice.regions.depots.created'))
          }}
        />
      )}
      {dialog !== null && dialog !== 'create' && (
        <EditDepotDialog
          depot={dialog}
          onClose={close}
          onDone={() => {
            close()
            setNotice(t('backoffice.regions.depots.updated'))
          }}
        />
      )}
    </Card>
  )
}
