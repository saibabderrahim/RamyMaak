import { describe, expect, it } from 'vitest'
import {
  activeFilters,
  daysOf,
  hasShippingEdits,
  lineChanged,
  lineProblem,
  MAX_SELECTION,
  pageToggled,
  parseQuantity,
  planBulk,
  readQueueParams,
  type Selection,
  shippingFieldErrors,
  shippingLines,
  shippingProblems,
  tabById,
  toggled,
  writeQueueParams,
} from './model'
import { lben, orange, summary } from './testing'

const params = (query: string) => new URLSearchParams(query)

describe('queue address', () => {
  it('reads the tab and the filters, ignoring malformed values', () => {
    expect(readQueueParams(params(''))).toEqual({ tab: 'a-preparer', day: '', region: '', depot: '', product: '', sort: 'decided_at', page: 1 })
    expect(readQueueParams(params('onglet=preparees&jour=2026-10-08&region=3&depot=none&produit=42&tri=-number&page=2'))).toEqual({
      tab: 'preparees',
      day: '2026-10-08',
      region: '3',
      depot: 'none',
      product: '42',
      sort: '-number',
      page: 2,
    })
    expect(readQueueParams(params('onglet=autre&jour=demain&region=0&depot=x&produit=-4&tri=hack&page=-1'))).toEqual({
      tab: 'a-preparer',
      day: '',
      region: '',
      depot: '',
      product: '',
      sort: 'decided_at',
      page: 1,
    })
  })

  it('drops the default values and goes back to the first page unless the page itself changes', () => {
    const current = params('onglet=expediees&region=3&page=4')

    expect(writeQueueParams(current, { region: '' }).toString()).toBe('onglet=expediees')
    expect(writeQueueParams(current, { tab: 'a-preparer' }).toString()).toBe('region=3')
    expect(writeQueueParams(current, { page: 5 }).toString()).toBe('onglet=expediees&region=3&page=5')
    expect(writeQueueParams(current, { page: 1 }).toString()).toBe('onglet=expediees&region=3')
    expect(writeQueueParams(params(''), { day: '2026-10-08', depot: 'none', sort: 'number' }).toString()).toBe('jour=2026-10-08&depot=none&tri=number')
  })

  it('counts the active filters, the free search included', () => {
    expect(activeFilters(readQueueParams(params('')))).toBe(0)
    expect(activeFilters(readQueueParams(params('region=3&jour=2026-10-08')), '  ')).toBe(2)
    expect(activeFilters(readQueueParams(params('')), 'baraka')).toBe(1)
  })

  it('maps each tab to its status and to the next step of the bulk change', () => {
    expect(tabById('a-preparer')).toMatchObject({ status: 'VALIDATED', next: 'IN_PREPARATION' })
    expect(tabById('en-preparation')).toMatchObject({ status: 'IN_PREPARATION', next: 'PREPARED' })
    expect(tabById('preparees')).toMatchObject({ status: 'PREPARED', next: 'SHIPPED' })
    expect(tabById('expediees').next).toBeNull()
    expect(tabById('annulees').next).toBeNull()
  })

  it('lists the displayed days once, in order', () => {
    expect(daysOf([{ day: '2026-10-08' }, { day: '2026-10-07' }, { day: '2026-10-08' }])).toEqual(['2026-10-07', '2026-10-08'])
  })
})

describe('selection', () => {
  const rows = Array.from({ length: 120 }, (_, index) => summary({ id: `ID${index}`, number: `RMK-${index}` }))

  it('toggles an order and stops at 100', () => {
    let selection: Selection = new Map()
    for (const row of rows) selection = toggled(selection, row)
    expect(selection.size).toBe(MAX_SELECTION)
    expect(selection.has('ID100')).toBe(false)

    expect(toggled(selection, rows[0]!).size).toBe(MAX_SELECTION - 1)
  })

  it('selects the whole page up to the limit, and clears it when it was already selected', () => {
    const page = rows.slice(0, 50)
    const selected = pageToggled(new Map(), page)
    expect(selected.size).toBe(50)
    expect(pageToggled(selected, page).size).toBe(0)

    const second = pageToggled(selected, rows.slice(50, 120))
    expect(second.size).toBe(MAX_SELECTION)
  })
})

