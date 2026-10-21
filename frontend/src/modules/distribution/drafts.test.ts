import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, NetworkError } from '../../shared/api/client'
import type { LimitCellInput, SaveLimitsResult } from '../../shared/api/types'
import { browserDraftStorage, DraftQueue, type DraftQueueOptions, type DraftStorage, draftStorageKey } from './drafts'
import type { CellChange } from './model'

const change = (clientId: number, productId: number, value: number | null, error: string | null = null): CellChange => ({
  clientId,
  productId,
  edit: { value, text: value === null ? '' : String(value), error },
})

const saved = (cells: LimitCellInput[]): SaveLimitsResult => ({
  changed: cells.length,
  status: 'DISTRIBUTION_DRAFT',
  cells: cells.map((cell) => ({ ...cell, limit: 0, draft: { value: cell.limit }, pending: 0, validated: 0 })),
})

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

/** Stockage en mémoire : contenu courant et nombre d'écritures. */
function memoryStorage(initial: LimitCellInput[] = []) {
  const state = { cells: initial, writes: 0 }
  const storage: DraftStorage = {
    load: () => state.cells,
    save: (cells) => {
      state.cells = [...cells]
      state.writes++
    },
  }
  return { storage, state }
}

/** Corps d'un lot tel qu'envoyé par saveLimits. */
const bodyBytes = (cells: LimitCellInput[]) => JSON.stringify({ region_id: 3, cells }).length

const many = (from: number, count: number) => Array.from({ length: count }, (_, index) => change(from + index, 42, 7))

