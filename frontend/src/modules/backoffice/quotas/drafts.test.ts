import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, NetworkError } from '../../../shared/api/client'
import type { QuotaCellInput, QuotaMatrix, SaveQuotasResult } from '../../../shared/api/types'
import { formatQuantity } from '../../../shared/format'
import { mergeSavedCells } from './api'
import { type CellChange, DraftQueue } from './drafts'
import { groupByFamily, isBlock, parseClipboard, parseQuantity, serverValue } from './matrix'

const valid = (productId: number, regionId: number, value: number): CellChange => ({
  productId,
  regionId,
  edit: { value, text: String(value), error: null },
})

const saved = (cells: QuotaCellInput[]): SaveQuotasResult => ({
  changed: cells.length,
  cells: cells.map((cell) => ({ ...cell, quantity: 0, draft: cell.quantity, validated: 0 })),
})

describe('quota matrix — values and parsing', () => {
  it('displays the draft, else the published quota, else 0', () => {
    expect(serverValue({ quantity: 500, draft: 450, validated: 0 })).toBe(450)
    expect(serverValue({ quantity: 500, draft: null, validated: 0 })).toBe(500)
    expect(serverValue({ quantity: 500, draft: 0, validated: 0 })).toBe(0)
    expect(serverValue(undefined)).toBe(0)
  })

  it('accepts integers from 0 to 1 000 000 with spaces as thousands separators', () => {
    expect(parseQuantity('450')).toBe(450)
    expect(parseQuantity(' 1 200 ')).toBe(1200)
    expect(parseQuantity('1 200')).toBe(1200)
    expect(parseQuantity('1 000 000')).toBe(1_000_000)
    expect(parseQuantity('')).toBe(0)
    expect(parseQuantity('0')).toBe(0)
    expect(parseQuantity('12,5')).toBeNull()
    expect(parseQuantity('12.5')).toBeNull()
    expect(parseQuantity('-3')).toBeNull()
    expect(parseQuantity('abc')).toBeNull()
    expect(parseQuantity('1000001')).toBeNull()
    expect(formatQuantity(1200)).toBe('1 200')
  })

  it('reads a block copied from Excel', () => {
    expect(parseClipboard('1 200\t300\r\n40\t5\r\n')).toEqual([
      ['1 200', '300'],
      ['40', '5'],
    ])
    expect(parseClipboard('7')).toEqual([['7']])
    expect(parseClipboard('\t8\n')).toEqual([['', '8']])
    expect(isBlock('12\n')).toBe(false)
    expect(isBlock('12\t13')).toBe(true)
    expect(isBlock('12\n13')).toBe(true)
  })

  it('groups consecutive products by family', () => {
    const family = (id: number) => ({ id, code: `F${id}`, name: `Famille ${id}` })
    const product = (id: number, familyId: number) => ({
      id,
      sku: `P${id}`,
      name: `Produit ${id}`,
      short_name: `P ${id}`,
      family: family(familyId),
      sales_unit_label: 'Fardeau',
      is_active: true,
    })
    const groups = groupByFamily([product(1, 2), product(2, 2), product(3, 5)])
    expect(groups.map((group) => [group.family.code, group.rows])).toEqual([
      ['F2', [0, 1]],
      ['F5', [2]],
    ])
  })

  it('merges saved cells into the matrix and follows the pending drafts of each region', () => {
    const matrix = {
      day: {},
      regions: [
        { id: 3, code: 'CENTRE', name: 'Centre', status: 'QUOTA_PUBLISHED', quota_version: 1, pending_drafts: 1 },
        { id: 4, code: 'EST', name: 'Est', status: 'QUOTA_DRAFT', quota_version: 0, pending_drafts: 0 },
      ],
      products: [],
      cells: { '42': { '3': { quantity: 500, draft: 450, validated: 0 } }, '43': { '3': { quantity: 10, draft: null, validated: 0 } } },
    } as unknown as QuotaMatrix

    const merged = mergeSavedCells(matrix, [
      { product_id: 42, region_id: 3, quantity: 500, draft: null, validated: 0 },
      { product_id: 42, region_id: 4, quantity: 0, draft: 80, validated: 0 },
    ])

    expect(merged.cells['42']).toEqual({ '3': { quantity: 500, draft: null, validated: 0 }, '4': { quantity: 0, draft: 80, validated: 0 } })
    // Ligne non touchée : même référence (ligne mémorisée non redessinée).
    expect(merged.cells['43']).toBe(matrix.cells['43'])
    expect(merged.regions.map((region) => region.pending_drafts)).toEqual([0, 1])
    expect(matrix.cells['42']!['3']!.draft).toBe(450)
  })
})

