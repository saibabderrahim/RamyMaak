import { describe, expect, it } from 'vitest'
import type { ReasonCode } from '../../../shared/api/types'
import { canRequireComment, changesOf, CODE_PATTERN, createBody, isLastActive, nextSortOrder, TABS, typeOfTab } from './model'

const reason = (overrides: Partial<ReasonCode> = {}): ReasonCode => ({
  id: 1,
  type: 'REJECTION',
  code: 'QUOTA_EPUISE',
  label: 'Quota épuisé',
  requires_comment: false,
  sort_order: 10,
  is_active: true,
  created_at: '2026-10-01T08:00:00+00:00',
  updated_at: '2026-10-01T08:00:00+00:00',
  ...overrides,
})

describe('reason codes — rules of the screen (EF-ADM-05, I-82, I-95)', () => {
  it('accepts a code of 2 to 30 capitals, digits or underscores, starting with a letter', () => {
    for (const code of ['AB', 'QUOTA_EPUISE', 'A1_B2', 'A'.repeat(30)]) expect(CODE_PATTERN.test(code)).toBe(true)
    for (const code of ['A', 'a1', '1AB', '_AB', 'AB-C', 'AB C', 'É1', 'A'.repeat(31), '']) expect(CODE_PATTERN.test(code)).toBe(false)
  })

  it('maps the tabs to the four types, in order, with a French address', () => {
    expect(TABS).toEqual(['rejet', 'ajustement', 'ecart', 'annulation'])
    expect(TABS.map(typeOfTab)).toEqual(['REJECTION', 'ADJUSTMENT', 'SHIPPING_GAP', 'CANCELLATION'])
  })

  it('offers a mandatory comment for every type but the shipping gap (I-95)', () => {
    expect(canRequireComment('SHIPPING_GAP')).toBe(false)
    expect((['REJECTION', 'ADJUSTMENT', 'CANCELLATION'] as const).every(canRequireComment)).toBe(true)
  })

  it('proposes the next ten after the highest order of the type, 10 for the first', () => {
    expect(nextSortOrder([])).toBe(10)
    expect(nextSortOrder([{ sort_order: 10 }, { sort_order: 20 }, { sort_order: 40 }])).toBe(50)
    expect(nextSortOrder([{ sort_order: 25 }])).toBe(30)
    expect(nextSortOrder([{ sort_order: 0 }])).toBe(10)
    expect(nextSortOrder([{ sort_order: 65_535 }])).toBe(65_535)
  })

  it('finds the only active reason of a type, whatever the inactive ones', () => {
    const only = reason({ id: 1 })
    const gone = reason({ id: 2, is_active: false })
    expect(isLastActive([only, gone], only)).toBe(true)
    expect(isLastActive([only, reason({ id: 3 })], only)).toBe(false)
    expect(isLastActive([only, gone], gone)).toBe(false)
  })

  it('builds the creation body, without a comment rule for a shipping gap', () => {
    expect(createBody({ type: 'REJECTION', code: 'DOUBLON', label: 'Commande en double', requires_comment: true, sort_order: 30 })).toEqual({
      type: 'REJECTION',
      code: 'DOUBLON',
      label: 'Commande en double',
      requires_comment: true,
      sort_order: 30,
    })
    expect(createBody({ type: 'SHIPPING_GAP', code: 'CASSE', label: 'Casse', requires_comment: true, sort_order: 20 })).toEqual({
      type: 'SHIPPING_GAP',
      code: 'CASSE',
      label: 'Casse',
      sort_order: 20,
    })
  })

  it('sends only what changed, nothing when nothing changed, and never a comment rule for a shipping gap', () => {
    const current = reason()
    expect(changesOf(current, { label: 'Quota épuisé', requires_comment: false, sort_order: 10 })).toEqual({})
    expect(changesOf(current, { label: 'Quota atteint', requires_comment: false, sort_order: 10 })).toEqual({ label: 'Quota atteint' })
    expect(changesOf(current, { label: 'Quota épuisé', requires_comment: true, sort_order: 15 })).toEqual({ requires_comment: true, sort_order: 15 })
    expect(changesOf(reason({ type: 'SHIPPING_GAP' }), { label: 'Quota épuisé', requires_comment: true, sort_order: 10 })).toEqual({})
  })
})
