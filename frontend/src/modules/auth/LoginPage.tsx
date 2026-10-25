import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { z } from 'zod'
import { consumeSessionExpired } from '../../shared/api/queryClient'
import { homePath, useCurrentUser, useLogin } from '../../shared/auth/session'
import { Alert, Button, Checkbox, ErrorAlert, Field, Input } from '../../shared/ui'

const schema = z.object({
  username: z.string().trim().min(1),
  password: z.string().min(1),
  remember: z.boolean(),
})

type FormValues = z.infer<typeof schema>

export default function LoginPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const { data: currentUser } = useCurrentUser()
  const login = useLogin()
  const [expired] = useState(consumeSessionExpired)

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { username: '', password: '', remember: false },
  })

  if (currentUser && !login.isPending) {
    return <Navigate to={homePath(currentUser.role)} replace />
  }

  const from = (location.state as { from?: string } | null)?.from

  const onSubmit = handleSubmit((values) => {
    login.mutate(values, {
      onSuccess: (user) => navigate(from && from !== '/' ? from : homePath(user.role), { replace: true }),
    })
  })

  const missing = errors.username || errors.password

  // Charte Ramy (lot 7) : photo produit à côté du formulaire sur grand écran, en bandeau au-dessus sur téléphone.
  return (
    <div className="grid min-h-dvh grid-cols-1 bg-white lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
      <div className="flex h-52 items-center justify-center overflow-hidden border-b border-slate-200 bg-photo lg:order-2 lg:h-auto lg:border-b-0 lg:border-s">
        <img
          src="/brand/bouteille-hibiscus-menthe.webp"
          alt={t('auth.login.photo')}
          width={560}
          height={700}
          className="h-full w-auto max-w-none lg:h-auto lg:w-3/4 lg:max-w-[520px]"
        />
      </div>

      <div className="flex justify-center px-4 py-8 lg:items-center lg:px-10">
        <form onSubmit={onSubmit} noValidate className="flex w-full max-w-sm flex-col gap-4">
          <div className="flex flex-col items-start gap-2">
            <img src="/brand/ramy-logo.png" alt="Ramy" width={120} height={62} className="h-14 w-auto" />
            <p className="text-sm text-slate-600">{t('app.tagline')}</p>
          </div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{t('auth.login.title')}</h1>

          {expired && <Alert tone="warning">{t('errors.sessionExpired')}</Alert>}
          {missing && <Alert tone="error">{t('auth.login.required')}</Alert>}
          <ErrorAlert error={login.error} />

          <Field label={t('auth.login.username')} hint={t('auth.login.usernameHint')}>
            {(props) => (
              <Input {...props} {...register('username')} autoComplete="username" autoCapitalize="none" autoCorrect="off" spellCheck={false} />
            )}
          </Field>

          <Field label={t('auth.login.password')}>
            {(props) => <Input {...props} {...register('password')} type="password" autoComplete="current-password" />}
          </Field>

          <Checkbox label={t('auth.login.remember')} {...register('remember')} />

          <Button type="submit" className="min-h-12 text-base" disabled={login.isPending}>
            {login.isPending ? t('auth.login.submitting') : t('auth.login.submit')}
          </Button>

          <p className="text-sm text-slate-600">{t('auth.login.forgotten')}</p>
        </form>
      </div>
    </div>
  )
}
