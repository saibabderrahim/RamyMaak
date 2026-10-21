import { ApiError } from '../../shared/api/client'
import type { LimitCellInput, SaveLimitsResult } from '../../shared/api/types'
import { type CellChange, type CellKey, cellKey, KEEPALIVE_MAX_BYTES, type LocalEdit, MAX_BATCH, SAVE_DELAY_MS } from './model'

/**
 * Enregistrement automatique des bornes en brouillon (RG-B09) : les
 * saisies attendent un court délai, partent par lots (`PUT …/limits`,
 * 2 000 cellules au plus) et une seule requête est en cours à la fois. Une
 * saisie reste affichée par-dessus les données du serveur jusqu'à son
 * accusé de réception, même si la grille est relue entre-temps (temps réel,
 * rafraîchissement de secours). Rien n'est perdu à la fermeture : la file
 * part sans attendre quand la page est masquée ou quittée, et les saisies
 * sans accusé de réception restent dans le navigateur (échec réseau,
 * départ de l'écran, application tuée) jusqu'à la prochaine ouverture de la
 * grille, qui les renvoie.
 */

export type SaveStatus = 'idle' | 'pending' | 'saving' | 'saved' | 'error'

export type DraftSnapshot = {
  /** Saisies locales par client puis par produit ; une ligne inchangée garde la même référence. */
  edits: ReadonlyMap<number, Readonly<Record<number, LocalEdit>>>
  status: SaveStatus
  /** Heure du dernier enregistrement réussi. */
  savedAt: Date | null
  /** Échec du dernier envoi (réseau, serveur) : nouvel essai par retry() ou à la saisie suivante. */
  error: unknown
  /** Journée ou région clôturée, quotas retirés (409 DAY_NOT_OPEN) : plus rien n'est envoyé. */
  closed: boolean
  /** Cellules saisies non encore enregistrées (en attente ou en cours d'envoi). */
  unsaved: number
  /** Cellules en erreur, non envoyées. */
  invalid: number
  /** Bornes d'une visite précédente reprises à l'ouverture et renvoyées (RG-B09). */
  restored: number
}

/** Saisies gardées jusqu'à leur accusé de réception (RG-B09). */
export type DraftStorage = {
  load: () => LimitCellInput[]
  /** Liste vide : plus rien à garder, l'entrée disparaît. */
  save: (cells: readonly LimitCellInput[]) => void
}

export type DraftQueueOptions = {
  send: (cells: LimitCellInput[]) => Promise<SaveLimitsResult>
  /** Réponse du serveur : état des cellules envoyées, à reporter dans la grille. */
  onSaved: (result: SaveLimitsResult) => void
  /** Journée clôturée (409 DAY_NOT_OPEN). */
  onClosed: (error: ApiError) => void
  /** Aucun stockage par défaut. */
  storage?: DraftStorage
  delay?: number
  maxBatch?: number
  /** Corps maximal d'une requête `keepalive`, et budget de l'envoi à la fermeture de la page. */
  keepaliveBytes?: number
}

const NO_STORAGE: DraftStorage = { load: () => [], save: () => undefined }

/** Clé des saisies sans accusé de réception d'un utilisateur, pour une journée et une région. */
export const draftStorageKey = (userId: number, day: string, regionId: number) => `ramymaak:limit-drafts:${userId}:${day}:${regionId}`

function isCellInput(value: unknown): value is LimitCellInput {
  if (typeof value !== 'object' || value === null) return false
  const { client_id, product_id, limit } = value as Record<string, unknown>
  return Number.isInteger(client_id) && Number.isInteger(product_id) && (limit === null || (Number.isInteger(limit) && Number(limit) >= 0))
}

/**
 * Stockage dans le navigateur (`localStorage`, propre à l'appareil). Il peut
 * être indisponible ou plein (navigation privée, refus) : chaque accès est
 * protégé, et la saisie continue sans lui.
 */
export function browserDraftStorage(key: string): DraftStorage {
  return {
    load: () => {
      try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(key) ?? '[]')
        if (!Array.isArray(parsed)) return []
        return parsed.filter(isCellInput).map(({ client_id, product_id, limit }) => ({ client_id, product_id, limit }))
      } catch {
        return []
      }
    },
    save: (cells) => {
      try {
        if (cells.length === 0) window.localStorage.removeItem(key)
        else window.localStorage.setItem(key, JSON.stringify(cells))
      } catch {
        // Stockage indisponible ou plein : la file en mémoire reste la seule copie.
      }
    },
  }
}

