import type { TFunction } from 'i18next'
import { ApiError, NetworkError } from '../../../../shared/api/client'
import { BLOCKING_CODES } from './model'

/** Codes qui ont leur propre bandeau ou message ailleurs que dans l'alerte d'erreur. */
export const ELSEWHERE_CODES: readonly string[] = ['INVALID_TRANSITION', ...BLOCKING_CODES]

/**
 * Message d'un refus de la décision (contrat du lot 6, §3), sinon le `detail`
 * du serveur. Réseau : la décision n'est pas partie, la saisie est conservée.
 */
export function decisionErrorText(t: TFunction, error: unknown): string {
  if (error instanceof NetworkError) return t('orders.decision.errors.network')
  if (!(error instanceof ApiError)) return t('errors.unexpected')
  switch (error.code) {
    case 'VERSION_CONFLICT':
      return t('orders.decision.errors.VERSION_CONFLICT')
    case 'INVALID_TRANSITION':
      return t('orders.decision.outcome.processed')
    case 'REGION_QUOTA_EXCEEDED':
    case 'CLIENT_LIMIT_EXCEEDED':
      return t('orders.decision.errors.EXCEEDED')
    case 'VALIDATION_FAILED':
      return t('orders.decision.errors.VALIDATION_FAILED')
    default:
      return error.problem.detail ?? t('errors.unexpected')
  }
}

/** Messages de validation d'un refus 422 (par champ), sinon le `detail` du serveur ; null : rien à citer. */
export function fieldMessages(error: unknown): string[] {
  if (!(error instanceof ApiError) || error.code !== 'VALIDATION_FAILED') return []
  const messages = Object.values(error.fieldErrors).flat()
  return messages.length > 0 ? messages : error.problem.detail ? [error.problem.detail] : []
}
