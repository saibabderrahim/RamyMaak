import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { disconnectRealtime } from '../../shared/realtime'
import { json } from '../../test/utils'
import { cellOf, DAY, findGrid, GRID_URL, makeGrid, regional, renderDistribution, summaryOf } from './testing'

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
  vi.useRealTimers()
  disconnectRealtime()
  fake.stateChange = null
  fake.listeners.clear()
})

const CHANNEL = `region-day.3.${DAY}`
const broadcast = (event: string, payload: unknown) => act(() => fake.listeners.get(`${CHANNEL}:.${event}`)?.forEach((listener) => listener(payload)))

describe('distribution — realtime', () => {
  it('rereads the grid on the events of the region, never overwriting an edit waiting to be saved', async () => {
    let sumLimits = 400
    const { requests } = renderDistribution({
      user: regional({ id: 7, realtime: { key: 'app-key' } }),
      routes: {
        // Un autre régional a saisi une borne pour C0051 / Lben.
        [GRID_URL]: () => json(makeGrid({ cells: { ...makeGrid().cells, '51': { '42': { limit: 400, draft: null, pending: 0, validated: 0 }, '57': { limit: sumLimits, draft: null, pending: 0, validated: 0 } } } })),
      },
    })
    await findGrid()
    for (const event of ['DistributionPublished', 'LimitDraftsUpdated', 'QuotaPublished']) {
      await waitFor(() => expect(fake.listeners.get(`${CHANNEL}:.${event}`)?.size).toBe(1))
    }
    act(() => fake.stateChange?.({ current: 'connected' }))

    // Saisie en attente d'enregistrement (délai de 600 ms).
    const user = userEvent.setup()
    await user.click(cellOf('C0012', 'Lben 1 L'))
    await user.keyboard('33{Enter}')

    sumLimits = 450
    broadcast('LimitDraftsUpdated', { region_id: 3, day: DAY })
    await waitFor(() => expect(requests(GRID_URL)).toHaveLength(2))
    await waitFor(() => expect(cellOf('C0051', 'Lben 1 L')).toHaveTextContent(/^450$/))
    expect(cellOf('C0012', 'Lben 1 L')).toHaveTextContent(/^33/)
    // ΣB : 33 (saisie) + 80 + 450.
    expect(summaryOf('Σ bornes (ΣB)', 'Lben 1 L')).toHaveTextContent(/^563$/)

    broadcast('DistributionPublished', { region_id: 3, day: DAY, version: 2, first: false })
    await waitFor(() => expect(requests(GRID_URL)).toHaveLength(3))
  })

  it('shows the grid as soon as the ADV publishes the quotas of the region', async () => {
    let published = false
    const waiting = makeGrid({
      region: { ...makeGrid().region, status: 'QUOTA_DRAFT', distribution_version: 0, distribution_published_at: null },
      products: [],
      cells: {},
      can_edit: false,
      can_publish: false,
    })
    renderDistribution({ user: regional({ id: 7, realtime: { key: 'app-key' } }), routes: { [GRID_URL]: () => json(published ? makeGrid() : waiting) } })
    expect(await screen.findByText('En attente des quotas de l’ADV')).toBeInTheDocument()
    await waitFor(() => expect(fake.listeners.get(`${CHANNEL}:.QuotaPublished`)?.size).toBe(1))

    published = true
    broadcast('QuotaPublished', { region_id: 3, day: DAY, version: 1, first: true })
    expect(await findGrid()).toBeInTheDocument()
  })

  it('refreshes every 30 s without realtime', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderDistribution()
    await findGrid()
    await act(() => vi.advanceTimersByTimeAsync(30_000))
    await waitFor(() => expect(requests(GRID_URL)).toHaveLength(2))
  })
})