function makeQueue(options: Partial<DraftQueueOptions> = {}) {
  const sent: LimitCellInput[][] = []
  const onSaved = vi.fn()
  const onClosed = vi.fn()
  const queue = new DraftQueue({
    send: async (cells) => {
      sent.push(cells)
      return saved(cells)
    },
    onSaved,
    onClosed,
    ...options,
  })
  return { queue, sent, onSaved, onClosed }
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('distribution — draft queue', () => {
  it('batches the edits after the delay, keeps the last value per cell and sends « no limit » as null', async () => {
    const { queue, sent, onSaved } = makeQueue()
    queue.edit([change(12, 42, 50)])
    queue.edit([change(47, 42, null), change(12, 42, 60)])
    // Saisie refusée sur place : jamais envoyée.
    queue.edit([change(51, 42, null, 'Borne trop grande')])
    expect(queue.getSnapshot()).toMatchObject({ status: 'pending', unsaved: 2, invalid: 1 })

    await vi.advanceTimersByTimeAsync(599)
    expect(sent).toHaveLength(0)
    await vi.advanceTimersByTimeAsync(1)
    expect(sent).toEqual([
      [
        { client_id: 47, product_id: 42, limit: null },
        { client_id: 12, product_id: 42, limit: 60 },
      ],
    ])
    expect(onSaved).toHaveBeenCalledTimes(1)
    // Accusé de réception : les saisies s'effacent derrière la valeur du serveur.
    expect(queue.getSnapshot().edits.get(12)).toBeUndefined()
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', unsaved: 0, invalid: 1 })
  })

  it('sends at most maxBatch cells per request, one request at a time', async () => {
    const pending = [deferred<SaveLimitsResult>(), deferred<SaveLimitsResult>()]
    const sent: LimitCellInput[][] = []
    const { queue } = makeQueue({
      maxBatch: 2,
      send: (cells) => {
        sent.push(cells)
        return pending[sent.length - 1]!.promise
      },
    })
    queue.edit([change(1, 42, 1), change(2, 42, 2), change(3, 42, 3)])
    await vi.advanceTimersByTimeAsync(600)
    expect(sent).toHaveLength(1)
    expect(sent[0]).toHaveLength(2)

    // Ressaisie pendant l'envoi : la saisie reste affichée jusqu'à son propre envoi.
    queue.edit([change(1, 42, 10)])
    await vi.advanceTimersByTimeAsync(600)
    expect(sent).toHaveLength(1)

    pending[0]!.resolve(saved(sent[0]!))
    await vi.advanceTimersByTimeAsync(0)
    expect(sent).toHaveLength(2)
    expect(sent[1]).toEqual([
      { client_id: 3, product_id: 42, limit: 3 },
      { client_id: 1, product_id: 42, limit: 10 },
    ])
    expect(queue.getSnapshot().edits.get(1)?.[42]?.value).toBe(10)
  })

  it('maps a 422 to the offending cells and resends the others', async () => {
    let calls = 0
    const sent: LimitCellInput[][] = []
    const { queue } = makeQueue({
      send: async (cells) => {
        sent.push(cells)
        calls++
        if (calls === 1) {
          throw new ApiError({
            status: 422,
            code: 'VALIDATION_FAILED',
            errors: { 'cells.1.limit': ['La borne ne peut pas dépasser le quota de la région (500).'] },
          })
        }
        return saved(cells)
      },
    })
    queue.edit([change(12, 42, 50), change(47, 42, 600)])
    await vi.advanceTimersByTimeAsync(600)
    await vi.advanceTimersByTimeAsync(0)

    expect(sent).toHaveLength(2)
    expect(sent[1]).toEqual([{ client_id: 12, product_id: 42, limit: 50 }])
    expect(queue.getSnapshot().edits.get(47)?.[42]).toEqual({ value: 600, text: '600', error: 'La borne ne peut pas dépasser le quota de la région (500).' })
    expect(queue.getSnapshot().invalid).toBe(1)

    // Échap : retour à la valeur du serveur.
    queue.revert(47, 42)
    expect(queue.getSnapshot().edits.get(47)).toBeUndefined()
  })

  it('keeps the batch for a retry after a network failure', async () => {
    let fail = true
    const sent: LimitCellInput[][] = []
    const { queue } = makeQueue({
      send: async (cells) => {
        sent.push(cells)
        if (fail) throw new NetworkError()
        return saved(cells)
      },
    })
    queue.edit([change(12, 42, 50)])
    await vi.advanceTimersByTimeAsync(600)
    expect(queue.getSnapshot()).toMatchObject({ status: 'error', unsaved: 1 })
    await expect(queue.flushAll()).rejects.toBeInstanceOf(NetworkError)

    fail = false
    queue.retry()
    await vi.advanceTimersByTimeAsync(0)
    expect(sent).toHaveLength(3)
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', unsaved: 0 })
  })

  it('stops everything when the day is closed (409 DAY_NOT_OPEN)', async () => {
    const { queue, onClosed } = makeQueue({
      send: async () => {
        throw new ApiError({ status: 409, code: 'DAY_NOT_OPEN', detail: 'Journée clôturée.' })
      },
    })
    queue.edit([change(12, 42, 50)])
    await vi.advanceTimersByTimeAsync(600)
    expect(onClosed).toHaveBeenCalledTimes(1)
    expect(queue.getSnapshot()).toMatchObject({ closed: true, unsaved: 0 })
    expect(queue.getSnapshot().edits.size).toBe(0)
    queue.edit([change(12, 42, 60)])
    expect(queue.getSnapshot().edits.size).toBe(0)
  })

  it('flushes everything before a send, and right away when the page is hidden', async () => {
    const { queue, sent } = makeQueue()
    queue.edit([change(12, 42, 50)])
    await queue.flushAll()
    expect(sent).toHaveLength(1)

    queue.edit([change(12, 42, 70)])
    queue.flushNow()
    expect(sent).toHaveLength(2)
  })

  it('sends the rest at once when the page is discarded while a request is in flight', async () => {
    const first = deferred<SaveLimitsResult>()
    const sent: LimitCellInput[][] = []
    const { queue } = makeQueue({
      send: (cells) => {
        sent.push(cells)
        return sent.length === 1 ? first.promise : Promise.resolve(saved(cells))
      },
    })
    queue.edit([change(12, 42, 50)])
    queue.flushNow()
    expect(sent).toHaveLength(1)

    // Onglet masqué : la suite part dès la réponse, sans délai.
    queue.edit([change(47, 42, 20)])
    queue.flushNow()
    expect(sent).toHaveLength(1)
    // Page détruite : plus de réponse à attendre, le reste part aussitôt.
    queue.flushNow(true)
    expect(sent).toHaveLength(2)
    expect(sent[1]).toEqual([{ client_id: 47, product_id: 42, limit: 20 }])
  })

  it('derives its status from what really waits: an entry refused on the spot sends nothing', async () => {
    const { queue, sent } = makeQueue()
    queue.edit([change(51, 42, null, 'Borne trop grande')])
    expect(queue.getSnapshot()).toMatchObject({ status: 'idle', unsaved: 0, invalid: 1 })
    queue.revert(51, 42)
    expect(queue.getSnapshot()).toMatchObject({ status: 'idle', invalid: 0 })

    queue.edit([change(12, 42, 50)])
    expect(queue.getSnapshot().status).toBe('pending')
    await vi.advanceTimersByTimeAsync(600)
    expect(queue.getSnapshot().status).toBe('saved')
    // Après un enregistrement, une saisie refusée laisse « Enregistré à … ».
    queue.edit([change(47, 42, 1, 'Borne inférieure au validé')])
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', invalid: 1 })
    queue.revert(47, 42)
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', invalid: 0 })
    await vi.advanceTimersByTimeAsync(600)
    expect(sent).toHaveLength(1)
  })

  it('keeps the edits without acknowledgement in the storage until they are saved', async () => {
    let fail = true
    const { storage, state } = memoryStorage()
    const { queue } = makeQueue({
      storage,
      send: async (cells) => {
        if (fail) throw new NetworkError()
        return saved(cells)
      },
    })
    queue.edit([change(12, 42, 50), change(47, 42, null)])
    expect(state.cells).toEqual([
      { client_id: 12, product_id: 42, limit: 50 },
      { client_id: 47, product_id: 42, limit: null },
    ])
    // Échec réseau : toujours gardées ; ressaisie : la dernière valeur l'emporte.
    await vi.advanceTimersByTimeAsync(600)
    expect(queue.getSnapshot().status).toBe('error')
    queue.edit([change(12, 42, 55)])
    expect(state.cells).toEqual([
      { client_id: 47, product_id: 42, limit: null },
      { client_id: 12, product_id: 42, limit: 55 },
    ])

    fail = false
    queue.retry()
    await vi.advanceTimersByTimeAsync(0)
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', unsaved: 0 })
    expect(state.cells).toEqual([])
  })

  it('drops from the storage the cells refused by the server (422), which stay in error on screen', async () => {
    let calls = 0
    const { storage, state } = memoryStorage()
    const { queue } = makeQueue({
      storage,
      send: async (cells) => {
        calls++
        if (calls === 1) throw new ApiError({ status: 422, code: 'VALIDATION_FAILED', errors: { 'cells.1.client_id': ['Client hors de la région ce jour-là.'] } })
        if (calls === 2) throw new NetworkError()
        return saved(cells)
      },
    })
    queue.edit([change(12, 42, 50), change(99, 42, 10)])
    await vi.advanceTimersByTimeAsync(600)
    // Refusée : en erreur à l'écran, plus dans le stockage ; l'autre est renvoyée (et échoue).
    expect(queue.getSnapshot().edits.get(99)?.[42]?.error).toBe('Client hors de la région ce jour-là.')
    expect(queue.getSnapshot().status).toBe('error')
    expect(state.cells).toEqual([{ client_id: 12, product_id: 42, limit: 50 }])

    // Refus sans cellule désignée : tout le lot est refusé, rien n'est gardé pour un nouvel essai.
    const { storage: other, state: otherState } = memoryStorage()
    const { queue: second, sent } = makeQueue({
      storage: other,
      send: async () => {
        throw new ApiError({ status: 422, code: 'VALIDATION_FAILED', detail: 'Les données envoyées sont invalides.', errors: { region_id: ['Région invalide.'] } })
      },
    })
    second.edit([change(12, 42, 50)])
    await vi.advanceTimersByTimeAsync(600)
    expect(second.getSnapshot()).toMatchObject({ status: 'idle', unsaved: 0, invalid: 1 })
    expect(second.getSnapshot().edits.get(12)?.[42]?.error).toBe('Les données envoyées sont invalides.')
    expect(otherState.cells).toEqual([])
    await vi.advanceTimersByTimeAsync(600)
    expect(sent).toHaveLength(0)
  })

  it('restores the stored edits, sends them at once and rewrites the storage', async () => {
    const { storage, state } = memoryStorage([
      { client_id: 12, product_id: 42, limit: 50 },
      { client_id: 47, product_id: 42, limit: 900 },
      { client_id: 99, product_id: 42, limit: 1 },
    ])
    const { queue, sent } = makeQueue({ storage })
    queue.restore(({ client_id, product_id, limit }) => {
      if (client_id === 99) return null
      return limit === 900 ? change(client_id, product_id, null, 'Borne trop grande') : change(client_id, product_id, limit)
    })
    // Envoyée sans attendre le délai ; seule la borne valide compte comme renvoyée.
    expect(sent).toEqual([[{ client_id: 12, product_id: 42, limit: 50 }]])
    expect(queue.getSnapshot()).toMatchObject({ restored: 1, invalid: 1 })
    expect(state.cells).toEqual([{ client_id: 12, product_id: 42, limit: 50 }])
    await vi.advanceTimersByTimeAsync(0)
    expect(state.cells).toEqual([])

    // Rien à reprendre : l'entrée du stockage disparaît.
    const { storage: stale, state: staleState } = memoryStorage([{ client_id: 12, product_id: 42, limit: 50 }])
    const { queue: second } = makeQueue({ storage: stale })
    second.restore(() => null)
    expect(staleState).toEqual({ cells: [], writes: 1 })
    expect(second.getSnapshot().restored).toBe(0)
  })

  it('stops writing the storage once the screen is left, the next screen taking it over', async () => {
    const first = deferred<SaveLimitsResult>()
    const { storage, state } = memoryStorage()
    const { queue } = makeQueue({ storage, send: () => first.promise })
    queue.attach()
    queue.edit([change(12, 42, 50)])
    queue.dispose()
    expect(state.cells).toEqual([{ client_id: 12, product_id: 42, limit: 50 }])
    const writes = state.writes
    first.resolve(saved([{ client_id: 12, product_id: 42, limit: 50 }]))
    await vi.advanceTimersByTimeAsync(0)
    expect(queue.getSnapshot().unsaved).toBe(0)
    expect(state.writes).toBe(writes)
  })

  it('sends a big paste at once, beyond what a keepalive request can carry', () => {
    const sent: LimitCellInput[][] = []
    const { queue } = makeQueue({
      send: (cells) => {
        sent.push(cells)
        return new Promise(() => undefined)
      },
    })
    queue.edit(many(1, 100))
    expect(sent).toHaveLength(0)
    queue.edit(many(1_000, 3_000))
    expect(sent).toHaveLength(1)
    expect(sent[0]).toHaveLength(2_000)
    expect(queue.getSnapshot()).toMatchObject({ status: 'saving', unsaved: 3_100 })
  })

  it('fits what is sent on unload in the keepalive budget, most recent edits first, the rest staying stored', () => {
    const sent: LimitCellInput[][] = []
    const { storage, state } = memoryStorage()
    const { queue } = makeQueue({
      storage,
      send: (cells) => {
        sent.push(cells)
        return new Promise(() => undefined)
      },
    })
    // Lot en cours trop gros pour keepalive (il sera annulé) : tout le budget reste disponible.
    queue.edit(many(1, 5_000))
    expect(sent).toHaveLength(1)
    queue.flushNow(true)
    const unload = sent.slice(1)
    const cells = unload.flat()
    expect(unload.length).toBeGreaterThan(0)
    for (const batch of unload) expect(bodyBytes(batch)).toBeLessThanOrEqual(60_000)
    expect(unload.reduce((sum, batch) => sum + bodyBytes(batch), 0)).toBeLessThanOrEqual(60_000)
    expect(cells.length).toBeGreaterThan(1_000)
    // Les plus récentes : la dernière saisie part, les plus anciennes de la file attendent.
    expect(cells.some((cell) => cell.client_id === 5_000)).toBe(true)
    expect(cells.some((cell) => cell.client_id === 2_001)).toBe(false)
    // Sans accusé de réception lisible, tout reste dans le stockage pour la prochaine ouverture.
    expect(state.cells).toHaveLength(5_000)

    // Lot en cours assez petit pour keepalive : il entame le budget.
    const small: LimitCellInput[][] = []
    const { queue: second } = makeQueue({
      send: (batch) => {
        small.push(batch)
        return new Promise(() => undefined)
      },
    })
    second.edit(many(1, 1_000))
    second.flushNow()
    second.edit(many(2_000, 1_000))
    second.flushNow(true)
    expect(small.length).toBeGreaterThan(1)
    expect(small.reduce((sum, batch) => sum + bodyBytes(batch), 0)).toBeLessThanOrEqual(60_000)
    expect(small.slice(1).flat().some((cell) => cell.client_id === 2_999)).toBe(true)
  })
})

describe('distribution — draft storage', () => {
  afterEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('keeps the cells per user, day and region, and removes the entry when nothing is left', () => {
    const key = draftStorageKey(7, '2026-10-08', 3)
    expect(key).toBe('ramymaak:limit-drafts:7:2026-10-08:3')
    const storage = browserDraftStorage(key)
    expect(storage.load()).toEqual([])
    storage.save([{ client_id: 12, product_id: 42, limit: null }])
    expect(JSON.parse(localStorage.getItem(key)!)).toEqual([{ client_id: 12, product_id: 42, limit: null }])
    expect(storage.load()).toEqual([{ client_id: 12, product_id: 42, limit: null }])
    storage.save([])
    expect(localStorage.getItem(key)).toBeNull()
  })

  it('ignores malformed content and an unavailable storage', () => {
    const key = draftStorageKey(7, '2026-10-08', 3)
    localStorage.setItem(key, JSON.stringify([{ client_id: 12, product_id: 42, limit: 5, extra: true }, { client_id: 'x' }, { client_id: 1, product_id: 2, limit: -1 }]))
    expect(browserDraftStorage(key).load()).toEqual([{ client_id: 12, product_id: 42, limit: 5 }])
    localStorage.setItem(key, '{oops')
    expect(browserDraftStorage(key).load()).toEqual([])

    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('Accès refusé', 'SecurityError')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('Plein', 'QuotaExceededError')
    })
    const storage = browserDraftStorage(key)
    expect(storage.load()).toEqual([])
    expect(() => storage.save([{ client_id: 12, product_id: 42, limit: 5 }])).not.toThrow()
  })
})
