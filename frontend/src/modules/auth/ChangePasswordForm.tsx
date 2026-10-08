import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { CurrentUser } from '../../shared/api/types'
import { useChangePassword } from '../../shared/auth/session'
import { applyServerErrors } from '../../shared/forms'
import { Button, ErrorAlert, Field, Input } from '../../shared/ui'

type FormValues = {
  current_password: string
  password: string
  password_confirmation: string
}

const FIELDS = ['current_password', 'password', 'password_confirmation'] as const

/**
 * Changement de mot de passe (EF-AUTH-02, EF-AUTH-03). Le serveur applique
 * la politique complète (mots de passe courants, identifiant…).
 */
export function ChangePasswordForm({ user, onSuccess }: { user: CurrentUser; onSuccess?: (user: CurrentUser) => void }) {
  const { t } = useTranslation()
  const changePassword = useChangePassword()
  const min = user.password_min_length

  const schema = z
    .object({
      current_password: z.string().min(1),
      password: z
        .string()
        .min(min, t('auth.password.rules', { min }))
        .regex(/\p{L}/u, t('auth.password.rules', { min }))
        .regex(/\d/, t('auth.password.rules', { min })),
      password_confirmation: z.string(),
    })
    .refine((values) => values.password === values.password_confirmation, {
      path: ['password_confirmation'],
      message: t('auth.password.mismatch'),
    })

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })

  const onSubmit = handleSubmit((values) => {
    changePassword.mutate(values, {
      onSuccess: (updated) => {
        reset()
        onSuccess?.(updated)
      },
      onError: (error) => applyServerErrors(error, setError, FIELDS),
    })
  })

  const serverError = changePassword.error && !Object.keys(errors).length ? changePassword.error : null

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <ErrorAlert error={serverError} />
      {changePassword.isSuccess && (
        <p role="status" className="text-sm text-emerald-700">
          {t('auth.password.success')}
        </p>
      )}

      <Field label={t('auth.password.current')} error={errors.current_password?.message}>
        {(props) => <Input {...props} {...register('current_password')} type="password" autoComplete="current-password" />}
      </Field>

      <Field label={t('auth.password.new')} hint={t('auth.password.rules', { min })} error={errors.password?.message}>
        {(props) => <Input {...props} {...register('password')} type="password" autoComplete="new-password" />}
      </Field>

      <Field label={t('auth.password.confirm')} error={errors.password_confirmation?.message}>
        {(props) => <Input {...props} {...register('password_confirmation')} type="password" autoComplete="new-password" />}
      </Field>

      <Button type="submit" className="self-start" disabled={changePassword.isPending}>
        {changePassword.isPending ? t('common.saving') : t('auth.password.submit')}
      </Button>
    </form>
  )
}
