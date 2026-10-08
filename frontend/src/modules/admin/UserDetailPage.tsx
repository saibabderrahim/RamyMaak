import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { formatDateTime } from '../../shared/format'
import { Alert, Button, Card, ErrorAlert, PageHeader, Spinner } from '../../shared/ui'
import { useResetPassword, useUpdateUser, useUser } from './api'
import { TemporaryPasswordNotice, UserStatusBadges } from './components'
import { UserForm } from './UserForm'

export default function UserDetailPage() {
  const { t } = useTranslation()
  const id = Number(useParams().id)
  const user = useUser(id)
  const updateUser = useUpdateUser(id)
  const statusUpdate = useUpdateUser(id)
  const resetPassword = useResetPassword(id)

  if (user.isPending) return <Spinner />
  if (user.error) return <ErrorAlert error={user.error} />

  const account = user.data

  const toggleActive = () => {
    if (account.is_active && !window.confirm(t('admin.users.deactivateConfirm'))) return
    statusUpdate.mutate({ is_active: !account.is_active })
  }

  const reset = () => {
    if (window.confirm(t('admin.users.resetConfirm'))) resetPassword.mutate()
  }

  return (
    <>
      <PageHeader
        title={account.username}
        actions={
          <Link to=".." relative="path" className="text-sm text-brand-700 hover:underline">
            {t('common.back')}
          </Link>
        }
      />

      <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <Card title={t('admin.users.edit')}>
          {updateUser.isSuccess && (
            <Alert tone="success" className="mb-4">
              {t('admin.users.updated')}
            </Alert>
          )}
          <UserForm
            key={account.updated_at}
            user={account}
            submitting={updateUser.isPending}
            error={updateUser.error}
            onSubmit={({ username: _username, ...values }) =>
              updateUser.mutateAsync({ ...values, client_id: values.role === 'client' ? values.client_id : null })
            }
          />
        </Card>

        <Card title={t('profile.security')}>
          <div className="flex flex-col gap-4">
            <UserStatusBadges user={account} />
            <p className="text-sm text-slate-600">
              {t('admin.users.columns.lastLogin')} : {formatDateTime(account.last_login_at)}
            </p>

            <ErrorAlert error={statusUpdate.error ?? resetPassword.error} />
            {resetPassword.data && <TemporaryPasswordNotice user={resetPassword.data.user} password={resetPassword.data.temporary_password} />}

            <Button variant="secondary" onClick={reset} disabled={resetPassword.isPending}>
              {t('admin.users.resetPassword')}
            </Button>
            {account.is_locked && (
              <Button variant="secondary" onClick={() => statusUpdate.mutate({ unlock: true })} disabled={statusUpdate.isPending}>
                {t('admin.users.unlock')}
              </Button>
            )}
            <Button variant={account.is_active ? 'danger' : 'primary'} onClick={toggleActive} disabled={statusUpdate.isPending}>
              {account.is_active ? t('admin.users.deactivate') : t('admin.users.activate')}
            </Button>
          </div>
        </Card>
      </div>
    </>
  )
}
