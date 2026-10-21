import { describe, expect, it } from 'vitest'
import { historyEvent, ORDER_TABS, ordersOf, orderPath, refreshInterval, regionParam, submissionTime, tabOf } from './model'
import { ordersPage, SECOND, summary } from './testing'

describe('regional orders — model', () => {
  it('maps the tabs to the statuses read and to the counts of the list', () => {
    expect(ORDER_TABS.map((tab) => [tab.slug, tab.key, tab.statuses.join(',')])).toEqual([
      ['a-valider', 'SUBMITTED', 'SUBMITTED'],
      ['validees', 'VALIDATED', 'VALIDATED,IN_PREPARATION,PREPARED,SHIPPED,DELIVERED'],
      ['rejetees', 'REJECTED', 'REJECTED'],
      ['annulees', 'CLOSED', 'CANCELLED,EXPIRED'],
    ])
    expect(tabOf('rejetees').key).toBe('REJECTED')
    expect(tabOf(null).key).toBe('SUBMITTED')
    expect(tabOf('SUBMITTED').key).toBe('SUBMITTED')
  })

  it('reads the region of the address', () => {
    expect(regionParam('4')).toBe(4)
    expect(regionParam(null)).toBeNull()
    expect(regionParam('')).toBeNull()
    expect(regionParam('0')).toBeNull()
    expect(regionParam('3.5')).toBeNull()
    expect(regionParam('abc')).toBeNull()
  })

  it('keeps the order of the server and shows a repeated order once, with its last state', () => {
    const changed = { ...SECOND, status: 'CANCELLED' as const }
    const third = summary({ id: '01JA2Z6K8QW3X9M4T7RBN5CDEH', number: 'RMK-261008-00044' })
    const orders = ordersOf([ordersPage([summary(), SECOND]), ordersPage([changed, third], { page: 2 })])
    expect(orders.map((order) => order.number)).toEqual(['RMK-261008-00042', 'RMK-261008-00043', 'RMK-261008-00044'])
    expect(orders[1]?.status).toBe('CANCELLED')
    expect(ordersOf([])).toEqual([])
  })

  it('shows the time of submission in the business time zone, with the day when it differs from the day of the order', () => {
    expect(submissionTime('2026-10-08T08:40:00+00:00', '2026-10-08')).toBe('09:40')
    // 23:30 UTC le 07/10 = 00:30 à Alger le 08/10.
    expect(submissionTime('2026-10-07T23:30:00+00:00', '2026-10-08')).toBe('00:30')
    expect(submissionTime('2026-10-08T15:05:00+00:00', '2026-10-09')).toBe('08/10 16:05')
  })

  it('names the events of the history', () => {
    expect(historyEvent({ from: null, to: 'SUBMITTED' })).toBe('created')
    expect(historyEvent({ from: 'SUBMITTED', to: 'SUBMITTED' })).toBe('modified')
    expect(historyEvent({ from: 'SUBMITTED', to: 'CANCELLED' })).toBe('CANCELLED')
  })

  it('refreshes at the shortest interval of the regions, every 30 s before any subscription', () => {
    expect(refreshInterval(new Map())).toBe(30_000)
    expect(refreshInterval(new Map([[3, false]]))).toBe(false)
    expect(refreshInterval(new Map<number, number | false>([[3, false], [4, 30_000]]))).toBe(30_000)
  })

  it('links to the detail of an order', () => {
    expect(orderPath('01JA2Z6K8QW3X9M4T7RBN5CDEF')).toBe('/regional/commandes/01JA2Z6K8QW3X9M4T7RBN5CDEF')
  })
})
