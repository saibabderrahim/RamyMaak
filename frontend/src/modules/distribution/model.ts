import type { DistributionGrid, DistributionGridClient, DistributionGridProduct, LimitCell, NoLimitPolicy } from '../../shared/api/types'
import { BUSINESS_TIME_ZONE } from '../../shared/format'

/**
 * Règles d'affichage et de saisie de la grille de répartition (§5.1, §5.3,
 * EF-REG-02, EF-REG-03), sans React : valeur affichée d'une cellule, ΣB,
 * disponible client D(c,p), contrôles RG-B03 et RG-B07, lecture d'une
 * saisie et d'un bloc collé depuis Excel, dates de la journée.
 */

/** Borne maximale d'une cellule (RG-B01, `LimitBook::MAX_LIMIT`). */
export const MAX_LIMIT = 1_000_000

/** Taille maximale d'un lot d'enregistrement (`PUT …/limits`). */
export const MAX_BATCH = 2_000

/**
 * Corps au-delà duquel un lot part sans `keepalive`, et budget de ce qui
 * part à la fermeture de la page : le navigateur plafonne à 64 Kio la somme
 * des corps des requêtes `keepalive` en cours (marge gardée).
 */
export const KEEPALIVE_MAX_BYTES = 60_000

/** Délai d'enregistrement automatique après la dernière saisie (RG-B09). */
export const SAVE_DELAY_MS = 600

/** Modes d'affichage de la grille : Bornes (saisie), Commandes (S et V), Reste (D). */
export const DISPLAY_MODES = ['limits', 'orders', 'remaining'] as const
export type DisplayMode = (typeof DISPLAY_MODES)[number]

/** Identifiant d'une cellule : « client:produit ». */
export type CellKey = `${number}:${number}`

export const cellKey = (clientId: number, productId: number): CellKey => `${clientId}:${productId}`

/** Saisie locale d'une cellule, affichée tant qu'elle n'est pas enregistrée. */
export type LocalEdit = {
  /** Borne saisie ; null : « pas de borne ». Sans objet quand `error` est renseignée. */
  value: number | null
  /** Texte saisi, affiché tel quel quand la saisie est refusée. */
  text: string
  /** Saisie refusée sur place ou par le serveur (422) : la cellule n'est pas envoyée. */
  error: string | null
}

/** Saisies locales par client puis par produit ; une ligne inchangée garde la même référence. */
export type EditsByClient = ReadonlyMap<number, Readonly<Record<number, LocalEdit>>>

export type CellChange = { clientId: number; productId: number; edit: LocalEdit }

/** Cellules signalées (refus d'un envoi) : client → produits. */
export type Highlights = ReadonlyMap<number, ReadonlySet<number>>

export const NO_HIGHLIGHTS: Highlights = new Map()

/** Valeur affichée d'après le serveur (contrat §1) : brouillon s'il existe, sinon borne publiée ; null = pas de borne. */
export function serverLimit(cell: LimitCell | undefined): number | null {
  if (!cell) return null
  return cell.draft ? cell.draft.value : cell.limit
}

/** Valeur affichée : saisie locale valide, sinon brouillon, sinon borne publiée. */
export function displayedLimit(cell: LimitCell | undefined, edit: LocalEdit | undefined): number | null {
  return edit && edit.error === null ? edit.value : serverLimit(cell)
}

/** Cellule à envoyer aux clients : saisie valide différente de la borne publiée, ou brouillon enregistré. */
export function hasDraft(cell: LimitCell | undefined, edit: LocalEdit | undefined): boolean {
  if (edit && edit.error === null) return edit.value !== (cell?.limit ?? null)
  return (cell?.draft ?? null) !== null
}

/**
 * Disponible client D(c,p) (§5.1) : avec une borne, max(0, min(B − S − V, R)) ;
 * sans borne, R en politique LIBRE et 0 en politique OBLIGATOIRE (RG-B04).
 */
export function available(limit: number | null, pending: number, validated: number, remaining: number, policy: NoLimitPolicy): number {
  if (limit !== null) return Math.max(0, Math.min(limit - pending - validated, remaining))
  return policy === 'FREE' ? Math.max(0, remaining) : 0
}

/**
 * Disponible D(c,p) d'une cellule : calculé sur la borne **publiée** B
 * (§5.1, ce que le client peut demander maintenant), jamais sur un
 * brouillon ou une saisie qui n'a pas encore été envoyé aux clients.
 */
export function cellAvailable(cell: LimitCell | undefined, remaining: number, policy: NoLimitPolicy): number {
  return available(cell?.limit ?? null, cell?.pending ?? 0, cell?.validated ?? 0, remaining, policy)
}

