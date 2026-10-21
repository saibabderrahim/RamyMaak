import { describe, expect, it } from 'vitest'
import { cutoffParts, distributionAction, ordersPath, ordersToValidatePath, quotasPath, withoutOrderGroups } from './model'
import { client, dashboard, region } from './testing'

describe('« Ma journée » links', () => {
  it('builds the list of the orders to validate of a region, and the other shortcuts, for the day shown', () => {
    expect(ordersToValidatePath('2026-10-08', 3)).toBe('/regional/commandes?day=2026-10-08&onglet=a-valider&region=3')
    expect(ordersPath('2026-10-09')).toBe('/regional/commandes?day=2026-10-09')
    expect(quotasPath('2026-10-09')).toBe('/regional/quotas?day=2026-10-09')
  })
})

describe('cutoffs of a region', () => {
  it('lists the three cutoffs with the extension of each, the validation « bloquante » when the day says so', () => {
    expect(cutoffParts(region(), false)).toEqual([
      { kind: 'distribution', time: '10:00', notes: [] },
      { kind: 'ordering', time: '13:30', notes: ['extended'] },
      { kind: 'validation', time: '15:00', notes: [] },
    ])
    const parts = cutoffParts(region({ extended: { distribution: true, ordering: false, validation: true } }), true)
    expect(parts.map((part) => part.notes)).toEqual([['extended'], [], ['extended', 'blocking']])
  })

  it('only marks the validation cutoff as blocking', () => {
    expect(cutoffParts(region({ extended: { distribution: false, ordering: false, validation: false } }), true).map((part) => part.notes)).toEqual([[], [], ['blocking']])
  })
})

describe('distribution shortcut', () => {
  it('offers to distribute once the quotas are published and nothing was sent, to view it afterwards or when the day is closed', () => {
    expect(distributionAction(region({ quota_published_at: null, distribution_published_at: null, status: 'QUOTA_DRAFT' }))).toBeNull()
    expect(distributionAction(region({ distribution_published_at: null, status: 'QUOTA_PUBLISHED' }))).toBe('distribute')
    expect(distributionAction(region({ distribution_published_at: null, status: 'DISTRIBUTION_DRAFT' }))).toBe('distribute')
    expect(distributionAction(region())).toBe('view')
    expect(distributionAction(region({ distribution_published_at: null, status: 'CLOSED' }))).toBe('view')
  })
})

describe('clients without order', () => {
  it('groups them by region in the order of the regions, keeping the regions which have none', () => {
    const est = region({ region: { id: 4, code: 'EST', name: 'Est' }, distribution_published_at: null })
    const groups = withoutOrderGroups(
      dashboard({
        regions: [region(), est],
        clients_without_order: [client({ id: 12, region_id: 3 }), client({ id: 13, region_id: 3 }), client({ id: 80, region_id: 9 })],
      }),
    )
    expect(groups.map((group) => [group.region.region.code, group.sent, group.clients.map((entry) => entry.id)])).toEqual([
      ['CENTRE', true, [12, 13]],
      ['EST', false, []],
    ])
  })
})
