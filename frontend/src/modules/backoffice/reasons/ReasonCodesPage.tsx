import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * Motifs (EF-ADM-05) : `/admin/motifs`, un onglet par type, création et
 * modification, activation — contrat : docs/lot-6-contrat-api.md §4 et §8.
 */
export default function ReasonCodesPage() {
  const { t } = useTranslation()
  return <PageHeader title={t('backoffice.reasons.title')} />
}
