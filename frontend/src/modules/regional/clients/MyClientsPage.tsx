import { useTranslation } from 'react-i18next'
import { Alert } from '../../../shared/ui'

/** Écran du lot 2 (docs/lot-2-contrat-api.md §7), à réaliser. */
export default function MyClientsPage() {
  const { t } = useTranslation()
  return <Alert>{t('field.placeholder')}</Alert>
}
