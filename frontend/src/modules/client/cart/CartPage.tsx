import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * Panier (EF-CLT-03) : lignes, totaux indicatifs, commentaire, envoi idempotent ou modification.
 * Contrat : docs/lot-5-contrat-api.md.
 */
export default function CartPage() {
  const { t } = useTranslation()

  return <PageHeader title={t('nav.cart')} />
}
