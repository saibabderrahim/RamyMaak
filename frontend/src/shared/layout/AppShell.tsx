import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { NavLink, Outlet, useNavigate } from 'react-router'
import type { Role } from '../api/types'
import { LOGIN_PATH } from '../auth/guards'
import { homePath, useAuthenticatedUser, useLogout } from '../auth/session'
import { NotificationBell } from '../notifications/NotificationBell'
import { useAdvToPrepareCount } from '../orders'
import { OfflineBanner } from '../pwa/components'
import { Icon, type IconName } from '../ui/Icon'
import { useCartLineCount } from '../../modules/client/cart/store'

type NavGroup = 'operations' | 'referentials' | 'exchanges' | 'administration' | 'account'

/**
 * `badge` : pastille (nombre) ; `tabBar: false` : hors de la barre d'onglets du téléphone, lien dans l'en-tête ;
 * `group` : rubrique de la barre latérale du back-office.
 */
type NavItem = { to: string; label: string; icon: IconName; end?: boolean; badge?: number; tabBar?: boolean; group?: NavGroup }

function useNavItems(role: Role, permissions: readonly string[]): NavItem[] {
  const { t } = useTranslation()
  const cartLines = useCartLineCount()
  // Commandes à préparer de l'ADV (N-08) : lues seulement avec le droit de consulter les commandes.
  const toPrepare = useAdvToPrepareCount(role === 'adv' && permissions.includes('order.view'))
  const profile: NavItem = { to: '/profil', label: t('nav.profile'), icon: 'user', group: 'account' }

  switch (role) {
    case 'admin':
      return [
        { to: '/admin/journees', label: t('nav.days'), icon: 'calendar', group: 'operations' },
        { to: '/admin/commandes', label: t('nav.orders'), icon: 'list', group: 'operations' },
        { to: '/admin/regions', label: t('nav.regions'), icon: 'map', group: 'referentials' },
        { to: '/admin/clients', label: t('nav.clients'), icon: 'users', group: 'referentials' },
        { to: '/admin/produits', label: t('nav.products'), icon: 'box', group: 'referentials' },
        { to: '/admin/motifs', label: t('nav.reasons'), icon: 'doc', group: 'referentials' },
        { to: '/admin/imports', label: t('nav.imports'), icon: 'upload', group: 'exchanges' },
        { to: '/admin/comptes', label: t('nav.users'), icon: 'user', group: 'administration' },
        { to: '/admin/journal', label: t('nav.audit'), icon: 'shield', group: 'administration' },
        { to: '/admin/parametres', label: t('nav.settings'), icon: 'settings', group: 'administration' },
        profile,
      ]
    case 'adv':
      return [
        { to: '/adv', label: t('nav.home'), icon: 'home', end: true, group: 'operations' },
        // Commandes validées à préparer (EF-ADV-04), avec la pastille du nombre à préparer (N-08).
        { to: '/adv/commandes', label: t('nav.orders'), icon: 'list', badge: toPrepare, group: 'operations' },
        { to: '/adv/journees', label: t('nav.days'), icon: 'calendar', group: 'operations' },
        { to: '/adv/regions', label: t('nav.regions'), icon: 'map', group: 'referentials' },
        { to: '/adv/clients', label: t('nav.clients'), icon: 'users', group: 'referentials' },
        { to: '/adv/produits', label: t('nav.products'), icon: 'box', group: 'referentials' },
        // Imports : quotas (quota.edit) et référentiels selon les droits reçus en option.
        ...(permissions.includes('quota.edit') || permissions.includes('product.manage') || permissions.includes('client.manage')
          ? [{ to: '/adv/imports', label: t('nav.imports'), icon: 'upload' as const, group: 'exchanges' as const }]
          : []),
        profile,
      ]
    case 'regional':
      return [
        // « Ma journée » (EF-REG-01) : page d'accueil du régional depuis le lot 6.
        { to: '/regional', label: t('nav.myDay'), icon: 'sun', end: true },
        { to: '/regional/quotas', label: t('nav.quotas'), icon: 'gauge' },
        { to: '/regional/repartition', label: t('nav.distribution'), icon: 'split' },
        { to: '/regional/commandes', label: t('nav.orders'), icon: 'list' },
        { to: '/regional/clients', label: t('nav.myClients'), icon: 'users' },
        // Cinq onglets au plus sur un téléphone de 320 px : le profil passe dans l'en-tête.
        { ...profile, tabBar: false },
      ]
    case 'client':
      return [
        { to: '/client', label: t('nav.home'), icon: 'home', end: true },
        { to: '/client/catalogue', label: t('nav.order'), icon: 'grid' },
        { to: '/client/panier', label: t('nav.cart'), icon: 'bag', badge: cartLines },
        { to: '/client/commandes', label: t('nav.orders'), icon: 'list' },
        profile,
      ]
  }
}

