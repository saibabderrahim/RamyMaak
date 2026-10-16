import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * Commandes reçues par le régional (EF-REG-04, lecture au lot 5) : À valider, Validées, Rejetées.
 * Contrat : docs/lot-5-contrat-api.md.
 */
export default function RegionalOrdersPage() {
  const { t } = useTranslation()

  return <PageHeader title={t('nav.orders')} />
}
