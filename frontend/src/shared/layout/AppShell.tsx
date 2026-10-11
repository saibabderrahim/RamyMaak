import { useTranslation } from 'react-i18next'
import { NavLink, Outlet, useNavigate } from 'react-router'
import type { Role } from '../api/types'
import { LOGIN_PATH } from '../auth/guards'
import { homePath, useAuthenticatedUser, useLogout } from '../auth/session'
import { NotificationBell } from '../notifications/NotificationBell'
import { OfflineBanner } from '../pwa/components'

type NavItem = { to: string; label: string; end?: boolean }

function useNavItems(role: Role, permissions: readonly string[]): NavItem[] {
  const { t } = useTranslation()
  const profile = { to: '/profil', label: t('nav.profile') }

  switch (role) {
    case 'admin':
      return [
        { to: '/admin/comptes', label: t('nav.users') },
        { to: '/admin/journees', label: t('nav.days') },
        { to: '/admin/regions', label: t('nav.regions') },
        { to: '/admin/clients', label: t('nav.clients') },
        { to: '/admin/produits', label: t('nav.products') },
        { to: '/admin/imports', label: t('nav.imports') },
        { to: '/admin/journal', label: t('nav.audit') },
        { to: '/admin/parametres', label: t('nav.settings') },
        profile,
      ]
    case 'adv':
      return [
        { to: '/adv', label: t('nav.home'), end: true },
        { to: '/adv/journees', label: t('nav.days') },
        { to: '/adv/regions', label: t('nav.regions') },
        { to: '/adv/clients', label: t('nav.clients') },
        { to: '/adv/produits', label: t('nav.products') },
        // Imports : quotas (quota.edit) et référentiels selon les droits reçus en option.
        ...(permissions.includes('quota.edit') || permissions.includes('product.manage') || permissions.includes('client.manage')
          ? [{ to: '/adv/imports', label: t('nav.imports') }]
          : []),
        profile,
      ]
    case 'regional':
      return [
        { to: '/regional', label: t('nav.home'), end: true },
        { to: '/regional/quotas', label: t('nav.quotas') },
        { to: '/regional/clients', label: t('nav.myClients') },
        profile,
      ]
    case 'client':
      return [{ to: '/client', label: t('nav.home'), end: true }, profile]
  }
}

/** Barre d'onglets en bas de l'écran pour les rôles de terrain (téléphone). */
function hasTabBar(role: Role): boolean {
  return role === 'client' || role === 'regional'
}

/**
 * Coquille commune à tous les rôles : en-tête, navigation (barre fixée en
 * bas de l'écran sur téléphone), bandeau hors ligne.
 */
export function AppShell() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const logout = useLogout()
  const navigate = useNavigate()
  const items = useNavItems(user.role, user.permissions)

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    `flex min-h-11 items-center rounded-lg px-3 text-sm font-medium ${
      isActive ? 'bg-brand-50 text-brand-800' : 'text-slate-700 hover:bg-slate-100'
    }`

  const tabClass = ({ isActive }: { isActive: boolean }) =>
    `flex min-h-14 flex-1 flex-col items-center justify-center text-xs font-medium ${
      isActive ? 'text-brand-700' : 'text-slate-600'
    }`

  return (
    <div className="flex min-h-dvh flex-col">
      <OfflineBanner />
      <header className="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center gap-4 px-4 py-2">
          <NavLink to={homePath(user.role)} className="flex items-center gap-2 font-bold text-brand-700">
            <img src="/icons/icon-192.png" alt="" className="size-8 rounded-lg" />
            {t('app.name')}
          </NavLink>
          <nav aria-label={t('nav.menu')} className="hidden flex-1 gap-1 lg:flex">
            {items.map((item) => (
              <NavLink key={item.to} to={item.to} end={item.end} className={linkClass}>
                {item.label}
              </NavLink>
            ))}
          </nav>
          <div className="ms-auto flex items-center gap-3">
            {/* Cloche des notifications, pour tous les rôles et sur téléphone (EF-NOT-01). */}
            <NotificationBell />
            <div className="hidden text-end text-sm leading-tight sm:block">
              <div className="font-medium text-slate-900">{user.name}</div>
              <div className="text-slate-500">{t(`roles.${user.role}`)}</div>
            </div>
            <button
              type="button"
              className="min-h-11 rounded-lg px-3 text-sm font-medium text-slate-700 hover:bg-slate-100"
              onClick={() => logout.mutate(undefined, { onSettled: () => navigate(LOGIN_PATH, { replace: true }) })}
              disabled={logout.isPending}
            >
              {t('auth.logout')}
            </button>
          </div>
        </div>
      </header>

      {!hasTabBar(user.role) && (
        <nav aria-label={t('nav.menu')} className="flex gap-1 overflow-x-auto border-b border-slate-200 bg-white px-2 py-1 lg:hidden">
          {items.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={(state) => `${linkClass(state)} shrink-0`}>
              {item.label}
            </NavLink>
          ))}
        </nav>
      )}

      <main className={`mx-auto w-full max-w-6xl flex-1 px-4 py-6 ${hasTabBar(user.role) ? 'pb-24 lg:pb-6' : ''}`}>
        <Outlet />
      </main>

      {hasTabBar(user.role) && (
        <nav
          aria-label={t('nav.menu')}
          className="fixed inset-x-0 bottom-0 z-40 flex border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden"
        >
          {items.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={tabClass}>
              {item.label}
            </NavLink>
          ))}
        </nav>
      )}
    </div>
  )
}
