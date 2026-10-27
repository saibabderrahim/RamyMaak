import { useTranslation } from 'react-i18next'
import { useAuthenticatedUser } from '../../shared/auth/session'
import { formatDateTime } from '../../shared/format'
import { InstallHelp } from '../../shared/pwa/components'
import { Card, PageHeader } from '../../shared/ui'
import { ChangePasswordForm } from '../auth/ChangePasswordForm'
import { DevicesCard } from './DevicesCard'
import { MyCompanyCard } from './MyCompanyCard'
import { NotificationsCard } from './NotificationsCard'

/**
 * Profil (EF-CLT-06) : compte, entreprise (comptes clients), notifications
 * de l'appareil et rappels, appareils connectés avec la déconnexion de tous
 * les appareils (EF-AUTH-07, lot 8 §5), aide à l'installation de
 * l'application, mot de passe.
 */
export default function ProfilePage() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()

  return (
    <>
      <PageHeader title={t('profile.title')} />
      <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
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

        {user.role === 'client' && user.client_id !== null && <MyCompanyCard clientId={user.client_id} />}

        <NotificationsCard user={user} />

        <DevicesCard />

        <Card title={t('profile.app')}>
          <InstallHelp />
        </Card>

        <Card title={t('auth.password.title')}>
          <ChangePasswordForm user={user} />
        </Card>
      </div>
    </>
  )
}
