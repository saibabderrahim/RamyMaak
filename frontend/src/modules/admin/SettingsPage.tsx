import { useId } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../shared/api/client'
import type { Settings } from '../../shared/api/types'
import { Alert, Button, Card, Checkbox, ErrorAlert, Field, Input, PageHeader, Select, Spinner } from '../../shared/ui'
import { useSettings, useUpdateSettings } from './api'

/** Paramètres généraux (EF-ADM-04). */
export default function SettingsPage() {
  const { t } = useTranslation()
  const settings = useSettings()
  // La mutation vit ici : le formulaire est recréé avec les valeurs
  // enregistrées, sans perdre le message de confirmation.
  const update = useUpdateSettings()

  return (
    <>
      <PageHeader title={t('admin.settings.title')} />
      <ErrorAlert error={settings.error} />
      {settings.isPending && <Spinner />}
      {update.isSuccess && (
        <Alert tone="success" className="mb-6">
          {t('admin.settings.saved')}
        </Alert>
      )}
      <ErrorAlert error={update.error} className="mb-6" />
      {settings.data && (
        <SettingsForm
          key={JSON.stringify(settings.data)}
          initial={settings.data}
          saving={update.isPending}
          fieldErrors={update.error instanceof ApiError ? update.error.fieldErrors : {}}
          onSave={(values) => update.mutate(values)}
        />
      )}
    </>
  )
}

type FormProps = {
  initial: Settings
  saving: boolean
  fieldErrors: Record<string, string[]>
  onSave: (values: Settings) => void
}

const numberField = { valueAsNumber: true } as const

function SettingsForm({ initial, saving, fieldErrors, onSave }: FormProps) {
  const { t } = useTranslation()
  const weekdays = t('admin.settings.weekdays', { returnObjects: true })
  const pushHintId = useId()

  const { register, handleSubmit, control, setValue } = useForm<Settings>({ defaultValues: initial })
  const workingDays = useWatch({ control, name: 'calendar.working_days' })

  const toggleDay = (day: number) => {
    const next = workingDays.includes(day) ? workingDays.filter((d) => d !== day) : [...workingDays, day].sort()
    setValue('calendar.working_days', next, { shouldDirty: true })
  }

  const onSubmit = handleSubmit(onSave)

  // Les erreurs de validation du serveur sont indexées par clé « section.champ ».
  const err = (key: string) => fieldErrors[key]?.[0]

  const text = (key: Parameters<typeof register>[0], label: string) => (
    <Field label={label} error={err(key)}>
      {(props) => <Input {...props} {...register(key)} type="time" />}
    </Field>
  )

  const number = (key: Parameters<typeof register>[0], label: string) => (
    <Field label={label} error={err(key)}>
      {(props) => <Input {...props} {...register(key, numberField)} type="number" inputMode="numeric" min={0} />}
    </Field>
  )

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">

      <Card title={t('admin.settings.sections.cutoffs')}>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {text('cutoffs.distribution', t('admin.settings.fields.distribution'))}
          {text('cutoffs.ordering', t('admin.settings.fields.ordering'))}
          {text('cutoffs.validation', t('admin.settings.fields.validation'))}
          {text('cutoffs.closing', t('admin.settings.fields.closing'))}
        </div>
        <Checkbox className="mt-2" label={t('admin.settings.fields.validationBlocking')} {...register('cutoffs.validation_blocking')} />
      </Card>

      <Card title={t('admin.settings.sections.calendar')}>
        <div className="flex flex-wrap gap-x-6">
          {weekdays.map((label, day) => (
            <Checkbox key={label} label={label} checked={workingDays.includes(day)} onChange={() => toggleDay(day)} />
          ))}
        </div>
        {err('calendar.working_days') && <p className="text-sm text-red-700">{err('calendar.working_days')}</p>}
      </Card>

      <Card title={t('admin.settings.sections.orders')}>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label={t('admin.settings.fields.noLimitPolicy')} error={err('limits.no_limit_policy')}>
            {(props) => (
              <Select {...props} {...register('limits.no_limit_policy')}>
                <option value="FREE">{t('admin.settings.fields.policyFree')}</option>
                <option value="REQUIRED">{t('admin.settings.fields.policyRequired')}</option>
              </Select>
            )}
          </Field>
          {number('orders.max_per_day', t('admin.settings.fields.maxPerDay'))}
          <Field label={t('admin.settings.fields.minAmount')} error={err('orders.min_amount')}>
            {(props) => (
              <Input
                {...props}
                {...register('orders.min_amount', { setValueAs: (v: unknown) => (v === '' || v === null ? null : Number(v)) })}
                type="number"
                inputMode="decimal"
                min={0}
              />
            )}
          </Field>
        </div>
      </Card>

      <Card title={t('admin.settings.sections.sessions')}>
        <div className="grid gap-4 sm:grid-cols-3">
          {number('sessions.remember_days', t('admin.settings.fields.rememberDays'))}
          {number('sessions.staff_absolute_hours', t('admin.settings.fields.staffAbsoluteHours'))}
          {number('sessions.staff_idle_minutes', t('admin.settings.fields.staffIdleMinutes'))}
        </div>
      </Card>

      <Card title={t('admin.settings.sections.passwords')}>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {number('passwords.min_length', t('admin.settings.fields.minLength'))}
          {number('passwords.min_length_staff', t('admin.settings.fields.minLengthStaff'))}
          {number('auth.max_failed_logins', t('admin.settings.fields.maxFailedLogins'))}
          {number('auth.lockout_minutes', t('admin.settings.fields.lockoutMinutes'))}
        </div>
      </Card>

      <Card title={t('admin.settings.sections.notifications')}>
        <div className="grid gap-4 sm:grid-cols-2">
          {text('notifications.quiet_start', t('admin.settings.fields.quietStart'))}
          {text('notifications.quiet_end', t('admin.settings.fields.quietEnd'))}
        </div>
        {/* Lot 8 (§2.1) : interrupteur général du push ; la plage ci-dessus borne les envois. */}
        <Checkbox
          className="mt-2"
          label={t('push.settings.pushEnabled')}
          aria-describedby={pushHintId}
          {...register('notifications.push_enabled')}
        />
        <p id={pushHintId} className="text-sm text-slate-600">
          {t('push.settings.pushHint')}
        </p>
        {err('notifications.push_enabled') && <p className="text-sm text-danger">{err('notifications.push_enabled')}</p>}
        <Checkbox className="mt-2" label={t('admin.settings.fields.emailEnabled')} {...register('notifications.email_enabled')} />
      </Card>

      <Button type="submit" className="self-start" disabled={saving}>
        {saving ? t('common.saving') : t('common.save')}
      </Button>
    </form>
  )
}
