import type { AdvOrderLine, AdvOrderStatus, AdvOrderSummary, OrderStatus, ProcessingStatus } from '../../../shared/api/types'
import { isDay } from '../days/dates'

// Règles de la file ADV et du détail d'une commande (EF-ADV-04 à EF-ADV-06) — contrat :
// docs/lot-6-contrat-api.md §5 et §8. Fonctions pures, testées sans écran.

/**
 * Onglets de la file : un statut chacun, et l'étape suivante du traitement en
 * masse (I-78, une étape à la fois). Les identifiants sont ceux de l'adresse
 * (`?onglet=en-preparation`).
 */
export const QUEUE_TABS = [
  { id: 'a-preparer', status: 'VALIDATED', next: 'IN_PREPARATION' },
  { id: 'en-preparation', status: 'IN_PREPARATION', next: 'PREPARED' },
  { id: 'preparees', status: 'PREPARED', next: 'SHIPPED' },
  { id: 'expediees', status: 'SHIPPED', next: null },
  { id: 'annulees', status: 'CANCELLED', next: null },
] as const satisfies readonly { id: string; status: AdvOrderStatus; next: ProcessingStatus | null }[]

export type QueueTab = (typeof QUEUE_TABS)[number]
export type QueueTabId = QueueTab['id']

export const DEFAULT_TAB: QueueTabId = 'a-preparer'

export function tabById(id: QueueTabId): QueueTab {
  return QUEUE_TABS.find((tab) => tab.id === id) ?? QUEUE_TABS[0]
}

/** Au plus 100 commandes par changement de statut (I-78). */
export const MAX_SELECTION = 100

/** Taille de page de la file (contrat §5 : 50 par défaut). */
export const PAGE_SIZE = 50

/** Tris proposés (le premier est celui du serveur : premier validé, premier préparé). */
export const QUEUE_SORTS = ['decided_at', '-decided_at', 'number', '-number'] as const
export type QueueSort = (typeof QUEUE_SORTS)[number]

/**
 * Filtres et position de la file, tels que l'adresse les porte (chaînes vides :
 * pas de filtre). La recherche libre (numéro, code ou nom du client) est une
 * saisie de l'écran, hors de l'adresse.
 */
export type QueueParams = {
  tab: QueueTabId
  /** AAAA-MM-JJ */
  day: string
  region: string
  /** Identifiant du dépôt, ou `none` (régions sans dépôt). */
  depot: string
  product: string
  sort: QueueSort
  page: number
}

const PARAM_NAMES = { tab: 'onglet', day: 'jour', region: 'region', depot: 'depot', product: 'produit', sort: 'tri', page: 'page' } as const

const POSITIVE = /^[1-9]\d{0,9}$/

function positive(value: string | null): string {
  return value !== null && POSITIVE.test(value) ? value : ''
}

/** Filtres lus dans l'adresse ; une valeur mal formée est ignorée. */
export function readQueueParams(params: URLSearchParams): QueueParams {
  const tab = QUEUE_TABS.find((candidate) => candidate.id === params.get(PARAM_NAMES.tab))
  const day = params.get(PARAM_NAMES.day)
  const depot = params.get(PARAM_NAMES.depot)
  const sort = QUEUE_SORTS.find((candidate) => candidate === params.get(PARAM_NAMES.sort))
  const page = Number(params.get(PARAM_NAMES.page))
  return {
    tab: tab?.id ?? DEFAULT_TAB,
    day: isDay(day) ? day : '',
    region: positive(params.get(PARAM_NAMES.region)),
    depot: depot === 'none' ? 'none' : positive(depot),
    product: positive(params.get(PARAM_NAMES.product)),
    sort: sort ?? 'decided_at',
    page: Number.isInteger(page) && page >= 1 ? page : 1,
  }
}

/**
 * Adresse après un changement : les valeurs par défaut en sont retirées, et
 * la page repart à 1 sauf si le changement est celui de la page.
 */
export function writeQueueParams(current: URLSearchParams, patch: Partial<QueueParams>): URLSearchParams {
  const next = { ...readQueueParams(current), ...('page' in patch ? {} : { page: 1 }), ...patch }
  const params = new URLSearchParams()
  if (next.tab !== DEFAULT_TAB) params.set(PARAM_NAMES.tab, next.tab)
  for (const key of ['day', 'region', 'depot', 'product'] as const) {
    if (next[key] !== '') params.set(PARAM_NAMES[key], next[key])
  }
  if (next.sort !== 'decided_at') params.set(PARAM_NAMES.sort, next.sort)
  if (next.page > 1) params.set(PARAM_NAMES.page, String(next.page))
  return params
}