/** Pastille d'un nombre (99+ au-delà), annoncée dans le nom accessible de l'entrée. */
function Count({ value, tone }: { value: number; tone: 'red' | 'white' }) {
  return (
    <span
      aria-hidden="true"
      className={`inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs leading-none font-semibold tabular-nums ${
        tone === 'red' ? 'bg-brand-600 text-white' : 'bg-white text-slate-900'
      }`}
    >
      {value > 99 ? '99+' : value}
    </span>
  )
}

/** Libellé d'une entrée, avec sa pastille (nombre annoncé dans le nom accessible). */
function NavLabel({ item, tone = 'red' }: { item: NavItem; tone?: 'red' | 'white' }) {
  const { t } = useTranslation()
  if (!item.badge) return <>{item.label}</>
  return (
    <span className="flex items-center gap-1.5">
      <span className="sr-only">{t('nav.withCount', { label: item.label, count: item.badge })}</span>
      <span aria-hidden="true">{item.label}</span>
      <Count value={item.badge} tone={tone} />
    </span>
  )
}

/** Barre d'onglets en bas de l'écran pour les rôles de terrain (téléphone). */
function hasTabBar(role: Role): boolean {
  return role === 'client' || role === 'regional'
}

function useLogoutAndLeave() {
  const logout = useLogout()
  const navigate = useNavigate()
  return {
    pending: logout.isPending,
    leave: () => logout.mutate(undefined, { onSettled: () => navigate(LOGIN_PATH, { replace: true }) }),
  }
}

/**
 * Coquille commune à tous les rôles (charte Ramy, lot 7). Rôles de terrain
 * (client, régional) : barre rouge au logo blanc, onglets fixés en bas de
 * l'écran sur téléphone. Back-office (ADV, Admin) : barre latérale groupée
 * par rubrique sur grand écran, menu repliable sur tablette et téléphone.
 */
export function AppShell() {
  const user = useAuthenticatedUser()
  return hasTabBar(user.role) ? <FieldShell /> : <BackOfficeShell />
}

