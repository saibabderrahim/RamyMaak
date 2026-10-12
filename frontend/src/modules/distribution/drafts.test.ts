import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, NetworkError } from '../../shared/api/client'
import type { LimitCellInput, SaveLimitsResult } from '../../shared/api/types'
import { DraftQueue, type DraftQueueOptions } from './drafts'
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
})
