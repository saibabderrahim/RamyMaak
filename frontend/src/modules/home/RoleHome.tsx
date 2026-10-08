import { useTranslation } from 'react-i18next'
import { useAuthenticatedUser } from '../../shared/auth/session'
import { Card, PageHeader } from '../../shared/ui'

type RoleWithHome = 'client' | 'regional' | 'adv'

/**
 * Accueil de l'espace d'un rôle. Les écrans métier (catalogue, grille,
 * matrice des quotas…) viennent s'y ajouter à partir du lot 3.
 */
export function RoleHome({ role }: { role: RoleWithHome }) {
  const { t } = useTranslation()
  const user = useAuthenticatedUser()
  const items = t(`home.${role}.items`, { returnObjects: true })

  return (
    <>
      <PageHeader title={t('home.welcome', { name: user.name })} />
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