function FieldShell() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const { leave, pending } = useLogoutAndLeave()
  const items = useNavItems(user.role, user.permissions)
  // Entrées hors barre d'onglets (téléphone) : dans l'en-tête.
  const headerItems = items.filter((item) => item.tabBar === false)

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    `relative flex min-h-14 shrink-0 items-center whitespace-nowrap px-3 text-sm focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-white ${
      isActive ? 'text-white shadow-[inset_0_-3px_0_white]' : 'text-white/85 hover:text-white'
    }`

  const tabClass = ({ isActive }: { isActive: boolean }) =>
    `relative flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 text-xs focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus ${
      isActive
        ? 'font-semibold text-brand-700 before:absolute before:inset-x-1/4 before:top-0 before:h-[3px] before:rounded-b before:bg-brand-600'
        : 'text-slate-600 hover:text-slate-900'
    }`

  return (
    <div className="flex min-h-dvh flex-col">
      <OfflineBanner />
      <header className="sticky top-0 z-40 bg-brand-600 text-white">
        <div className="mx-auto flex min-h-14 max-w-6xl items-center gap-2 px-4 sm:gap-4">
          <NavLink
            to={homePath(user.role)}
            className="flex min-h-11 shrink-0 items-center gap-3 rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          >
            <img src="/brand/ramy-logo-blanc.png" alt="" width={64} height={33} className="h-8 w-auto" />
            {/* Entrée d'en-tête en plus (« Profil » du régional) : sur téléphone, le logo seul tient dans 360 px. */}
            {/* Filet séparé du nom : « sm:not-sr-only » remet les marges du nom à zéro. */}
            <span aria-hidden="true" className={`h-5 w-px bg-white/60 ${headerItems.length > 0 ? 'hidden sm:block' : ''}`} />
            <span className={`text-sm ${headerItems.length > 0 ? 'sr-only sm:not-sr-only' : ''}`}>{t('app.name')}</span>
          </NavLink>
          {/* Grand écran : le menu passe dans la barre, les onglets du bas disparaissent. */}
          <nav aria-label={t('nav.menu')} className="hidden min-w-0 flex-1 lg:flex">
            {items.map((item) => (
              <NavLink key={item.to} to={item.to} end={item.end} className={linkClass}>
                <NavLabel item={item} tone="white" />
              </NavLink>
            ))}
          </nav>
          {headerItems.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={(state) => `${linkClass(state)} lg:hidden`}>
              {item.label}
            </NavLink>
          ))}
          <div className="ms-auto flex shrink-0 items-center gap-2 sm:gap-3">
            {/* Cloche des notifications, pour tous les rôles et sur téléphone (EF-NOT-01). */}
            <NotificationBell onBar />
            <div className="hidden text-end text-sm leading-tight sm:block">
              <div className="font-semibold">{user.name}</div>
              <div className="text-xs text-white/85">{t(`roles.${user.role}`)}</div>
            </div>
            {/* Téléphone : pictogramme seul (le libellé reste annoncé) ; texte à partir de la tablette. */}
            <button
              type="button"
              className="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-md border border-white/80 px-2 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-not-allowed disabled:opacity-55 sm:px-3"
              onClick={leave}
              disabled={pending}
            >
              <Icon name="logout" className="size-5 sm:hidden" />
              <span className="sr-only sm:not-sr-only">{t('auth.logout')}</span>
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-5 pb-24 lg:py-6 lg:pb-8">
        <Outlet />
      </main>

      <nav
        aria-label={t('nav.menu')}
        className="fixed inset-x-0 bottom-0 z-40 flex border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden"
      >
        {items
          .filter((item) => item.tabBar !== false)
          .map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={tabClass}>
              <span className="relative">
                <Icon name={item.icon} className="size-6" />
                {item.badge ? (
                  <span className="absolute -end-3 -top-1.5">
                    <Count value={item.badge} tone="red" />
                  </span>
                ) : null}
              </span>
              <span>{item.badge ? <span className="sr-only">{t('nav.withCount', { label: item.label, count: item.badge })}</span> : null}<span aria-hidden={item.badge ? true : undefined}>{item.label}</span></span>
            </NavLink>
          ))}
      </nav>
    </div>
  )
}

const GROUP_ORDER: NavGroup[] = ['operations', 'referentials', 'exchanges', 'administration']

