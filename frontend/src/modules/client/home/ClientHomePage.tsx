import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * Accueil du client (EF-CLT-01) : état de la journée, commande du jour, « Commander ».
 * Contrat : docs/lot-5-contrat-api.md.
 */
export default function ClientHomePage() {
  const { t } = useTranslation()

  return <PageHeader title={t('nav.home')} />
}
