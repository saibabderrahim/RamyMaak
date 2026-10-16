import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * Détail d'une commande (EF-CLT-04) : frise de statut, lignes, modifier, annuler, Recommander (EF-CLT-05).
 * Contrat : docs/lot-5-contrat-api.md.
 */
export default function OrderDetailPage() {
  const { t } = useTranslation()

  return <PageHeader title={t('nav.orders')} />
}
