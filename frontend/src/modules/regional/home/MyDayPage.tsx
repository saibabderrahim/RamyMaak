import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * « Ma journée » du régional (EF-REG-01), page d'accueil `/regional` —
 * contrat : docs/lot-6-contrat-api.md §3 (`GET /regional/dashboard`) et §8.
 * Chargée par la PWA terrain : rien du back-office ici.
 */
export default function MyDayPage() {
  const { t } = useTranslation()
  return <PageHeader title={t('orders.myDay.title')} />
}