/** Synthèse d'une colonne : Q, ΣB (valeurs affichées), S, V(r,p), R, surallocation (RG-B05), épuisement. */
export type ProductSummary = {
  productId: number
  quota: number
  sumLimits: number
  /** Clients ayant une borne affichée : sans aucune, ΣB s'affiche « — ». */
  limited: number
  pending: number
  validated: number
  remaining: number
  overallocated: boolean
  exhausted: boolean
}

/**
 * Synthèse de chaque produit, dans l'ordre des colonnes. ΣB porte sur
 * **tous** les clients listés par la grille (pas seulement ceux affichés par
 * un filtre) et suit chaque saisie locale ; S, V et R viennent du serveur.
 */
export function summarize(grid: Pick<DistributionGrid, 'products' | 'clients' | 'cells'>, edits: EditsByClient): ProductSummary[] {
  const sums = new Map<number, number>()
  const counts = new Map<number, number>()
  const add = (productId: number, value: number | null) => {
    if (value === null) return
    sums.set(productId, (sums.get(productId) ?? 0) + value)
    counts.set(productId, (counts.get(productId) ?? 0) + 1)
  }

  for (const client of grid.clients) {
    const row = grid.cells[String(client.id)]
    const edited = edits.get(client.id)
    if (row) {
      for (const [productKey, cell] of Object.entries(row)) {
        const productId = Number(productKey)
        if (edited && productId in edited) continue
        add(productId, serverLimit(cell))
      }
    }
    if (edited) {
      for (const [productKey, edit] of Object.entries(edited)) {
        const productId = Number(productKey)
        add(productId, displayedLimit(row?.[productKey], edit))
      }
    }
  }

  return grid.products.map((product) => {
    const sumLimits = sums.get(product.id) ?? 0
    return {
      productId: product.id,
      quota: product.quota,
      sumLimits,
      limited: counts.get(product.id) ?? 0,
      pending: product.pending,
      validated: product.validated,
      remaining: product.remaining,
      overallocated: sumLimits > product.quota,
      exhausted: product.remaining <= 0,
    }
  })
}

/** Refus d'une borne avant envoi : au-dessus du quota (RG-B03), sous le validé après le premier envoi (RG-B07). */
export type LimitProblem = { kind: 'aboveQuota'; quota: number } | { kind: 'belowValidated'; validated: number }

export function checkLimit(value: number | null, quota: number, validated: number, published: boolean): LimitProblem | null {
  if (value === null) return null
  if (value > quota) return { kind: 'aboveQuota', quota }
  if (published && value < validated) return { kind: 'belowValidated', validated }
  return null
}

/** Avertissement RG-B07 : borne inférieure à la quantité engagée E = S + V du client. */
export function belowEngaged(value: number | null, cell: LimitCell | undefined): boolean {
  return value !== null && value < (cell?.pending ?? 0) + (cell?.validated ?? 0)
}

/** Messages des saisies refusées (traduits par l'appelant). */
export type LimitMessages = {
  format: string
  aboveQuota: (quota: number) => string
  belowValidated: (validated: number) => string
}

export type ChangeInput = {
  clientId: number
  product: DistributionGridProduct
  cell: LimitCell | undefined
  edit: LocalEdit | undefined
  /** Répartition déjà envoyée (`distribution_version` > 0) : RG-B07 s'applique. */
  published: boolean
}

/**
 * Saisie d'une cellule : texte lu (vide = pas de borne), contrôlé sur place
 * (format, RG-B03, RG-B07) ; une valeur refusée s'affiche en erreur et
 * n'est jamais envoyée. null : rien ne change (valeur déjà affichée).
 */
export function buildChange({ clientId, product, cell, edit, published }: ChangeInput, text: string, messages: LimitMessages): CellChange | null {
  const productId = product.id
  const parsed = parseLimit(text)
  if (parsed === null) return { clientId, productId, edit: { value: null, text, error: messages.format } }

  const { value } = parsed
  // Retour à la borne publiée (ressaisie, « Annuler les modifications ») :
  // toujours permis, même au-dessus d'un quota diminué depuis (RG-Q06) ; le
  // serveur efface alors le brouillon.
  const restoring = cell !== undefined && value === cell.limit
  const problem = restoring ? null : checkLimit(value, product.quota, cell?.validated ?? 0, published)
  if (problem) {
    const error = problem.kind === 'aboveQuota' ? messages.aboveQuota(problem.quota) : messages.belowValidated(problem.validated)
    return { clientId, productId, edit: { value: null, text, error } }
  }
  // Valeur inchangée (F2 puis Entrée) : rien à enregistrer.
  if (edit ? edit.error === null && edit.value === value : serverLimit(cell) === value) return null
  return { clientId, productId, edit: { value, text, error: null } }
}

