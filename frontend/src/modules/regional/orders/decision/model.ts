import { ApiError } from '../../../../shared/api/client'
import type {
  DecisionBlocker,
  DecisionViolation,
  ReasonOption,
  RegionalOrder,
  RegionalOrderLine,
  RegionalOrderSummary,
  ValidateOrderBody,
} from '../../../../shared/api/types'
import { requiresComment } from '../../../../shared/reasons/api'

/**
 * Décision du régional sur une commande reçue (EF-REG-05, RG-V01 à RG-V08) —
 * contrat : docs/lot-6-contrat-api.md §3 et §8. Brouillon de décision, maximums,
 * « reste après validation », corps de la requête et lecture des refus, sans React.
 */

/** Commentaire d'une ligne ajustée (I-73) et du rejet (§3) : plafonds du serveur. */
export const ADJUSTMENT_COMMENT_MAX = 200
export const REJECTION_COMMENT_MAX = 500

export type DraftLine = {
  /** Quantité à valider, de 0 à la quantité demandée. */
  quantity: number
  /** Code du motif d'ajustement ('' : aucun), demandé seulement sur une ligne réduite. */
  reason: string
  comment: string
}

/**
 * Brouillon local d'une commande, pour la version lue à son ouverture
 * (par produit). Une relecture en direct de la commande ne le change jamais :
 * si la version de la commande diffère de la sienne, le client l'a modifiée
 * et il faut repartir de la commande à jour.
 */
export type Draft = {
  version: number
  /** Le régional a saisi quelque chose : un brouillon resté intact ne vaut rien pour une autre version de la commande. */
  touched: boolean
  lines: Readonly<Record<number, DraftLine>>
}

type DraftOrder = Pick<RegionalOrder, 'version' | 'lines'>

/** Départ : tout est validé comme demandé, le régional ajuste ensuite. */
export function initialDraft(order: DraftOrder): Draft {
  return {
    version: order.version,
    touched: false,
    lines: Object.fromEntries(order.lines.map((line) => [line.product_id, { quantity: line.requested_qty, reason: '', comment: '' }])),
  }
}

/** La commande a changé de version depuis le brouillon : le client l'a modifiée. */
export function isStale(draft: Draft, order: Pick<RegionalOrder, 'version'>): boolean {
  return draft.version !== order.version
}

/** Maximum validable d'une ligne (RG-V02, RG-V03) : celui du serveur, jamais plus que demandé. */
export function maxOf(line: RegionalOrderLine): number {
  return Math.max(0, Math.min(line.requested_qty, line.context.max_validatable))
}

/** Quantité du brouillon pour une ligne, entre 0 et la quantité demandée. */
export function quantityOf(draft: Draft, line: RegionalOrderLine): number {
  const quantity = draft.lines[line.product_id]?.quantity ?? line.requested_qty
  return Math.max(0, Math.min(quantity, line.requested_qty))
}

/** Ligne réduite : motif d'ajustement obligatoire (RG-V04). */
export function isReduced(line: Pick<RegionalOrderLine, 'requested_qty'>, quantity: number): boolean {
  return quantity < line.requested_qty
}

/** Au-dessus du maximum validable : le serveur refuserait la validation. */
export function isAboveMax(line: RegionalOrderLine, quantity: number): boolean {
  return quantity > line.context.max_validatable
}

/** Reste de la région une fois cette quantité validée (R − q) ; négatif : dépassement. */
export function regionRemainingAfter(line: RegionalOrderLine, quantity: number): number {
  return line.context.region_remaining - quantity
}

/** Reste de la borne du client une fois cette quantité validée (B − V(c,p) − q) ; null sans borne. */
export function limitRemainingAfter(line: RegionalOrderLine, quantity: number): number | null {
  const { limit, client_validated } = line.context
  return limit === null ? null : limit - client_validated - quantity
}

/** Nombre de lignes réduites du brouillon (« Valider avec ajustement (n) »). */
export function adjustedCount(order: Pick<RegionalOrder, 'lines'>, draft: Draft): number {
  return order.lines.filter((line) => isReduced(line, quantityOf(draft, line))).length
}

/** Toutes les lignes à 0 : il faut rejeter la commande (RG-V05). */
export function isAllZero(order: Pick<RegionalOrder, 'lines'>, draft: Draft): boolean {
  return order.lines.length > 0 && order.lines.every((line) => quantityOf(draft, line) === 0)
}

function withLine(draft: Draft, productId: number, change: Partial<DraftLine>, fallback: DraftLine): Draft {
  return { ...draft, touched: true, lines: { ...draft.lines, [productId]: { ...(draft.lines[productId] ?? fallback), ...change } } }
}

const blank = (line: RegionalOrderLine): DraftLine => ({ quantity: line.requested_qty, reason: '', comment: '' })

/** Quantité d'une ligne, ramenée entre 0 et le demandé. Le motif saisi est gardé : il revient si la ligne est réduite de nouveau. */
export function setQuantity(draft: Draft, line: RegionalOrderLine, quantity: number): Draft {
  return withLine(draft, line.product_id, { quantity: Math.max(0, Math.min(Math.trunc(quantity), line.requested_qty)) }, blank(line))
}

export function setReason(draft: Draft, line: RegionalOrderLine, reason: string): Draft {
  return withLine(draft, line.product_id, { reason }, blank(line))
}

export function setComment(draft: Draft, line: RegionalOrderLine, comment: string): Draft {
  return withLine(draft, line.product_id, { comment: comment.slice(0, ADJUSTMENT_COMMENT_MAX) }, blank(line))
}

