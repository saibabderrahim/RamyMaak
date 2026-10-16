import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * « Mes commandes » (EF-CLT-04) : liste, toutes journées, la plus récente d'abord.
 * Contrat : docs/lot-5-contrat-api.md.
 */
export default function OrdersPage() {
  const { t } = useTranslation()

  return <PageHeader title={t('nav.orders')} />
}
