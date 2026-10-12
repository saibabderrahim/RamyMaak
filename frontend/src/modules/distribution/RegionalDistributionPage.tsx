import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../shared/ui'

/**
 * Grille de répartition du régional (EF-REG-02 sur PC et tablette, EF-REG-03
 * sur téléphone) : `/regional/repartition?day=AAAA-MM-JJ&region=ID`.
 * Contrat : docs/lot-4-contrat-api.md §3 et §5.
 */
export default function RegionalDistributionPage() {
  const { t } = useTranslation()

  return <PageHeader title={t('distribution.title')} />
}
