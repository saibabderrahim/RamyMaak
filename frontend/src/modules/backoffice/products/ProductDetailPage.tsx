import { useTranslation } from 'react-i18next'
import { Alert } from '../../../shared/ui'

/** Écran du lot 2 (docs/lot-2-contrat-api.md §7), à réaliser. */
export default function ProductDetailPage() {
  const { t } = useTranslation()
  return <Alert>{t('backoffice.placeholder')}</Alert>
}
