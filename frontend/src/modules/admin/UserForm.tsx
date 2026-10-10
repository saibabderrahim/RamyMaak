import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { ROLES, type User } from '../../shared/api/types'
import { applyServerErrors } from '../../shared/forms'
import { Button, ErrorAlert, Field, Input, Select } from '../../shared/ui'
import type { UserInput } from './api'
import { ClientPicker, type PickedClient } from './ClientPicker'

const optionalText = z
  .string()
  .trim()
  .transform((value) => (value === '' ? null : value))

const schema = z.object({
  username: z.string().trim().toLowerCase().min(3).max(50).regex(/^[a-z0-9][a-z0-9._-]*$/),
  name: z.string().trim().min(1).max(255),
  email: optionalText.pipe(z.email().nullable()),
  phone: optionalText,
  role: z.enum(ROLES),
  client_id: z
    .string()
    .trim()
    .transform((value) => (value === '' ? null : Number(value)))
    .pipe(z.number().int().positive().nullable()),
})

type FormInput = z.input<typeof schema>

const FIELDS = ['username', 'name', 'email', 'phone', 'role', 'client_id'] as const

type Props = {
  user?: User
  submitting: boolean
  error: unknown
  onSubmit: (values: UserInput) => Promise<unknown>
}

/**
 * Formulaire de compte (EF-ADM-01). L'identifiant n'est saisi qu'à la
 * création : c'est la clé de connexion de l'utilisateur.
 */
export function UserForm({ user, submitting, error, onSubmit }: Props) {
  const { t } = useTranslation()
  const {
    register,
    handleSubmit,
    setError,
    setValue,
    control,
    formState: { errors, isSubmitted },
  } = useForm<FormInput, unknown, UserInput>({
    resolver: zodResolver(schema),
    defaultValues: {
      username: user?.username ?? '',
      name: user?.name ?? '',
      email: user?.email ?? '',
      phone: user?.phone ?? '',
      role: user?.role ?? 'client',
      client_id: user?.client_id ? String(user.client_id) : '',
    },
  })

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  })

  const isClient = useWatch({ control, name: 'role' }) === 'client'
  const [client, setClient] = useState<PickedClient | null>(user?.client ?? null)
  const pickClient = (picked: PickedClient | null) => {
    setClient(picked)
    setValue('client_id', picked ? String(picked.id) : '', { shouldValidate: isSubmitted })
  }
  const hasFieldErrors = Object.keys(errors).length > 0

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {!hasFieldErrors && <ErrorAlert error={error} />}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('admin.users.fields.username')} hint={t('admin.users.fields.usernameHint')} error={errors.username?.message}>
          {(props) => <Input {...props} {...register('username')} disabled={Boolean(user)} autoCapitalize="none" spellCheck={false} />}
        </Field>
        <Field label={t('admin.users.fields.name')} error={errors.name?.message}>
          {(props) => <Input {...props} {...register('name')} />}
        </Field>
        <Field label={t('admin.users.fields.role')} error={errors.role?.message}>
          {(props) => (
            <Select {...props} {...register('role')}>
              {ROLES.map((role) => (
                <option key={role} value={role}>
                  {t(`roles.${role}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        {isClient && <ClientPicker value={client} onChange={pickClient} error={errors.client_id?.message} />}
        <Field label={t('admin.users.fields.email')} error={errors.email?.message}>
          {(props) => <Input {...props} {...register('email')} type="email" autoComplete="off" />}
        </Field>
        <Field label={t('admin.users.fields.phone')} error={errors.phone?.message}>
          {(props) => <Input {...props} {...register('phone')} type="tel" />}
        </Field>
      </div>

      <Button type="submit" className="self-start" disabled={submitting}>
        {submitting ? t('common.saving') : t('common.save')}
      </Button>
    </form>
  )
}
