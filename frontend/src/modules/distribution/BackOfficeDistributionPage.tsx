import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../shared/ui'

/**
 * Répartition d'une région en lecture seule pour l'ADV et l'Admin :
 * `/adv|admin/journees/:day/repartition/:regionId`.
 * Contrat : docs/lot-4-contrat-api.md §3 et §5.
 */
export default function BackOfficeDistributionPage() {
  const { t } = useTranslation()

  return <PageHeader title={t('distribution.title')} />
}
