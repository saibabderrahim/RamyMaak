import type { ReasonRef } from '../api/types'

/**
 * Code de motif rendu lisible (« QUOTA_INSUFFISANT » → « Quota insuffisant ») :
 * repli quand l'API ne donne pas de libellé (données antérieures au lot 6).
 */
export function reasonLabel(code: string): string {
  const text = code.replace(/[_-]+/g, ' ').trim().toLowerCase()
  return text === '' ? code : text.charAt(0).toUpperCase() + text.slice(1)
}

/**
 * Texte d'un motif cité par une commande : son libellé (`{code, label}`, motif
 * désactivé compris), sinon le code rendu lisible ; null sans motif.
 */
export function reasonText(reason: ReasonRef | null | undefined, code?: string | null): string | null {
  if (reason?.label) return reason.label
  const fallback = reason?.code ?? code
  return fallback ? reasonLabel(fallback) : null
}