// Espaces tolérés comme séparateurs de milliers (Excel, saisie à la main).
const SEPARATORS = /[\s  ]/g

/**
 * Lecture d'une saisie ou d'une valeur collée : entier de 0 à 1 000 000,
 * espaces de milliers tolérés ; vide = « pas de borne » (null). Résultat
 * null : valeur refusée (décimale, négative, texte, trop grande).
 */
export function parseLimit(text: string): { value: number | null } | null {
  const compact = text.replace(SEPARATORS, '')
  if (compact === '') return { value: null }
  if (!/^\d{1,7}$/.test(compact)) return null
  const value = Number(compact)
  return value <= MAX_LIMIT ? { value } : null
}

/**
 * Bloc copié depuis Excel : lignes séparées par des retours à la ligne,
 * valeurs par des tabulations. Le retour à la ligne final qu'ajoute Excel
 * ne crée pas de ligne vide.
 */
export function parseClipboard(text: string): string[][] {
  const lines = text.replace(/\r\n?/g, '\n').split('\n')
  if (lines.length > 1 && lines.at(-1) === '') lines.pop()
  return lines.map((line) => line.split('\t'))
}

/** Le texte collé est-il un bloc (plusieurs cellules) plutôt qu'une simple valeur ? */
export function isBlock(text: string): boolean {
  return /[\t\n\r]/.test(text.replace(/(\r\n|\r|\n)$/, ''))
}

export type FamilyGroup = {
  family: DistributionGridProduct['family']
  /** Indices des produits du groupe dans la liste des colonnes. */
  columns: number[]
}

/** Colonnes groupées par famille, dans l'ordre reçu (celui de la matrice des quotas). */
export function groupByFamily(products: readonly DistributionGridProduct[]): FamilyGroup[] {
  const groups: FamilyGroup[] = []
  products.forEach((product, index) => {
    const last = groups.at(-1)
    if (last && last.family.id === product.family.id) last.columns.push(index)
    else groups.push({ family: product.family, columns: [index] })
  })
  return groups
}

/** Catégories présentes parmi les clients, par nom. */
export function categoriesOf(clients: readonly DistributionGridClient[]): Array<NonNullable<DistributionGridClient['category']>> {
  const categories = new Map<number, NonNullable<DistributionGridClient['category']>>()
  for (const client of clients) if (client.category) categories.set(client.category.id, client.category)
  return [...categories.values()].sort((a, b) => a.name.localeCompare(b.name, 'fr'))
}

const normalize = (text: string) =>
  text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim()

/** Clients affichés : recherche sur le code et le nom (sans accents ni casse), catégorie ; null = toutes. */
export function filterClients(clients: readonly DistributionGridClient[], search: string, categoryId: number | null): DistributionGridClient[] {
  const needle = normalize(search)
  return clients.filter(
    (client) =>
      (categoryId === null || client.category?.id === categoryId) &&
      (needle === '' || normalize(client.code).includes(needle) || normalize(client.name).includes(needle)),
  )
}

const quantityFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })

/** Quantité affichée « 1 200 » (espace insécable comme séparateur de milliers, ENF-13). */
export function formatQuantity(value: number): string {
  return quantityFormat.format(value).replace(/[  ]/g, ' ')
}

/** Valeur proposée à la modification (F2) : chiffres seuls, vide pour « pas de borne ». */
export function editableText(value: number | null): string {
  return value === null ? '' : String(value)
}

const timeFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: BUSINESS_TIME_ZONE, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

/** Heure « 09:12 » dans le fuseau métier (Africa/Algiers). */
export function formatTime(instant: Date | number | string): string {
  return timeFormat.format(new Date(instant))
}

/** Journée « AAAA-MM-JJ » valide (calendrier compris : pas de 31/02). */
export function isDayKey(value: string | null): value is string {
  if (value === null || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T00:00:00Z`)
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value
}

/** Lendemain calendaire d'une journée « AAAA-MM-JJ » (calcul en UTC, sans heure d'été). */
export function nextDay(day: string): string {
  const date = new Date(`${day}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + 1)
  return date.toISOString().slice(0, 10)
}

const weekdayFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'long' })

/** « jeudi » pour « 2026-10-08 ». */
export function weekdayOf(day: string): string {
  return weekdayFormat.format(new Date(`${day}T00:00:00Z`))
}

