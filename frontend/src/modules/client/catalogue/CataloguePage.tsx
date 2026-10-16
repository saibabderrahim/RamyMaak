import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../shared/ui'

/**
 * Catalogue du jour (EF-CLT-02) et fiche produit, sélecteur de quantité, panier latéral sur PC.
 * Contrat : docs/lot-5-contrat-api.md.
 */
export default function CataloguePage() {
  const { t } = useTranslation()

  return <PageHeader title={t('nav.order')} />
}