/** « Ajuster au maximum possible » : le maximum validable sur chaque ligne ; les motifs restent à choisir. */
export function withMaximum(order: Pick<RegionalOrder, 'lines'>, draft: Draft): Draft {
  return {
    ...draft,
    touched: true,
    lines: {
      ...draft.lines,
      ...Object.fromEntries(order.lines.map((line) => [line.product_id, { ...(draft.lines[line.product_id] ?? blank(line)), quantity: maxOf(line) }])),
    },
  }
}

/** Saisie manquante d'une ligne réduite. */
export type LineProblem = { reason: boolean; comment: boolean }

/**
 * Lignes réduites dont la saisie est incomplète : motif d'ajustement absent,
 * ou commentaire absent alors que le motif l'exige (AUTRE). `reasons` : motifs
 * actifs ADJUSTMENT ; non chargés, seul le motif manquant est détecté (le
 * serveur reste juge).
 */
export function lineProblems(order: Pick<RegionalOrder, 'lines'>, draft: Draft, reasons: readonly ReasonOption[] | undefined): Map<number, LineProblem> {
  const problems = new Map<number, LineProblem>()
  for (const line of order.lines) {
    if (!isReduced(line, quantityOf(draft, line))) continue
    const entry = draft.lines[line.product_id]
    const reason = entry?.reason ?? ''
    const missing = reason === ''
    const comment = !missing && requiresComment(reasons, 'ADJUSTMENT', reason) && (entry?.comment ?? '').trim() === ''
    if (missing || comment) problems.set(line.product_id, { reason: missing, comment })
  }
  return problems
}

/**
 * Corps de `POST /regional/orders/{id}/validate` : la version que le régional a
 * sous les yeux (celle du brouillon : une commande modifiée entre-temps donne un
 * conflit de version), toutes les lignes dans l'ordre de la commande ; motif et
 * commentaire sur les lignes réduites seulement (I-72, I-73).
 */
export function buildValidateBody(order: Pick<RegionalOrder, 'lines'>, draft: Draft): ValidateOrderBody {
  return {
    version: draft.version,
    lines: order.lines.map((line) => {
      const quantity = quantityOf(draft, line)
      if (!isReduced(line, quantity)) return { product_id: line.product_id, validated_qty: quantity }
      const entry = draft.lines[line.product_id]
      const comment = entry?.comment.trim() ?? ''
      return { product_id: line.product_id, validated_qty: quantity, reason_code: entry?.reason ?? '', comment: comment === '' ? null : comment }
    }),
  }
}

/** Problème d'une ligne signalé par le serveur ; valable tant que la quantité envoyée n'a pas changé. */
export type LineIssue =
  | { kind: 'violation'; violation: DecisionViolation; sent: number }
  | { kind: 'field'; messages: string[]; sent: number }

const LINE_FIELD = /^lines\.(\d+)\.(product_id|validated_qty|reason_code|comment)$/

/** Lignes en cause d'un refus de quota ou de borne (I-69). */
export function violationsOf(error: unknown): DecisionViolation[] {
  return error instanceof ApiError && Array.isArray(error.problem.violations) ? (error.problem.violations as DecisionViolation[]) : []
}

/**
 * Refus rapporté aux lignes envoyées (`sent`, dans l'ordre de l'envoi) :
 * `violations[]` (REGION_QUOTA_EXCEEDED, CLIENT_LIMIT_EXCEEDED) par produit,
 * erreurs de sens `lines.N.*` par indice (422). `general` : les autres messages (`lines`).
 */
export function lineIssues(error: unknown, sent: ValidateOrderBody['lines']): { lines: Map<number, LineIssue>; general: string[] } {
  const lines = new Map<number, LineIssue>()
  const general: string[] = []
  if (!(error instanceof ApiError)) return { lines, general }

  for (const violation of violationsOf(error)) {
    lines.set(violation.product_id, { kind: 'violation', violation, sent: violation.requested })
  }

  for (const [field, messages] of Object.entries(error.fieldErrors)) {
    const match = LINE_FIELD.exec(field)
    const line = match ? sent[Number(match[1])] : undefined
    if (!line) {
      general.push(...messages)
      continue
    }
    const previous = lines.get(line.product_id)
    lines.set(line.product_id, {
      kind: 'field',
      sent: line.validated_qty,
      messages: [...(previous?.kind === 'field' ? previous.messages : []), ...messages],
    })
  }
  return { lines, general }
}

/** Codes qui interdisent la validation (le rejet reste possible sauf journée close). */
export const BLOCKING_CODES: readonly DecisionBlocker[] = ['CLIENT_BLOCKED', 'CUTOFF_PASSED', 'DAY_NOT_OPEN']

/** Raison du blocage : celle du serveur, sinon celle du dernier refus (avant la relecture de la commande). */
export function blockerOf(order: Pick<RegionalOrder, 'blocked_reason'>, error: unknown): DecisionBlocker | null {
  if (order.blocked_reason !== null) return order.blocked_reason
  return error instanceof ApiError ? (BLOCKING_CODES.find((code) => code === error.code) ?? null) : null
}

/** La plus ancienne commande à valider (RG-V01), hors la commande en cours. */
export function oldestSubmitted(orders: readonly RegionalOrderSummary[], excludeId: string): RegionalOrderSummary | null {
  let oldest: RegionalOrderSummary | null = null
  for (const order of orders) {
    if (order.status !== 'SUBMITTED' || order.id === excludeId) continue
    if (oldest === null || Date.parse(order.submitted_at) < Date.parse(oldest.submitted_at)) oldest = order
  }
  return oldest
}
