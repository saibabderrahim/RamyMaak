import { useTranslation } from 'react-i18next'
import { ApiError, NetworkError } from '../api/client'

/** Message lisible pour une erreur d'API ou réseau. */
export function useErrorMessage() {
  const { t } = useTranslation()
  return (error: unknown): string => {
    if (error instanceof NetworkError) return t('errors.network')
    if (error instanceof ApiError) return error.problem.detail ?? t('errors.unexpected')
    return t('errors.unexpected')
  }
}