/** « 07/10 » pour « 2026-10-07 ». */
export function formatDayMonth(day: string): string {
  return `${day.slice(8, 10)}/${day.slice(5, 7)}`
}

/** Cellule repérée par son identité, stable quand la grille est relue ou filtrée. */
export type CellId = { clientId: number; productId: number }

/**
 * Sélection de la grille PC : cellule active (avec son dernier rang connu,
 * en repli si elle disparaît) et ancre d'un rectangle étendu par
 * Maj+flèches ou Maj+clic.
 */
export type GridSelection = { active: CellId & { row: number; col: number }; anchor: CellId }

export type Position = { row: number; col: number }

/** Rectangle sélectionné, en rangs de la grille affichée (bornes comprises). */
export type Range = { top: number; bottom: number; left: number; right: number }

const clamp = (value: number, last: number): number => Math.max(0, Math.min(value, last))

/**
 * Rangs actuels de la sélection dans la grille affichée (clients filtrés) :
 * cellule active suivie par identité, ramenée dans la grille si elle a
 * disparu (filtre, relecture) ; ancre disparue : la sélection se réduit à
 * la cellule active.
 */
export function locateSelection(
  selection: GridSelection | null,
  clients: ReadonlyArray<{ id: number }>,
  products: ReadonlyArray<{ id: number }>,
): { active: Position; range: Range } {
  const lastRow = clients.length - 1
  const lastCol = products.length - 1
  if (!selection) return { active: { row: 0, col: 0 }, range: { top: 0, bottom: 0, left: 0, right: 0 } }
  const rowOf = (clientId: number) => {
    const row = clients.findIndex((client) => client.id === clientId)
    return row < 0 ? null : row
  }
  const colOf = (productId: number) => {
    const col = products.findIndex((product) => product.id === productId)
    return col < 0 ? null : col
  }
  const active = {
    row: clamp(rowOf(selection.active.clientId) ?? selection.active.row, lastRow),
    col: clamp(colOf(selection.active.productId) ?? selection.active.col, lastCol),
  }
  const anchorRow = rowOf(selection.anchor.clientId)
  const anchorCol = colOf(selection.anchor.productId)
  const anchor = anchorRow === null || anchorCol === null ? active : { row: anchorRow, col: anchorCol }
  return {
    active,
    range: {
      top: Math.min(anchor.row, active.row),
      bottom: Math.max(anchor.row, active.row),
      left: Math.min(anchor.col, active.col),
      right: Math.max(anchor.col, active.col),
    },
  }
}

/** Cellules refusées par un envoi, par client puis par produit. */
export function highlightsOf(violations: ReadonlyArray<{ client_id: number; product_id: number }>): Highlights {
  const map = new Map<number, Set<number>>()
  for (const { client_id, product_id } of violations) map.set(client_id, (map.get(client_id) ?? new Set()).add(product_id))
  return map
}

/** Une cellule ressaisie n'est plus signalée. */
export function withoutCells(highlights: Highlights, changes: readonly CellChange[]): Highlights {
  if (!changes.some(({ clientId, productId }) => highlights.get(clientId)?.has(productId))) return highlights
  const next = new Map(highlights)
  for (const { clientId, productId } of changes) {
    const products = next.get(clientId)
    if (!products?.has(productId)) continue
    const rest = new Set(products)
    rest.delete(productId)
    if (rest.size > 0) next.set(clientId, rest)
    else next.delete(clientId)
  }
  return next
}

/**
 * « Annuler les modifications » : chaque cellule en brouillon (enregistré ou
 * saisi) reçoit sa borne publiée, ce qui efface le brouillon (contrat §3).
 */
export function revertChanges(grid: Pick<DistributionGrid, 'clients' | 'cells' | 'products'>, edits: EditsByClient): CellChange[] {
  const products = new Set(grid.products.map((product) => product.id))
  const changes: CellChange[] = []
  for (const client of grid.clients) {
    const row = grid.cells[String(client.id)]
    const edited = edits.get(client.id)
    const productIds = new Set<number>()
    if (row) for (const [productKey, cell] of Object.entries(row)) if (cell.draft !== null) productIds.add(Number(productKey))
    if (edited) {
      for (const [productKey, edit] of Object.entries(edited)) {
        if (edit.error !== null || edit.value !== (row?.[productKey]?.limit ?? null)) productIds.add(Number(productKey))
      }
    }
    for (const productId of productIds) {
      if (!products.has(productId)) continue
      const limit = row?.[String(productId)]?.limit ?? null
      changes.push({ clientId: client.id, productId, edit: { value: limit, text: editableText(limit), error: null } })
    }
  }
  return changes
}
