import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, Outlet, useLocation } from 'react-router'
import type { Role } from '../api/types'
import { NoOfflineData } from '../offline/OfflineDataNotice'
import { Alert, Button, ErrorAlert, Spinner } from '../ui'
import { homePath, useCurrentUser } from './session'

export const LOGIN_PATH = '/connexion'
export const CHANGE_PASSWORD_PATH = '/mot-de-passe'

/**
 * Exige une session ; impose le changement de mot de passe (EF-AUTH-02).
 * Le serveur reste seul juge des droits (RG-S02) : ces gardes ne servent
 * qu'à la navigation.
 *
 * Hors ligne (lot 8, §6.1) : l'utilisateur relu du cache de l'appareil
 * s'affiche, même si sa relecture attend le réseau ou vient d'échouer faute
 * de réseau ; au retour du réseau, un 401 renvoie à la connexion. Sans
 * utilisateur enregistré, un message remplace l'attente sans fin.
 */
export function RequireAuth({ children }: { children?: ReactNode }) {
  const { data: user, isPending, error, refetch, fetchStatus } = useCurrentUser()
  const location = useLocation()
  const { t } = useTranslation()

  if (isPending) {
    return fetchStatus === 'paused' ? (
      <div className="mx-auto max-w-md p-6">
        <NoOfflineData />
      </div>
    ) : (
      <Spinner />
    )
  }
  if (error && !user) {
    return (
      <div className="mx-auto flex max-w-md flex-col gap-4 p-6">
        <ErrorAlert error={error} />
        <Button variant="secondary" onClick={() => void refetch()}>
          {t('common.retry')}
        </Button>
      </div>
    )
  }
  if (!user) {
    return <Navigate to={LOGIN_PATH} replace state={{ from: `${location.pathname}${location.search}` }} />
  }
  if (user.must_change_password && location.pathname !== CHANGE_PASSWORD_PATH) {
    return <Navigate to={CHANGE_PASSWORD_PATH} replace />
  }

  return children ?? <Outlet />
}

export function RequireRole({ roles, children }: { roles: Role[]; children?: ReactNode }) {
  const { data: user } = useCurrentUser()
  const { t } = useTranslation()

  if (!user || !roles.includes(user.role)) {
    return (
      <div className="mx-auto max-w-md p-6">
        <Alert tone="error">{t('errors.forbidden')}</Alert>
      </div>
    )
  }

  return children ?? <Outlet />
}

/** Redirige vers l'espace du rôle de l'utilisateur connecté. */
export function RoleHomeRedirect() {
  const { data: user } = useCurrentUser()
  return <Navigate to={user ? homePath(user.role) : LOGIN_PATH} replace />
}
