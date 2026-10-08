import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { ApiError } from './api/client'

/**
 * Reporte les erreurs de validation du serveur (`VALIDATION_FAILED`) sur les
 * champs du formulaire. Retourne `true` si au moins un champ connu a été
 * marqué, sinon l'erreur doit être affichée globalement.
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
): boolean {
  if (!(error instanceof ApiError) || error.code !== 'VALIDATION_FAILED') return false

  let applied = false
  for (const [field, messages] of Object.entries(error.fieldErrors)) {
    const target = fields.find((name) => name === field)
    if (target && messages[0]) {
      setError(target, { type: 'server', message: messages[0] })
      applied = true
    }
  }
  return applied
}