// Corps d'un lot, `{"region_id":…,"cells":[…]}` : enveloppe (avec une marge) et cellules suivies d'une virgule.
const BODY_BYTES = 40
const cellBytes = (cell: LimitCellInput): number => JSON.stringify(cell).length + 1

/** Le corps d'un lot de ces cellules dépasse-t-il `limit` octets ? */
function exceeds(cells: Iterable<LimitCellInput>, limit: number): boolean {
  let bytes = BODY_BYTES
  for (const cell of cells) {
    bytes += cellBytes(cell)
    if (bytes > limit) return true
  }
  return false
}

// Erreurs de validation par cellule : « cells.3.limit » → élément 3 du lot.
const CELL_ERROR = /^cells\.(\d+)\./

export class DraftQueue {
  private readonly options: Required<DraftQueueOptions>
  private readonly edits = new Map<number, Record<number, LocalEdit>>()
  /** Cellules à envoyer, dans l'ordre de saisie (Map : une seule entrée par cellule). */
  private readonly queue = new Map<CellKey, LimitCellInput>()
  private inflight: LimitCellInput[] | null = null
  private current: Promise<void> | null = null
  private timer: ReturnType<typeof setTimeout> | null = null
  /** Page masquée pendant un envoi : la suite part dès sa réponse, sans délai. */
  private urgent = false
  /** Écran quitté : le stockage n'est plus réécrit, l'écran suivant de la même grille le reprend. */
  private detached = false
  /** Dernière liste écrite dans le stockage ; null : rien d'écrit encore. */
  private stored: string | null = null
  private readonly listeners = new Set<() => void>()
  private snapshot: DraftSnapshot = { edits: new Map(), status: 'idle', savedAt: null, error: null, closed: false, unsaved: 0, invalid: 0, restored: 0 }

