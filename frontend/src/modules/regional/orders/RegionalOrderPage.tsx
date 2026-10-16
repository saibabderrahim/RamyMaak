import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * Détail d'une commande reçue (lecture au lot 5) : lignes et contexte de quotas ; décisions au lot 6.
 * Contrat : docs/lot-5-contrat-api.md.
 */
export default function RegionalOrderPage() {
  const { t } = useTranslation()

  return <PageHeader title={t('nav.orders')} />
}
