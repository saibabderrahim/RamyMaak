import { useTranslation } from 'react-i18next'
import { Alert } from '../ui'

/** Adresse inconnue, au niveau de l'application comme dans un module de rôle. */
export function NotFound() {
  const { t } = useTranslation()
  return <Alert tone="warning">{t('errors.notFound')}</Alert>
}