describe('bulk plan', () => {
  it('sends only the orders that may take the step, with the latest version read', () => {
    const stale = summary({ version: 3 })
    const frozen = summary({ id: 'B', number: 'RMK-B', next_statuses: [] })
    const moved = summary({ id: 'C', number: 'RMK-C', status: 'IN_PREPARATION', next_statuses: ['PREPARED'] })
    const selection: Selection = new Map([stale, frozen, moved].map((row) => [row.id, row]))

    const plan = planBulk(selection, [{ ...stale, version: 4 }, moved], 'IN_PREPARATION')

    expect(plan.items).toEqual([{ id: stale.id, version: 4 }])
    expect(plan.skipped).toEqual(['RMK-B', 'RMK-C'])
  })

  it('falls back on the snapshot of an order that is not on the displayed page', () => {
    const away = summary({ id: 'A', version: 9 })
    expect(planBulk(new Map([['A', away]]), [], 'IN_PREPARATION').items).toEqual([{ id: 'A', version: 9 }])
  })
})

describe('shipping', () => {
  const line42 = orange() // validé 40, expédié non saisi
  const line57 = lben({ shipped_qty: 1000 }) // validé 1000

  it('reads whole quantities between 0 and 1 000 000', () => {
    expect(parseQuantity('12')).toBe(12)
    expect(parseQuantity(' 0 ')).toBe(0)
    expect(parseQuantity('1000000')).toBe(1_000_000)
    for (const text of ['', '1,5', '-1', 'abc', '1000001', '12345678']) expect(parseQuantity(text)).toBeNull()
  })

  it('shows the validated quantity until a quantity is entered (I-77)', () => {
    expect(lineChanged(line42, undefined)).toBe(false)
    expect(lineChanged(line42, { qty: '40' })).toBe(false)
    expect(lineChanged(line42, { qty: '30' })).toBe(true)
    expect(lineChanged(line42, { qty: '' })).toBe(true)
  })

  it('requires a reason if and only if the shipped quantity is below the validated one', () => {
    expect(lineProblem(line42, { qty: '30' })).toBe('reason_required')
    expect(lineProblem(line42, { qty: '30', reason: 'RUPTURE' })).toBeNull()
    expect(lineProblem(line42, { qty: '40', reason: 'RUPTURE' })).toBe('reason_prohibited')
    expect(lineProblem(line42, { qty: '41' })).toBe('qty_over')
    expect(lineProblem(line42, { qty: 'x' })).toBe('qty_invalid')
    expect(lineProblem(line42, { qty: '0', reason: 'CASSE' })).toBeNull()
  })

  it('keeps the reason already saved when only the quantity moves', () => {
    const gap = lben({ shipped_qty: 900, shipping_gap_reason: { code: 'RUPTURE', label: 'Rupture de stock' } })
    expect(lineProblem(gap, { qty: '800' })).toBeNull()
    expect(lineProblem(gap, { qty: '1000' })).toBe('reason_prohibited')
    expect(lineProblem(gap, { qty: '1000', reason: '' })).toBeNull()
  })

  it('sends only the lines that differ, a reason only below the validated quantity', () => {
    const lines = [line42, line57]
    const edits = { 42: { qty: '30', reason: 'RUPTURE' }, 57: { qty: '1000' } }

    expect(shippingLines(lines, edits)).toEqual([{ product_id: 42, shipped_qty: 30, reason_code: 'RUPTURE' }])
    expect(shippingLines(lines, { 42: { qty: '40' } })).toEqual([])
    // Une ligne revenue à la validée, qui avait un écart enregistré, part sans motif.
    const gap = lben({ shipped_qty: 900, shipping_gap_reason: { code: 'RUPTURE', label: 'Rupture de stock' } })
    expect(shippingLines([gap], { 57: { qty: '1000', reason: '' } })).toEqual([{ product_id: 57, shipped_qty: 1000 }])
  })

  it('reports the problems of the changed lines only', () => {
    expect(shippingProblems([line42, line57], { 42: { qty: '30' }, 57: { qty: '1000' } })).toEqual({ 42: 'reason_required' })
    expect(hasShippingEdits([line42, line57], { 42: { qty: '40' } })).toBe(false)
    expect(hasShippingEdits([line42, line57], { 42: { qty: '39', reason: 'CASSE' } })).toBe(true)
  })

  it('maps the server errors of the submitted lines back to their products', () => {
    const error = Object.assign(new Error('x'), { fieldErrors: { 'lines.1.shipped_qty': ['Trop.'], 'lines.0.reason_code': ['Motif inconnu.'], delivery_note_ref: ['Trop long.'] } })
    expect(shippingFieldErrors(error, [{ product_id: 42 }, { product_id: 57 }])).toEqual({ 57: 'Trop.', 42: 'Motif inconnu.', note: 'Trop long.' })
    expect(shippingFieldErrors(new Error('network'), [])).toEqual({})
  })
})
