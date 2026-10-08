import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { LOGIN_PATH } from '../../shared/auth/guards'
import { useAuthenticatedUser, useLogoutAll } from '../../shared/auth/session'
import { formatDateTime } from '../../shared/format'
import { InstallHelp } from '../../shared/pwa/components'
import { Button, Card, ErrorAlert, PageHeader } from '../../shared/ui'
import { ChangePasswordForm } from '../auth/ChangePasswordForm'

/**
 * Profil (EF-CLT-06) : compte, mot de passe, déconnexion de tous les
 * appareils (EF-AUTH-07), aide à l'installation de l'application.
 */
export default function ProfilePage() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const logoutAll = useLogoutAll()
  const navigate = useNavigate()

  const onLogoutAll = () => {
    if (!window.confirm(t('auth.logoutAllConfirm'))) return
    logoutAll.mutate(undefined, { onSuccess: () => navigate(LOGIN_PATH, { replace: true }) })
  }

  return (
    <>
      <PageHeader title={t('profile.title')} />
      <div className="grid gap-6 lg:grid-cols-2">
        <Card title={t('profile.identity')}>
          <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
            <dt className="text-slate-500">{t('admin.users.fields.username')}</dt>
            <dd className="font-medium text-slate-900">{user.username}</dd>
            <dt className="text-slate-500">{t('admin.users.fields.name')}</dt>
            <dd className="text-slate-900">{user.name}</dd>
            <dt className="text-slate-500">{t('admin.users.fields.role')}</dt>
            <dd className="text-slate-900">{t(`roles.${user.role}`)}</dd>
            <dt className="text-slate-500">{t('profile.lastLogin')}</dt>
            <dd className="text-slate-900">{formatDateTime(user.last_login_at)}</dd>
          </dl>
        </Card>

        <Card title={t('profile.app')}>
          <InstallHelp />
        </Card>

        <Card title={t('auth.password.title')}>
          <ChangePasswordForm user={user} />
        </Card>

        <Card title={t('profile.security')}>
          <div className="flex flex-col gap-3">
            <ErrorAlert error={logoutAll.error} />
            <Button variant="secondary" className="self-start" onClick={onLogoutAll} disabled={logoutAll.isPending}>
              {t('auth.logoutAll')}
            </Button>
          </div>
        </Card>
      </div>
    </>
  )
}
