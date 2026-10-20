import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * File des commandes validées (EF-ADV-04, RG-A01, RG-A02) : `/adv/commandes`,
 * `/admin/commandes` en lecture — contrat : docs/lot-6-contrat-api.md §5 et §8.
 */
export default function OrdersQueuePage() {
  const { t } = useTranslation()
  return <PageHeader title={t('backoffice.orders.title')} />
}