describe('quota matrix — draft queue', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  function setup(send: (cells: QuotaCellInput[]) => Promise<SaveQuotasResult>, maxBatch?: number) {
    const onSaved = vi.fn()
    const onClosed = vi.fn()
    const sendMock = vi.fn(send)
    const queue = new DraftQueue({ send: sendMock, onSaved, onClosed, ...(maxBatch ? { maxBatch } : {}) })
    return { queue, send: sendMock, onSaved, onClosed }
  }

  it('waits for a pause, then sends the latest value of each edited cell in one batch', async () => {
    const { queue, send, onSaved } = setup(async (cells) => saved(cells))

    queue.edit([valid(42, 3, 10)])
    await vi.advanceTimersByTimeAsync(400)
    queue.edit([valid(42, 3, 12), valid(43, 3, 5)])
    await vi.advanceTimersByTimeAsync(400)
    expect(send).not.toHaveBeenCalled()
    expect(queue.getSnapshot()).toMatchObject({ status: 'pending', unsaved: 2 })

    await vi.advanceTimersByTimeAsync(200)
    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0]![0]).toEqual([
      { product_id: 42, region_id: 3, quantity: 12 },
      { product_id: 43, region_id: 3, quantity: 5 },
    ])
    expect(onSaved).toHaveBeenCalledTimes(1)
    // Accusé de réception : les saisies s'effacent derrière les données du serveur.
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', unsaved: 0 })
    expect(queue.getSnapshot().edits.size).toBe(0)
    expect(queue.getSnapshot().savedAt).toBeInstanceOf(Date)
  })

  it('keeps one request in flight and splits large pastes into batches of 2 000 cells', async () => {
    const pending: Array<() => void> = []
    const { queue, send } = setup(
      (cells) =>
        new Promise((resolve) => {
          pending.push(() => resolve(saved(cells)))
        }),
    )

    queue.edit(Array.from({ length: 2_500 }, (_, index) => valid(1_000 + index, 3, index)))
    await vi.advanceTimersByTimeAsync(600)
    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0]![0]).toHaveLength(2_000)
    expect(queue.getSnapshot()).toMatchObject({ status: 'saving', unsaved: 2_500 })

    // Saisie pendant l'envoi : affichée, mais pas de seconde requête simultanée.
    queue.edit([valid(42, 3, 7)])
    await vi.advanceTimersByTimeAsync(600)
    expect(send).toHaveBeenCalledTimes(1)
    expect(queue.getSnapshot().edits.get(42)?.[3]?.value).toBe(7)

    pending.shift()!()
    await vi.advanceTimersByTimeAsync(0)
    expect(send).toHaveBeenCalledTimes(2)
    expect(send.mock.calls[1]![0]).toHaveLength(501)

    pending.shift()!()
    await vi.advanceTimersByTimeAsync(0)
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', unsaved: 0 })
  })

  it('keeps a cell edited again while its previous value was being saved', async () => {
    let release: () => void = () => {}
    const { queue, send } = setup(
      (cells) =>
        new Promise((resolve) => {
          release = () => resolve(saved(cells))
        }),
    )

    queue.edit([valid(42, 3, 10)])
    await vi.advanceTimersByTimeAsync(600)
    queue.edit([valid(42, 3, 11)])
    release()
    await vi.advanceTimersByTimeAsync(0)
    expect(queue.getSnapshot().edits.get(42)?.[3]?.value).toBe(11)

    await vi.advanceTimersByTimeAsync(600)
    expect(send).toHaveBeenCalledTimes(2)
    expect(send.mock.calls[1]![0]).toEqual([{ product_id: 42, region_id: 3, quantity: 11 }])
  })

  it('never sends a cell rejected on input', async () => {
    const { queue, send } = setup(async (cells) => saved(cells))

    queue.edit([{ productId: 42, regionId: 3, edit: { value: null, text: '12,5', error: 'Nombre entier attendu.' } }, valid(43, 3, 4)])
    await vi.advanceTimersByTimeAsync(600)

    expect(send.mock.calls[0]![0]).toEqual([{ product_id: 43, region_id: 3, quantity: 4 }])
    expect(queue.getSnapshot()).toMatchObject({ invalid: 1, unsaved: 0 })

    queue.revert(42, 3)
    expect(queue.getSnapshot()).toMatchObject({ invalid: 0 })
    expect(queue.getSnapshot().edits.size).toBe(0)
  })

  it('flags the cells refused by the server (422) and sends the others again', async () => {
    let attempts = 0
    const { queue, send } = setup(async (cells) => {
      if (++attempts === 1) {
        throw new ApiError({
          status: 422,
          code: 'VALIDATION_FAILED',
          detail: 'Région hors de votre périmètre.',
          errors: { 'cells.1.region_id': ['Région hors de votre périmètre.'], 'cells.1.product_id': ['Produit inconnu ou inactif.'] },
        })
      }
      return saved(cells)
    })

    queue.edit([valid(42, 3, 10), valid(42, 9, 20)])
    await vi.advanceTimersByTimeAsync(600)

    expect(send).toHaveBeenCalledTimes(2)
    expect(send.mock.calls[1]![0]).toEqual([{ product_id: 42, region_id: 3, quantity: 10 }])
    expect(queue.getSnapshot().edits.get(42)?.[9]).toEqual({ value: 20, text: '20', error: 'Région hors de votre périmètre.' })
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', invalid: 1, unsaved: 0 })
  })

  it('keeps the batch after a network failure and sends it again on retry', async () => {
    let attempts = 0
    const { queue, send } = setup(async (cells) => {
      if (++attempts === 1) throw new NetworkError()
      return saved(cells)
    })

    queue.edit([valid(42, 3, 10)])
    await vi.advanceTimersByTimeAsync(600)
    expect(queue.getSnapshot()).toMatchObject({ status: 'error', unsaved: 1 })
    expect(queue.getSnapshot().error).toBeInstanceOf(NetworkError)
    await expect(queue.flushAll()).resolves.toBeUndefined()

    expect(send).toHaveBeenCalledTimes(2)
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', unsaved: 0 })
  })

  it('stops everything when the day is closed (409 DAY_NOT_OPEN)', async () => {
    const { queue, onClosed } = setup(async () => {
      throw new ApiError({ status: 409, code: 'DAY_NOT_OPEN', detail: 'La journée est clôturée.' })
    })

    queue.edit([valid(42, 3, 10)])
    await expect(queue.flushAll()).rejects.toBeInstanceOf(ApiError)

    expect(onClosed).toHaveBeenCalledTimes(1)
    expect(queue.getSnapshot()).toMatchObject({ closed: true, unsaved: 0 })
    expect(queue.getSnapshot().edits.size).toBe(0)
    queue.edit([valid(43, 3, 1)])
    expect(queue.getSnapshot().edits.size).toBe(0)
  })

  it('sends waiting edits right away when the screen is left', async () => {
    const { queue, send } = setup(async (cells) => saved(cells))
    queue.edit([valid(42, 3, 10)])
    queue.dispose()
    expect(send).toHaveBeenCalledTimes(1)
  })

  it('sends a batch put back after a failed save when the screen is left', async () => {
    let attempts = 0
    const { queue, send } = setup(async (cells) => {
      if (++attempts === 1) throw new ApiError({ status: 503, code: 'SERVICE_UNAVAILABLE', detail: 'Service indisponible.' })
      return saved(cells)
    })

    queue.edit([valid(42, 3, 10), valid(43, 4, 5)])
    await vi.advanceTimersByTimeAsync(600)
    // Échec : le lot attend « Réessayer », sans délai programmé.
    expect(queue.getSnapshot()).toMatchObject({ status: 'error', unsaved: 2 })

    // Le serveur répond de nouveau ; l'écran est quitté sans réessayer.
    queue.dispose()
    expect(send).toHaveBeenCalledTimes(2)
    expect(send.mock.calls[1]![0]).toEqual([
      { product_id: 42, region_id: 3, quantity: 10 },
      { product_id: 43, region_id: 4, quantity: 5 },
    ])
    await vi.advanceTimersByTimeAsync(0)
    expect(queue.getSnapshot()).toMatchObject({ status: 'saved', unsaved: 0 })
  })

  it('sends nothing when the screen is left with nothing waiting', async () => {
    const { queue, send } = setup(async (cells) => saved(cells))
    queue.dispose()
    queue.edit([valid(42, 3, 10)])
    await vi.advanceTimersByTimeAsync(600)
    queue.dispose()
    expect(send).toHaveBeenCalledTimes(1)
  })
})
