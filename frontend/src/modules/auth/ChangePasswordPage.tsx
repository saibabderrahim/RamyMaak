import { useTranslation } from 'react-i18next'
import { Navigate } from 'react-router'
import { homePath, useAuthenticatedUser } from '../../shared/auth/session'
import { Alert, Card } from '../../shared/ui'
import { ChangePasswordForm } from './ChangePasswordForm'

/**
 * Écran imposé à la première connexion et après une réinitialisation
 * (EF-AUTH-02). Une fois le mot de passe changé, le profil renvoyé par le
 * serveur lève l'obligation et l'utilisateur rejoint son espace.
 */
export default function ChangePasswordPage() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()

  if (!user.must_change_password) return <Navigate to={homePath(user.role)} replace />

  return (
    <div className="flex min-h-dvh items-center justify-center bg-slate-50 px-4 py-10">
      <Card title={t('auth.password.title')} className="w-full max-w-md">
        <Alert tone="info" className="mb-4">
          {t('auth.password.forcedIntro')}
        </Alert>
        <ChangePasswordForm user={user} />
      </Card>
    </div>
  )
}
