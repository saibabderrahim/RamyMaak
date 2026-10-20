import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * Suivi de la journée (EF-ADV-03) : `/adv/journees/:day/suivi`,
 * `/admin/journees/:day/suivi` en lecture ; bon de préparation et export —
 * contrat : docs/lot-6-contrat-api.md §6 et §8.
 */
export default function DayMonitoringPage() {
  const { t } = useTranslation()
  return <PageHeader title={t('backoffice.monitoring.title')} />
}
