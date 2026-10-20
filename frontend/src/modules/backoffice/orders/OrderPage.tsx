import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * Détail d'une commande pour l'ADV (expédition, annulation, bon de commande)
 * et l'Admin en lecture : `/adv/commandes/:id`, `/admin/commandes/:id` —
 * contrat : docs/lot-6-contrat-api.md §5 et §8.
 */
export default function OrderPage() {
  const { t } = useTranslation()
  return <PageHeader title={t('backoffice.orders.title')} />
}
