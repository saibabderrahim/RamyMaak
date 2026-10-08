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

  return (
    <div className="flex min-h-dvh items-center justify-center bg-slate-50 px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center gap-3 text-center">
          <img src="/icons/icon-192.png" alt="" className="size-16 rounded-2xl shadow-sm" />
          <div>
            <h1 className="text-2xl font-bold text-slate-900">{t('app.name')}</h1>
            <p className="text-sm text-slate-600">{t('app.tagline')}</p>
          </div>
        </div>

        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="text-lg font-semibold text-slate-900">{t('auth.login.title')}</h2>

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

          <Button type="submit" disabled={login.isPending}>
            {login.isPending ? t('auth.login.submitting') : t('auth.login.submit')}
          </Button>

          <p className="text-xs text-slate-500">{t('auth.login.forgotten')}</p>
        </form>
      </div>
    </div>
  )
}
