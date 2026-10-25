import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useAuthenticatedUser } from '../../shared/auth/session'
import { businessToday } from '../../shared/format'
import { useAdvToPrepareCount } from '../../shared/orders'
import { Card, PageHeader } from '../../shared/ui'

type RoleWithHome = 'client' | 'adv'

type Shortcut = { to: string; label: string; primary?: boolean }

// Zones tactiles d'au moins 44 px (§7.3), mêmes styles que les boutons.
const LINK_CLASSES =
  'inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-semibold transition-colors ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'
const PRIMARY_LINK = 'bg-brand-600 text-white hover:bg-brand-700'
const SECONDARY_LINK = 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50'

function Shortcuts({ title, links, children }: { title: string; links: Shortcut[]; children?: ReactNode }) {
  return (
    <Card title={title} className="mb-6 border-s-4 border-s-brand-600">
      <div className="flex flex-wrap gap-3">
        {links.map((link) => (
          <Link key={link.to} to={link.to} className={`${LINK_CLASSES} ${link.primary ? PRIMARY_LINK : SECONDARY_LINK}`}>
            {link.label}
          </Link>
        ))}
      </div>
      {children}
    </Card>
  )
}

/**
 * Accueil de l'espace d'un rôle. ADV : la file des commandes à préparer et le
 * suivi de la journée (lot 6), puis les journées et les quotas (lot 3). Client :
 * ce qui arrive dans les lots suivants. Le régional a « Ma journée »
 * (modules/regional/home), plus d'accueil commun. Chargé aussi par la PWA du
 * client : rien du back-office ici, seulement des adresses et des libellés.
 */
export function RoleHome({ role }: { role: RoleWithHome }) {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const canOrders = user.permissions.includes('order.view')
  const toPrepare = useAdvToPrepareCount(role === 'adv' && canOrders)

  const advOrders: Shortcut[] = [
    ...(canOrders ? [{ to: '/adv/commandes', label: t('backoffice.orders.home.queue'), primary: true }] : []),
    ...(user.permissions.includes('dashboard.view')
      ? [{ to: `/adv/journees/${businessToday()}/suivi`, label: t('backoffice.orders.home.monitoring'), primary: !canOrders }]
      : []),
  ]

  return (
    <>
      <PageHeader title={t('home.welcome', { name: user.name })} />
      {role === 'adv' && (
        <>
          {advOrders.length > 0 && (
            <Shortcuts title={t('backoffice.orders.home.title')} links={advOrders}>
              {toPrepare > 0 && <p className="mt-3 text-sm font-medium text-slate-800">{t('backoffice.orders.home.toPrepare', { count: toPrepare })}</p>}
            </Shortcuts>
          )}
          <Shortcuts
            title={t('home.adv.shortcuts')}
            links={[
              { to: `/adv/journees/${businessToday()}/quotas`, label: t('home.adv.todayQuotas'), primary: advOrders.length === 0 },
              { to: '/adv/journees', label: t('home.adv.days') },
            ]}
          />
        </>
      )}
      {role === 'client' && (
        <Card title={t('home.upcoming')}>
          <p className="mb-3 text-sm text-slate-600">{t('home.roleSpace', { role: t(`roles.${role}`) })}</p>
          <ul className="list-disc space-y-1 ps-5 text-sm text-slate-800">
            {t('home.client.items', { returnObjects: true }).map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
        </Card>
      )}
    </>
  )
}
