import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useAuthenticatedUser } from '../../shared/auth/session'
import { businessToday } from '../../shared/format'
import { Card, PageHeader } from '../../shared/ui'

type RoleWithHome = 'client' | 'regional' | 'adv'

type Shortcut = { to: string; label: string; primary?: boolean }

// Zones tactiles d'au moins 44 px (§7.3), mêmes styles que les boutons.
const LINK_CLASSES =
  'inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-semibold transition-colors ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600'
const PRIMARY_LINK = 'bg-brand-600 text-white hover:bg-brand-700'
const SECONDARY_LINK = 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50'

/**
 * Accueil de l'espace d'un rôle : accès aux écrans livrés (lot 3 :
 * journées et matrice des quotas de l'ADV, « Quotas du jour » du régional),
 * puis ce qui arrive dans les lots suivants. Chargé aussi par la PWA du
 * régional : rien du back-office ici.
 */
export function RoleHome({ role }: { role: RoleWithHome }) {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const items = t(`home.${role}.items`, { returnObjects: true })

  const shortcuts: { title: string; links: Shortcut[] } | null =
    role === 'adv'
      ? {
          title: t('home.adv.shortcuts'),
          links: [
            { to: `/adv/journees/${businessToday()}/quotas`, label: t('home.adv.todayQuotas'), primary: true },
            { to: '/adv/journees', label: t('home.adv.days') },
          ],
        }
      : role === 'regional'
        ? { title: t('home.regional.shortcuts'), links: [{ to: '/regional/quotas', label: t('home.regional.todayQuotas'), primary: true }] }
        : null

  return (
    <>
      <PageHeader title={t('home.welcome', { name: user.name })} />
      {shortcuts && (
        <Card title={shortcuts.title} className="mb-6 border-s-4 border-s-brand-600">
          <div className="flex flex-wrap gap-3">
            {shortcuts.links.map((link) => (
              <Link key={link.to} to={link.to} className={`${LINK_CLASSES} ${link.primary ? PRIMARY_LINK : SECONDARY_LINK}`}>
                {link.label}
              </Link>
            ))}
          </div>
        </Card>
      )}
      <Card title={t('home.upcoming')}>
        <p className="mb-3 text-sm text-slate-600">{t('home.roleSpace', { role: t(`roles.${role}`) })}</p>
        <ul className="list-disc space-y-1 ps-5 text-sm text-slate-800">
          {items.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
      </Card>
    </>
  )
}