/** Nombre de filtres actifs (hors onglet, tri et page) ; la recherche libre est tenue à part par l'écran. */
export function activeFilters(params: QueueParams, search = ''): number {
  return [params.day, params.region, params.depot, params.product, search.trim()].filter((value) => value !== '').length
}

/** Journées affichées par la page (la file court sur plusieurs journées, I-90), sans doublon, dans l'ordre. */
export function daysOf(rows: readonly Pick<AdvOrderSummary, 'day'>[]): string[] {
  return [...new Set(rows.map((row) => row.day))].sort()
}

/** Commandes cochées (instantané du dernier état lu), par identifiant. */
export type Selection = ReadonlyMap<string, AdvOrderSummary>

export function toggled(selection: Selection, row: AdvOrderSummary): Selection {
  const next = new Map(selection)
  if (next.has(row.id)) next.delete(row.id)
  else if (next.size < MAX_SELECTION) next.set(row.id, row)
  return next
}

/** Toute la page cochée, ou décochée si elle l'était déjà (dans la limite des 100). */
export function pageToggled(selection: Selection, rows: readonly AdvOrderSummary[]): Selection {
  const next = new Map(selection)
  if (rows.length > 0 && rows.every((row) => next.has(row.id))) {
    rows.forEach((row) => next.delete(row.id))
    return next
  }
  for (const row of rows) {
    if (next.size >= MAX_SELECTION) break
    next.set(row.id, row)
  }
  return next
}

export type BulkPlan = {
  /** Commandes envoyées : celles dont l'étape cible est permise à l'appelant. */
  items: { id: string; version: number }[]
  /** Numéros des commandes cochées qui n'ont plus cette étape (changées depuis, ou droits absents) : non envoyées. */
  skipped: string[]
}

/**
 * Changement de statut en masse (RG-A02) : seules les commandes pour lesquelles
 * l'étape cible est permise sont envoyées, avec la version la plus récente lue
 * (`latest`, la page affichée) ; les autres sont comptées à part.
 */
export function planBulk(selection: Selection, latest: readonly AdvOrderSummary[], target: ProcessingStatus): BulkPlan {
  const fresh = new Map(latest.map((row) => [row.id, row]))
  const plan: BulkPlan = { items: [], skipped: [] }
  for (const [id, snapshot] of selection) {
    const row = fresh.get(id) ?? snapshot
    if (row.next_statuses.includes(target)) plan.items.push({ id, version: row.version })
    else plan.skipped.push(row.number)
  }
  return plan
}

/* ---------- Expédition (RG-A03, I-77) ---------- */

/** Saisie d'une ligne : quantité (texte du champ) et motif d'écart ; absent : valeur du serveur. */
export type ShippingEdit = { qty?: string; reason?: string }
export type ShippingEdits = Readonly<Record<number, ShippingEdit>>

/** Quantité expédiée affichée : la saisie, sinon la valeur du serveur, sinon la quantité validée (défaut de I-77). */
export function shippedValue(line: AdvOrderLine, edit?: ShippingEdit): string {
  if (edit?.qty !== undefined) return edit.qty
  return String(line.shipped_qty ?? line.validated_qty ?? 0)
}

/** Motif d'écart affiché : la saisie, sinon celui du serveur. */
export function gapReasonValue(line: AdvOrderLine, edit?: ShippingEdit): string {
  return edit?.reason ?? line.shipping_gap_reason?.code ?? ''
}

/** Quantité saisie : entier de 0 à 1 000 000 ; null sinon (champ vide, décimal, négatif). */
export function parseQuantity(text: string): number | null {
  if (!/^\d{1,7}$/.test(text.trim())) return null
  const value = Number(text.trim())
  return value <= 1_000_000 ? value : null
}

export type ShippingProblem = 'qty_invalid' | 'qty_over' | 'reason_required' | 'reason_prohibited'

/** Valeur de la ligne côté serveur : quantité expédiée (la validée tant qu'elle n'est pas saisie, I-77) et motif d'écart. */
function serverShipping(line: AdvOrderLine): { qty: number; reason: string } {
  return { qty: line.shipped_qty ?? line.validated_qty ?? 0, reason: line.shipping_gap_reason?.code ?? '' }
}

