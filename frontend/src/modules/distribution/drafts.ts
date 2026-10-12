import { ApiError } from '../../shared/api/client'
import type { LimitCellInput, SaveLimitsResult } from '../../shared/api/types'
import { type CellChange, type CellKey, cellKey, type LocalEdit, MAX_BATCH, SAVE_DELAY_MS } from './model'

/**
 * Enregistrement automatique des bornes en brouillon (RG-B09) : les
 * saisies attendent un court délai, partent par lots (`PUT …/limits`,
 * 2 000 cellules au plus) et une seule requête est en cours à la fois. Une
 * saisie reste affichée par-dessus les données du serveur jusqu'à son
 * accusé de réception, même si la grille est relue entre-temps (temps réel,
 * rafraîchissement de secours). Rien n'est perdu à la fermeture : la file
 * part sans attendre quand la page est masquée ou quittée.
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
}

export type DraftQueueOptions = {
  send: (cells: LimitCellInput[]) => Promise<SaveLimitsResult>
  /** Réponse du serveur : état des cellules envoyées, à reporter dans la grille. */
  onSaved: (result: SaveLimitsResult) => void
  /** Journée clôturée (409 DAY_NOT_OPEN). */
  onClosed: (error: ApiError) => void
  delay?: number
  maxBatch?: number
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
  private readonly listeners = new Set<() => void>()
  private snapshot: DraftSnapshot = { edits: new Map(), status: 'idle', savedAt: null, error: null, closed: false, unsaved: 0, invalid: 0 }

  constructor(options: DraftQueueOptions) {
    this.options = { delay: SAVE_DELAY_MS, maxBatch: MAX_BATCH, ...options }
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
    this.emit({ status: this.snapshot.status === 'error' ? 'error' : this.inflight ? 'saving' : 'pending' })
  }

  /** Abandon d'une saisie (Échap sur une cellule en erreur) : la valeur du serveur réapparaît. */
  revert(clientId: number, productId: number): void {
    if (!this.removeEdit(clientId, productId)) return
    this.queue.delete(cellKey(clientId, productId))
    this.emit({})
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
   * Envoi en cours : la suite part dès sa réponse ; si le document est
   * détruit (`unloading`), aucune réponse ne sera lue et le reste part
   * aussitôt, sans attendre.
   */
  flushNow(unloading = false): void {
    this.clearTimer()
    if (this.snapshot.closed || this.queue.size === 0) return
    if (!this.inflight) {
      this.flush()
      return
    }
    if (!unloading) {
      this.urgent = true
      return
    }
    const cells = [...this.queue.values()]
    this.queue.clear()
    for (let start = 0; start < cells.length; start += this.options.maxBatch) {
      this.options.send(cells.slice(start, start + this.options.maxBatch)).catch(() => undefined)
    }
  }

  /**
   * Départ de l'écran : ce qui attend part tout de suite plutôt que d'être
   * perdu, y compris un lot remis en file après un échec. flush() ne fait
   * rien si un envoi est en cours (le reste part à sa réponse), si la file
   * est vide ou si la journée est clôturée.
   */
  dispose(): void {
    this.flush()
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

    const cellErrors = new Map<number, string>()
    if (error instanceof ApiError && error.code === 'VALIDATION_FAILED') {
      for (const [field, messages] of Object.entries(error.fieldErrors)) {
        const index = CELL_ERROR.exec(field)?.[1]
        if (index !== undefined && messages[0] && !cellErrors.has(Number(index))) cellErrors.set(Number(index), messages[0])
      }
    }

    // Le lot est refusé en entier (une transaction) : les cellules fautives
    // passent en erreur, les autres repartent ; sans erreur par cellule,
    // tout le lot attend un nouvel essai.
    batch.forEach((cell, index) => {
      const key = cellKey(cell.client_id, cell.product_id)
      const edit = this.edits.get(cell.client_id)?.[cell.product_id]
      if (!edit || edit.error !== null || edit.value !== cell.limit || this.queue.has(key)) return
      const message = cellErrors.get(index)
      if (message) this.setEdit(cell.client_id, cell.product_id, { ...edit, error: message })
      else this.queue.set(key, cell)
    })

    if (cellErrors.size > 0) {
      this.emit({ status: this.queue.size > 0 ? 'pending' : this.snapshot.savedAt ? 'saved' : 'idle', error: null })
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
    this.listeners.forEach((listener) => listener())
  }
}
