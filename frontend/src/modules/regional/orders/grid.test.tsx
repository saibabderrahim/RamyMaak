import { act, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { disconnectRealtime } from '../../../shared/realtime'
import { json } from '../../../test/utils'
import { GRID_EVENTS } from '../../distribution/api'
import { cellOf, DAY, findGrid, GRID_URL, makeGrid, regional, renderDistribution } from '../../distribution/testing'
import { ORDER_EVENTS } from './api'

// Temps réel simulé (même principe que shared/realtime/realtime.test.tsx).
const fake = vi.hoisted(() => ({
  stateChange: null as null | ((change: { current: string }) => void),
  listeners: new Map<string, Set<(payload: unknown) => void>>(),
}))

vi.mock('pusher-js', () => ({
  default: class {
    connection = { bind: (_: string, callback: (change: { current: string }) => void) => (fake.stateChange = callback) }
  },
}))

vi.mock('laravel-echo', () => ({
  default: class {
    private(channel: string) {
      return {
        listen: (event: string, listener: (payload: unknown) => void) => {
          const key = `${channel}:${event}`
          fake.listeners.set(key, (fake.listeners.get(key) ?? new Set()).add(listener))
        },
        stopListening: (event: string, listener: (payload: unknown) => void) => fake.listeners.get(`${channel}:${event}`)?.delete(listener),
      }
    }
    leave() {}
    disconnect() {}
  },
}))

afterEach(() => {
  disconnectRealtime()
  fake.stateChange = null
  fake.listeners.clear()
})

const CHANNEL = `region-day.3.${DAY}`

// Contrat du lot 5, §5 : la grille de répartition relit S sur les événements de commande de son canal.
describe('distribution grid — orders in real time', () => {
  it('listens to the order events of the region', () => {
    expect(GRID_EVENTS).toEqual(expect.arrayContaining([...ORDER_EVENTS]))
    expect(GRID_EVENTS).toEqual(expect.arrayContaining(['DistributionPublished', 'LimitDraftsUpdated', 'QuotaPublished']))
  })

  it('rereads the pending quantities (S) when a client submits, changes or cancels an order, or when orders expire', async () => {
    let pending = 10
    const { requests } = renderDistribution({
      user: regional({ id: 7, realtime: { key: 'app-key' } }),
      routes: {
        [GRID_URL]: () => {
          const grid = makeGrid()
          return json(makeGrid({ cells: { ...grid.cells, '12': { ...grid.cells['12'], '57': { limit: 5, draft: null, pending, validated: 0 } } } }))
        },
      },
    })
    await findGrid()
    for (const event of ORDER_EVENTS) {
      await waitFor(() => expect(fake.listeners.get(`${CHANNEL}:.${event}`)?.size).toBe(1))
    }
    act(() => fake.stateChange?.({ current: 'connected' }))
    expect(cellOf('C0012', 'Lben 1 L')).toHaveAccessibleName(/en attente 10/)

    const broadcast = (event: string, payload: unknown) => act(() => fake.listeners.get(`${CHANNEL}:.${event}`)?.forEach((listener) => listener(payload)))
    const order = { id: '01JA2Z6K8QW3X9M4T7RBN5CDEF', number: 'RMK-261008-00042', status: 'SUBMITTED', version: 1, day: DAY, client_id: 12 }

    pending = 14
    broadcast('OrderSubmitted', order)
    await waitFor(() => expect(cellOf('C0012', 'Lben 1 L')).toHaveAccessibleName(/en attente 14/))
    expect(requests(GRID_URL)).toHaveLength(2)

    broadcast('OrderUpdated', { ...order, version: 2 })
    await waitFor(() => expect(requests(GRID_URL)).toHaveLength(3))
    broadcast('OrderCancelled', { ...order, status: 'CANCELLED', version: 3 })
    await waitFor(() => expect(requests(GRID_URL)).toHaveLength(4))
    broadcast('OrdersExpired', { day: DAY, count: 2 })
    await waitFor(() => expect(requests(GRID_URL)).toHaveLength(5))
  })
})