/** La saisie de la ligne diffère-t-elle du serveur (une quantité illisible compte : elle reste à corriger) ? */
export function lineChanged(line: AdvOrderLine, edit?: ShippingEdit): boolean {
  if (!edit) return false
  const qty = parseQuantity(shippedValue(line, edit))
  if (qty === null) return true
  const server = serverShipping(line)
  return qty !== server.qty || gapReasonValue(line, edit) !== server.reason
}

/** Contrôle d'une ligne saisie avant l'envoi (le serveur rejoue tout) : 0 ≤ expédié ≤ validé, motif si et seulement si expédié < validé. */
export function lineProblem(line: AdvOrderLine, edit?: ShippingEdit): ShippingProblem | null {
  const validated = line.validated_qty ?? 0
  const qty = parseQuantity(shippedValue(line, edit))
  if (qty === null) return 'qty_invalid'
  if (qty > validated) return 'qty_over'
  const reason = gapReasonValue(line, edit)
  if (qty < validated && reason === '') return 'reason_required'
  if (qty === validated && reason !== '') return 'reason_prohibited'
  return null
}

/** Problèmes des lignes modifiées, par produit. */
export function shippingProblems(lines: readonly AdvOrderLine[], edits: ShippingEdits): Record<number, ShippingProblem> {
  const problems: Record<number, ShippingProblem> = {}
  for (const line of lines) {
    if (!lineChanged(line, edits[line.product_id])) continue
    const problem = lineProblem(line, edits[line.product_id])
    if (problem) problems[line.product_id] = problem
  }
  return problems
}

export type ShippingLineBody = { product_id: number; shipped_qty: number; reason_code?: string }

/**
 * Lignes à envoyer : seulement celles qui diffèrent du serveur (envoi partiel,
 * contrat §5). Le motif accompagne une quantité inférieure à la validée, et
 * seulement elle.
 */
export function shippingLines(lines: readonly AdvOrderLine[], edits: ShippingEdits): ShippingLineBody[] {
  const body: ShippingLineBody[] = []
  for (const line of lines) {
    const edit = edits[line.product_id]
    if (!lineChanged(line, edit)) continue
    const qty = parseQuantity(shippedValue(line, edit))
    if (qty === null) continue
    body.push(
      qty < (line.validated_qty ?? 0)
        ? { product_id: line.product_id, shipped_qty: qty, reason_code: gapReasonValue(line, edit) }
        : { product_id: line.product_id, shipped_qty: qty },
    )
  }
  return body
}

/** Messages du serveur sur les champs d'expédition, par produit (`lines.N.*` renvoie aux lignes envoyées). */
export function shippingFieldErrors(error: unknown, submitted: readonly { product_id: number }[]): Record<string, string> {
  const fields = (error as { fieldErrors?: Record<string, string[]> } | null)?.fieldErrors ?? {}
  const found: Record<string, string> = {}
  for (const [key, messages] of Object.entries(fields)) {
    const match = /^lines\.(\d+)\./.exec(key)
    const line = match ? submitted[Number(match[1])] : undefined
    if (messages[0] && line) found[String(line.product_id)] ??= messages[0]
    else if (messages[0] && key === 'delivery_note_ref') found.note = messages[0]
  }
  return found
}

/** Des quantités ou des motifs sont-ils saisis (à enregistrer) ? */
export function hasShippingEdits(lines: readonly AdvOrderLine[], edits: ShippingEdits): boolean {
  return lines.some((line) => lineChanged(line, edits[line.product_id]))
}

/** Couleur du statut (contraste AA, le libellé dit toujours le statut) : mêmes teintes que « Mes commandes » du client. */
export const STATUS_CLASSES: Record<OrderStatus, string> = {
  SUBMITTED: 'bg-sky-100 text-sky-900',
  VALIDATED: 'bg-emerald-100 text-emerald-900',
  IN_PREPARATION: 'bg-teal-100 text-teal-900',
  PREPARED: 'bg-green-100 text-green-900',
  SHIPPED: 'bg-green-200 text-green-950',
  DELIVERED: 'bg-emerald-200 text-emerald-950',
  REJECTED: 'bg-red-100 text-red-800',
  CANCELLED: 'bg-slate-100 text-slate-700',
  EXPIRED: 'bg-slate-200 text-slate-800',
}
