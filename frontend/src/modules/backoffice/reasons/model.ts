import { REASON_TYPES, type ReasonCode, type ReasonCodeInput, type ReasonCodeUpdate, type ReasonType } from '../../../shared/api/types'

// Règles de l'écran « Motifs » (EF-ADM-05, docs/lot-6-contrat-api.md §4, I-82, I-95).

/** Code d'un motif : majuscules, chiffres et « _ », 2 à 30 caractères, commençant par une lettre. */
export const CODE_PATTERN = /^[A-Z][A-Z0-9_]{1,29}$/

export const LABEL_MAX = 100

/** Colonne SMALLINT UNSIGNED. */
export const SORT_ORDER_MAX = 65_535

/** Pas des motifs par défaut (10, 20, 30…) : de la place pour en insérer un entre deux. */
const SORT_ORDER_STEP = 10

/** Onglets, dans l'ordre des types : l'adresse porte un nom français (`?onglet=ecart`). */
export const TAB_OF_TYPE = {
  REJECTION: 'rejet',
  ADJUSTMENT: 'ajustement',
  SHIPPING_GAP: 'ecart',
  CANCELLATION: 'annulation',
} as const satisfies Record<ReasonType, string>

export type ReasonTab = (typeof TAB_OF_TYPE)[ReasonType]

export const TABS: readonly ReasonTab[] = REASON_TYPES.map((type) => TAB_OF_TYPE[type])

export const typeOfTab = (tab: ReasonTab): ReasonType => REASON_TYPES.find((type) => TAB_OF_TYPE[type] === tab) ?? 'REJECTION'

/** Un écart d'expédition n'a pas de commentaire (aucune colonne ne le garde, I-95) : le serveur refuse `requires_comment`. */
export const canRequireComment = (type: ReasonType): boolean => type !== 'SHIPPING_GAP'

/** Ordre proposé à la création : à la suite des motifs du type (dizaine suivante), 10 pour le premier. */
export function nextSortOrder(reasons: readonly Pick<ReasonCode, 'sort_order'>[]): number {
  const highest = reasons.reduce((max, reason) => Math.max(max, reason.sort_order), 0)
  const next = reasons.length === 0 ? SORT_ORDER_STEP : (Math.floor(highest / SORT_ORDER_STEP) + 1) * SORT_ORDER_STEP
  return Math.min(next, SORT_ORDER_MAX)
}

/** Le motif est le seul actif de son type : le désactiver priverait l'écran qui le propose de tout choix. */
export function isLastActive(reasons: readonly ReasonCode[], reason: ReasonCode): boolean {
  return reason.is_active && !reasons.some((other) => other.id !== reason.id && other.is_active)
}

export type ReasonValues = { type: ReasonType; code: string; label: string; requires_comment: boolean; sort_order: number }

/** Corps de la création : `requires_comment` n'est pas envoyé pour un écart d'expédition. */
export function createBody(values: ReasonValues): ReasonCodeInput {
  return {
    type: values.type,
    code: values.code,
    label: values.label,
    ...(canRequireComment(values.type) ? { requires_comment: values.requires_comment } : {}),
    sort_order: values.sort_order,
  }
}

/** Corps de la modification : seulement ce qui change (libellé, commentaire exigé, ordre) ; vide si rien ne change. */
export function changesOf(reason: ReasonCode, values: Pick<ReasonValues, 'label' | 'requires_comment' | 'sort_order'>): ReasonCodeUpdate {
  return {
    ...(values.label !== reason.label ? { label: values.label } : {}),
    ...(canRequireComment(reason.type) && values.requires_comment !== reason.requires_comment ? { requires_comment: values.requires_comment } : {}),
    ...(values.sort_order !== reason.sort_order ? { sort_order: values.sort_order } : {}),
  }
}