function SideNav({ items, onNavigate }: { items: NavItem[]; onNavigate?: () => void }) {
  const { t } = useTranslation()
  const linkClass = ({ isActive }: { isActive: boolean }) =>
    `flex min-h-10 items-center gap-3 rounded-md px-3 text-sm focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus ${
      isActive ? 'bg-brand-50 font-semibold text-brand-800 shadow-[inset_3px_0_0_var(--color-brand-600)]' : 'text-slate-800 hover:bg-slate-100'
    }`
  return (
    <nav aria-label={t('nav.menu')} className="flex flex-col gap-5">
      {GROUP_ORDER.map((group) => {
        const groupItems = items.filter((item) => item.group === group)
        if (groupItems.length === 0) return null
        return (
          <div key={group} className="flex flex-col gap-0.5">
            <p className="px-3 pb-1 text-xs text-slate-600">{t(`nav.groups.${group}`)}</p>
            {groupItems.map((item) => (
              <NavLink key={item.to} to={item.to} end={item.end} className={linkClass} onClick={onNavigate}>
                <Icon name={item.icon} />
                <span className="flex-1">
                  <NavLabel item={item} />
                </span>
              </NavLink>
            ))}
          </div>
        )
      })}
      {items
        .filter((item) => item.group === 'account')
        .map((item) => (
          <div key={item.to} className="border-t border-slate-200 pt-3">
            <NavLink to={item.to} end={item.end} className={linkClass} onClick={onNavigate}>
              <Icon name={item.icon} />
              <span>{item.label}</span>
            </NavLink>
          </div>
        ))}
    </nav>
  )
}

function BackOfficeShell() {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const { leave, pending } = useLogoutAndLeave()
  const items = useNavItems(user.role, user.permissions)
  // Menu repliable (tablette, téléphone) : refermé dès qu'une entrée est choisie.
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <div className="flex min-h-dvh flex-col">
      <OfflineBanner />
      <div className="flex flex-1">
        <aside className="hidden w-60 shrink-0 border-e border-slate-200 bg-white lg:block">
          <div className="sticky top-0 flex max-h-dvh min-h-dvh flex-col gap-6 overflow-y-auto px-3 py-4">
            <NavLink
              to={homePath(user.role)}
              className="flex min-h-11 items-center gap-3 rounded px-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
              <img src="/brand/ramy-logo.png" alt="" width={72} height={37} className="h-9 w-auto" />
              <span className="text-sm text-slate-600">
                <span className="sr-only">{t('app.name')} </span>
                {t('nav.backOffice')}
              </span>
            </NavLink>
            <SideNav items={items} />
          </div>
        </aside>

        <div className="flex min-w-0 flex-1 flex-col">
          <header className="sticky top-0 z-40 border-b border-slate-200 bg-white">
            <div className="flex min-h-14 items-center gap-2 px-4 sm:gap-4 lg:px-6">
              <button
                type="button"
                className="inline-flex size-11 items-center justify-center rounded-md text-slate-800 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus lg:hidden"
                aria-expanded={menuOpen}
                aria-controls="back-office-menu"
                aria-label={menuOpen ? t('nav.closeMenu') : t('nav.openMenu')}
                onClick={() => setMenuOpen((open) => !open)}
              >
                <Icon name={menuOpen ? 'x' : 'menu'} className="size-6" />
              </button>
              <NavLink to={homePath(user.role)} className="flex min-h-11 items-center rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus lg:hidden">
                <img src="/brand/ramy-logo.png" alt={t('app.name')} width={56} height={29} className="h-7 w-auto" />
              </NavLink>
              <div className="ms-auto flex shrink-0 items-center gap-2 sm:gap-3">
                <NotificationBell />
                <div className="hidden text-end text-sm leading-tight sm:block">
                  <div className="font-semibold text-slate-900">{user.name}</div>
                  <div className="text-xs text-slate-600">{t(`roles.${user.role}`)}</div>
                </div>
                <button
                  type="button"
                  className="min-h-10 rounded-md border border-slate-300 px-3 text-sm font-semibold text-slate-800 hover:border-field hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-55"
                  onClick={leave}
                  disabled={pending}
                >
                  {t('auth.logout')}
                </button>
              </div>
            </div>
            {menuOpen && (
              <div id="back-office-menu" className="max-h-[calc(100dvh-3.5rem)] overflow-y-auto border-t border-slate-200 bg-white px-3 py-4 lg:hidden">
                <SideNav items={items} onNavigate={() => setMenuOpen(false)} />
              </div>
            )}
          </header>

          <main className="w-full max-w-7xl flex-1 px-4 py-6 lg:px-6">
            <Outlet />
          </main>
        </div>
      </div>
    </div>
  )
}