  constructor(options: DraftQueueOptions) {
    this.options = { delay: SAVE_DELAY_MS, maxBatch: MAX_BATCH, keepaliveBytes: KEEPALIVE_MAX_BYTES, storage: NO_STORAGE, ...options }
  }

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener)
    return () => this.listeners.delete(listener)
  }

  getSnapshot = (): DraftSnapshot => this.snapshot

  /** Saisie d'une ou plusieurs cellules (frappe, Suppr, collage, action de masse). */
  edit(changes: readonly CellChange[]): void {
    if (this.snapshot.closed || changes.length === 0) return
    for (const { clientId, productId, edit } of changes) {
      this.setEdit(clientId, productId, edit)
      const key = cellKey(clientId, productId)
      // Réinsertion : la cellule passe en fin de file, avec sa dernière valeur.
      this.queue.delete(key)
      if (edit.error === null) this.queue.set(key, { client_id: clientId, product_id: productId, limit: edit.value })
    }
    this.schedule()
    // Gros collage : au-delà de ce qu'une requête `keepalive` peut emporter à
    // la fermeture de la page, la file part sans attendre le délai.
    if (exceeds(this.queue.values(), this.options.keepaliveBytes)) {
      if (this.inflight) this.urgent = true
      else this.flush()
    }
    this.emitStatus()
  }

  /** Abandon d'une saisie (Échap sur une cellule en erreur) : la valeur du serveur réapparaît. */
  revert(clientId: number, productId: number): void {
    if (!this.removeEdit(clientId, productId)) return
    this.queue.delete(cellKey(clientId, productId))
    if (this.queue.size === 0) this.clearTimer()
    this.emitStatus()
  }

  /**
   * Saisies d'une visite précédente restées sans accusé de réception
   * (RG-B09) : chacune est reconstruite par `build` (contrôles de la saisie ;
   * null : abandonnée), affichée comme une saisie locale et renvoyée
   * aussitôt (`restored` : leur nombre). Le stockage est réécrit : ce qui
   * n'est pas repris en sort.
   */
  restore(build: (cell: LimitCellInput) => CellChange | null): void {
    const changes = this.options.storage.load().flatMap((cell) => build(cell) ?? [])
    this.edit(changes)
    this.flush()
    this.persist()
    const restored = changes.filter((change) => change.edit.error === null).length
    if (restored > 0) this.emit({ restored })
  }

  /** Envoi immédiat du lot suivant (délai écoulé, « Réessayer »). */
  flush(): void {
    this.clearTimer()
    if (this.inflight || this.queue.size === 0 || this.snapshot.closed) return

    const batch: LimitCellInput[] = []
    for (const [key, cell] of this.queue) {
      if (batch.length >= this.options.maxBatch) break
      batch.push(cell)
      this.queue.delete(key)
    }
    this.inflight = batch
    this.emit({ status: 'saving', error: null })

    this.current = this.options.send(batch).then(
      (result) => this.succeeded(batch, result),
      (error: unknown) => this.failed(batch, error),
    )
  }

  /** Nouvel essai après un échec. */
  retry(): void {
    this.flush()
  }

  /**
   * Enregistre tout ce qui attend (avant un envoi aux clients ou « Reprendre
   * J-1 »). Rejette si un envoi échoue ou si la journée est clôturée ; les
   * cellules en erreur ne bloquent pas : elles ne sont simplement pas envoyées.
   */
  async flushAll(): Promise<void> {
    this.clearTimer()
    while (this.queue.size > 0 || this.current) {
      if (!this.current) this.flush()
      const current = this.current
      if (!current) break
      await current
      if (this.snapshot.closed || this.snapshot.status === 'error') throw this.snapshot.error
    }
  }

  /**
   * Page masquée ou quittée (RG-B09) : ce qui attend part tout de suite.
   * Envoi en cours : la suite part dès sa réponse. Document détruit
   * (`unloading`) : aucune réponse ne sera lue, ce qui attend part aussitôt
   * dans le budget des requêtes `keepalive`.
   */
  flushNow(unloading = false): void {
    this.clearTimer()
    if (this.snapshot.closed || this.queue.size === 0) return
    if (unloading) this.sendOnUnload()
    else if (this.inflight) this.urgent = true
    else this.flush()
  }

  /** Écran affiché (et réaffiché par le double montage du mode strict) : la file tient le stockage à jour. */
  attach(): void {
    this.detached = false
  }

  /**
   * Départ de l'écran : ce qui attend part tout de suite plutôt que d'être
   * perdu, y compris un lot remis en file après un échec. flush() ne fait
   * rien si un envoi est en cours (le reste part à sa réponse), si la file
   * est vide ou si la journée est clôturée. Le stockage garde les saisies
   * sans accusé de réception pour le prochain écran de la même grille, qui
   * en prend la charge : cette file ne le réécrit plus.
   */
  dispose(): void {
    this.detached = true
    this.flush()
  }

  /**
   * Le document va être détruit : seules les requêtes `keepalive`
   * aboutissent, et le navigateur plafonne la somme de leurs corps (envoi en
   * cours compris). Les saisies les plus récentes partent d'abord, en lots
   * qui tiennent dans ce budget. Faute d'accusé de réception lisible, toutes
   * restent dans la file et dans le stockage : celles qui ne sont pas parties
   * (ou dont l'envoi a échoué) seront reprises à la prochaine ouverture.
   */
  private sendOnUnload(): void {
    const { keepaliveBytes, maxBatch } = this.options
    const inflight = this.inflight ? BODY_BYTES + this.inflight.reduce((sum, cell) => sum + cellBytes(cell), 0) : 0
    let budget = keepaliveBytes - (inflight <= keepaliveBytes ? inflight : 0)
    const batches: LimitCellInput[][] = []
    let batch: LimitCellInput[] = []
    let batchBytes = 0
    for (const cell of [...this.queue.values()].reverse()) {
      const size = cellBytes(cell)
      if (batch.length > 0 && (batch.length >= maxBatch || batchBytes + size > keepaliveBytes)) {
        batches.push(batch)
        batch = []
      }
      const cost = batch.length === 0 ? BODY_BYTES + size : size
      if (cost > budget) break
      budget -= cost
      batchBytes = batch.length === 0 ? cost : batchBytes + size
      batch.push(cell)
    }
    if (batch.length > 0) batches.push(batch)
    for (const cells of batches) this.options.send(cells.reverse()).catch(() => undefined)
  }

  private schedule(): void {
    this.clearTimer()
    if (this.queue.size > 0) this.timer = setTimeout(() => this.flush(), this.options.delay)
  }

  private clearTimer(): void {
    if (this.timer !== null) clearTimeout(this.timer)
    this.timer = null
  }

  private succeeded(batch: LimitCellInput[], result: SaveLimitsResult): void {
    this.inflight = null
    this.current = null
    this.options.onSaved(result)
    // Accusé de réception : la saisie s'efface derrière la valeur du serveur,
    // sauf si la cellule a été ressaisie entre-temps.
    for (const cell of batch) {
      const edit = this.edits.get(cell.client_id)?.[cell.product_id]
      if (edit && edit.error === null && edit.value === cell.limit && !this.queue.has(cellKey(cell.client_id, cell.product_id))) {
        this.removeEdit(cell.client_id, cell.product_id)
      }
    }
    this.emit({ status: this.queue.size > 0 ? 'pending' : 'saved', savedAt: new Date(), error: null })
    this.continue()
  }

  private failed(batch: LimitCellInput[], error: unknown): void {
    this.inflight = null
    this.current = null

    if (error instanceof ApiError && error.code === 'DAY_NOT_OPEN') {
      this.clearTimer()
      this.queue.clear()
      this.edits.clear()
      this.urgent = false
      this.emit({ status: 'idle', closed: true, error })
      this.options.onClosed(error)
      return
    }

    // Refus définitif (422) : les cellules désignées passent en erreur ; sans
    // désignation, tout le lot (un nouvel essai serait refusé de même).
    const refusals = new Map<number, string>()
    if (error instanceof ApiError && error.status === 422) {
      for (const [field, messages] of Object.entries(error.fieldErrors)) {
        const index = CELL_ERROR.exec(field)?.[1]
        if (index !== undefined && messages[0] && !refusals.has(Number(index))) refusals.set(Number(index), messages[0])
      }
      if (refusals.size === 0) batch.forEach((_, index) => refusals.set(index, error.message))
    }

    // Le lot est refusé en entier (une transaction) : les cellules fautives
    // passent en erreur (et sortent du stockage), les autres repartent ; sans
    // refus, tout le lot attend un nouvel essai.
    batch.forEach((cell, index) => {
      const key = cellKey(cell.client_id, cell.product_id)
      const edit = this.edits.get(cell.client_id)?.[cell.product_id]
      if (!edit || edit.error !== null || edit.value !== cell.limit || this.queue.has(key)) return
      const message = refusals.get(index)
      if (message) this.setEdit(cell.client_id, cell.product_id, { ...edit, error: message })
      else this.queue.set(key, cell)
    })

    if (refusals.size > 0) {
      this.emitStatus()
      this.continue()
    } else {
      this.urgent = false
      this.emit({ status: 'error', error })
    }
  }

  /** Lot suivant : reste d'un gros collage, saisies dont le délai a expiré pendant l'envoi, page masquée. */
  private continue(): void {
    if (this.queue.size > 0 && (this.timer === null || this.urgent)) {
      this.urgent = false
      this.flush()
    }
  }

  /**
   * Statut d'après l'état réel de la file : échec dont les cellules attendent
   * un nouvel essai, envoi en cours, saisies en attente du délai, sinon
   * dernier enregistrement (une saisie refusée sur place n'envoie rien).
   */
  private emitStatus(): void {
    let status: SaveStatus
    if (this.snapshot.status === 'error' && this.queue.size > 0) status = 'error'
    else if (this.inflight) status = 'saving'
    else if (this.queue.size > 0) status = 'pending'
    else status = this.snapshot.savedAt ? 'saved' : 'idle'
    this.emit({ status, error: status === 'error' ? this.snapshot.error : null })
  }

  private setEdit(clientId: number, productId: number, edit: LocalEdit): void {
    this.edits.set(clientId, { ...this.edits.get(clientId), [productId]: edit })
  }

  private removeEdit(clientId: number, productId: number): boolean {
    const row = this.edits.get(clientId)
    if (!row || !(productId in row)) return false
    const rest = { ...row }
    delete rest[productId]
    if (Object.keys(rest).length === 0) this.edits.delete(clientId)
    else this.edits.set(clientId, rest)
    return true
  }

  /**
   * Saisies sans accusé de réception (envoi en cours, puis file, la dernière
   * valeur d'une cellule l'emportant) écrites dans le stockage à chaque
   * changement ; l'entrée disparaît quand tout est enregistré ou refusé.
   */
  private persist(): void {
    if (this.detached) return
    const cells = new Map<CellKey, LimitCellInput>()
    for (const cell of this.inflight ?? []) cells.set(cellKey(cell.client_id, cell.product_id), cell)
    for (const [key, cell] of this.queue) {
      cells.delete(key)
      cells.set(key, cell)
    }
    const list = [...cells.values()]
    const serialized = JSON.stringify(list)
    if (serialized === this.stored) return
    this.stored = serialized
    this.options.storage.save(list)
  }

  private emit(patch: Partial<DraftSnapshot>): void {
    let invalid = 0
    for (const row of this.edits.values()) {
      for (const edit of Object.values(row)) if (edit.error !== null) invalid++
    }
    this.snapshot = {
      ...this.snapshot,
      ...patch,
      edits: new Map(this.edits),
      unsaved: this.queue.size + (this.inflight?.length ?? 0),
      invalid,
    }
    this.persist()
    this.listeners.forEach((listener) => listener())
  }
}
