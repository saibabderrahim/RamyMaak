import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import type { ListResponse, ReasonOption, ReasonType } from '../api/types'

/**
 * Motifs actifs proposés à la saisie (EF-ADM-05) — contrat :
 * docs/lot-6-contrat-api.md §4. Lus par le régional (ajustement, rejet),
 * l'ADV (écart d'expédition, annulation) et l'Admin ; refusés au client.
 * Rien du back-office ici : la PWA du régional s'en sert.
 */

/** Racine des motifs en cache : à invalider après une modification par l'Admin. */
export const REASONS_KEY = ['reasons'] as const

/** Motifs d'un ou plusieurs types (liste triée : une seule entrée en cache pour le même ensemble). */
export const reasonKey = (types: readonly ReasonType[]) => [...REASONS_KEY, [...new Set(types)].sort().join(',')] as const

/** Les motifs changent rarement (paramétrage de l'Admin) : relus au plus toutes les 10 minutes. */
export const REASONS_STALE_TIME = 10 * 60_000

/**
 * Motifs actifs, par type, puis ordre, puis libellé (ordre du serveur) :
 * `useReasonCodes('ADJUSTMENT')` ou `useReasonCodes(['SHIPPING_GAP', 'CANCELLATION'])`.
 */
export function useReasonCodes(type: ReasonType | readonly ReasonType[], options: { enabled?: boolean } = {}) {
  const key = reasonKey(typeof type === 'string' ? [type] : type)
  return useQuery({
    queryKey: key,
    queryFn: ({ signal }) => api.get<ListResponse<ReasonOption>>('/reason-codes', { type: key[1] }, signal),
    select: (response) => response.data,
    staleTime: REASONS_STALE_TIME,
    enabled: options.enabled,
  })
}

/** Motifs d'un type dans une liste chargée pour plusieurs types. */
export function reasonsOfType(reasons: readonly ReasonOption[] | undefined, type: ReasonType): ReasonOption[] {
  return (reasons ?? []).filter((reason) => reason.type === type)
}

/** Motif choisi (code) parmi les motifs actifs d'un type ; undefined s'il est inconnu ou désactivé depuis. */
export function findReason(reasons: readonly ReasonOption[] | undefined, type: ReasonType, code: string | null | undefined): ReasonOption | undefined {
  if (!code) return undefined
  return reasons?.find((reason) => reason.type === type && reason.code === code)
}

/** Commentaire obligatoire avec ce motif (AUTRE) ; faux pour un code inconnu (le serveur reste juge). */
export function requiresComment(reasons: readonly ReasonOption[] | undefined, type: ReasonType, code: string | null | undefined): boolean {
  return findReason(reasons, type, code)?.requires_comment ?? false
}
