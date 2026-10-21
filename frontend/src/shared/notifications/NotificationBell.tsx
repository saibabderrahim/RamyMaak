import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { NavLink } from 'react-router'
import { useLiveNotifications, useUnreadNotificationsCount } from './api'

/** Au-delà, la pastille affiche « 99+ » (le libellé accessible garde le nombre exact). */
const MAX_BADGE = 99

function BellIcon() {
  return (
    <svg viewBox="0 0 24 24" className="size-6" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <path strokeLinecap="round" strokeLinejoin="round" d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
      <path strokeLinecap="round" strokeLinejoin="round" d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
    </svg>
  )
}

/**
 * Cloche de l'en-tête, pour tous les rôles (§7, EF-NOT-01) : nombre de
 * notifications non lues, tenu à jour en temps réel sur `user.{id}`, sinon
 * toutes les 30 s (§9.6) ; mène au centre de notifications. Une
 * notification reçue est annoncée aux lecteurs d'écran.
 */
export function NotificationBell() {
  const { t } = useTranslation()
  const [received, setReceived] = useState('')
  const refetchInterval = useLiveNotifications((notification) => setReceived(t('notifications.bell.received', { title: notification.title })))
  const count = useUnreadNotificationsCount(refetchInterval)

  // En erreur (hors ligne…), la cloche reste disponible, sans pastille.
  const unread = count.data ?? 0
  const label = unread > 0 ? t('notifications.bell.unread', { count: unread }) : t('notifications.bell.label')

  return (
    <>
      <NavLink
        to="/notifications"
        aria-label={label}
        title={label}
        className={({ isActive }) =>
          `relative inline-flex size-11 shrink-0 items-center justify-center rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
            isActive ? 'bg-brand-50 text-brand-800' : 'text-slate-700 hover:bg-slate-100'
          }`
        }
      >
        <BellIcon />
        {unread > 0 && (
          <span
            aria-hidden="true"
            data-testid="notification-badge"
            className="absolute end-0.5 top-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-xs leading-none font-bold text-white ring-2 ring-white"
          >
            {unread > MAX_BADGE ? `${MAX_BADGE}+` : unread}
          </span>
        )}
      </NavLink>
      <span className="sr-only" aria-live="polite">
        {received}
      </span>
    </>
  )
}
