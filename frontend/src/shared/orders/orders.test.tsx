import { QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import type { ReactElement } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { json, makeUser, mockApi, renderApp } from '../../test/utils'
import { createQueryClient } from '../api/queryClient'
import type { CurrentUser } from '../api/types'
import { meQueryKey } from '../auth/session'
import { businessToday, nextDay } from '../format'
import { disconnectRealtime, FALLBACK_REFRESH_MS } from '../realtime'
import { LIVE_COALESCE_MS, useCoalescedRefresh } from './liveRefresh'
import { useAdvToPrepareCount } from './useAdvToPrepareCount'

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
  vi.restoreAllMocks()
})

const COUNTS = (validated: number) => ({ VALIDATED: validated, IN_PREPARATION: 4, PREPARED: 0, SHIPPED: 1, CANCELLED: 0 })
const queue = (validated: number) => json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 1, total: validated, from: null, to: null, counts: COUNTS(validated) } })

const emit = (event: string, channel = `adv.${businessToday()}`) =>
  act(() => fake.listeners.get(`${channel}:.${event}`)?.forEach((listener) => listener({ day: businessToday() })))

function Count({ enabled }: { enabled: boolean }) {
  return <output data-testid="count">{useAdvToPrepareCount(enabled)}</output>
}

function Interval() {
  const interval = useCoalescedRefresh(`adv.${businessToday()}`, ['OrderValidated'], [['probe']])
  return <output data-testid="interval">{String(interval)}</output>
}

function renderProbe(element: ReactElement, user: CurrentUser) {
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, user)
  return render(<QueryClientProvider client={queryClient}>{element}</QueryClientProvider>)
}

const adv = (overrides: Partial<CurrentUser> = {}) => makeUser('adv', { permissions: ['order.view', 'order.process'], ...overrides })

describe('ADV menu counter (N-08, I-80)', () => {
  it('reads the validated orders of the file, one row asked', async () => {
    const { calls } = mockApi({ 'GET /api/v1/adv/orders': () => queue(7) })
    renderProbe(<Count enabled />, adv())

    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('7'))
    expect(calls.filter((call) => call.path === '/api/v1/adv/orders')).toHaveLength(1)
  })

  it('asks for the Validated status alone and one row', async () => {
    const { fetchMock } = mockApi({ 'GET /api/v1/adv/orders': () => queue(2) })
    renderProbe(<Count enabled />, adv())

    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('2'))
    const url = new URL(String(fetchMock.mock.calls.find(([input]) => String(input).includes('/adv/orders'))?.[0]), 'http://localhost')
    expect(Object.fromEntries(url.searchParams)).toEqual({ 'filter[status]': 'VALIDATED', per_page: '1' })
  })

  it('reads and listens to nothing when the account does not read orders', async () => {
    const { calls } = mockApi({})
    renderProbe(<Count enabled={false} />, adv({ realtime: { key: 'app-key' } }))

    expect(screen.getByTestId('count')).toHaveTextContent('0')
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(calls).toHaveLength(0)
    expect(fake.listeners.size).toBe(0)
  })

  it('shows 0 while the counter is not read, or when the read fails', async () => {
    mockApi({ 'GET /api/v1/adv/orders': () => json({ code: 'FORBIDDEN', detail: 'Refusé.' }, 403) })
    renderProbe(<Count enabled />, adv())

    expect(screen.getByTestId('count')).toHaveTextContent('0')
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(screen.getByTestId('count')).toHaveTextContent('0')
  })

  it('follows the validations, the processing and the ADV cancellations of today', async () => {
    let validated = 3
    const { calls } = mockApi({ 'GET /api/v1/adv/orders': () => queue(validated) })
    renderProbe(<Count enabled />, adv({ realtime: { key: 'app-key' } }))
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('3'))
    for (const event of ['OrderValidated', 'OrdersProcessed', 'OrderCancelledByAdv']) {
      await waitFor(() => expect(fake.listeners.get(`adv.${businessToday()}:.${event}`)?.size).toBe(1))
    }

    validated = 4
    emit('OrderValidated')
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('4'))

    validated = 1
    emit('OrdersProcessed')
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('1'))

    validated = 0
    emit('OrderCancelledByAdv')
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('0'))
    expect(calls.filter((call) => call.path === '/api/v1/adv/orders')).toHaveLength(4)
  })
})

describe('ADV « to prepare » counter — other days', () => {
  it('also follows tomorrow, whose orders can be validated today (I-42)', async () => {
    let validated = 2
    mockApi({ 'GET /api/v1/adv/orders': () => queue(validated) })
    renderProbe(<Count enabled />, adv({ realtime: { key: 'app-key' } }))
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('2'))
    const tomorrow = nextDay(businessToday())
    await waitFor(() => expect(fake.listeners.get(`adv.${tomorrow}:.OrderValidated`)?.size).toBe(1))

    validated = 3
    emit('OrderValidated', `adv.${tomorrow}`)
    await waitFor(() => expect(screen.getByTestId('count')).toHaveTextContent('3'))
  })
})

describe('coalesced live refresh', () => {
  it('rereads once for a burst of events, then again for the next one', async () => {
    const { calls } = mockApi({ 'GET /api/v1/adv/orders': () => queue(1) })
    renderProbe(<Count enabled />, adv({ realtime: { key: 'app-key' } }))
    await waitFor(() => expect(calls.filter((call) => call.path === '/api/v1/adv/orders')).toHaveLength(1))
    await waitFor(() => expect(fake.listeners.get(`adv.${businessToday()}:.OrderValidated`)?.size).toBe(1))

    for (let index = 0; index < 20; index++) emit('OrderValidated')
    await waitFor(() => expect(calls.filter((call) => call.path === '/api/v1/adv/orders')).toHaveLength(2))
    await new Promise((resolve) => setTimeout(resolve, LIVE_COALESCE_MS + 200))
    expect(calls.filter((call) => call.path === '/api/v1/adv/orders')).toHaveLength(2)

    emit('OrderValidated')
    await waitFor(() => expect(calls.filter((call) => call.path === '/api/v1/adv/orders')).toHaveLength(3))
  })

  it('polls every 30 s unless the connection is established', async () => {
    mockApi({})
    renderProbe(<Interval />, adv({ realtime: { key: 'app-key' } }))
    await waitFor(() => expect(fake.stateChange).not.toBeNull())

    expect(screen.getByTestId('interval')).toHaveTextContent(String(FALLBACK_REFRESH_MS))
    act(() => fake.stateChange?.({ current: 'connected' }))
    await waitFor(() => expect(screen.getByTestId('interval')).toHaveTextContent('false'))
    act(() => fake.stateChange?.({ current: 'unavailable' }))
    await waitFor(() => expect(screen.getByTestId('interval')).toHaveTextContent(String(FALLBACK_REFRESH_MS)))
  })

  it('catches up at once when the connection comes back', async () => {
    const { calls } = mockApi({ 'GET /api/v1/adv/orders': () => queue(1) })
    renderProbe(<Count enabled />, adv({ realtime: { key: 'app-key' } }))
    await waitFor(() => expect(fake.stateChange).not.toBeNull())
    act(() => fake.stateChange?.({ current: 'connected' }))
    await waitFor(() => expect(calls.filter((call) => call.path === '/api/v1/adv/orders')).toHaveLength(1))

    act(() => fake.stateChange?.({ current: 'unavailable' }))
    act(() => fake.stateChange?.({ current: 'connected' }))

    await waitFor(() => expect(calls.filter((call) => call.path === '/api/v1/adv/orders')).toHaveLength(2))
  })
})

describe('AppShell — « Commandes » menu of the ADV', () => {
  it('shows the number of orders to prepare on the menu entry', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(adv()),
      'GET /api/v1/adv/orders': () => queue(12),
      'GET /api/v1/notifications': () =>
        json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, unread_count: 0 } }),
    })
    renderApp('/profil')

    const link = await screen.findByRole('link', { name: 'Commandes (12)' })
    expect(link).toHaveAttribute('href', '/adv/commandes')
    expect(link).toHaveTextContent('12')
  })

  it('shows no counter at zero', async () => {
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(adv()),
      'GET /api/v1/adv/orders': () => queue(0),
      'GET /api/v1/notifications': () =>
        json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, unread_count: 0 } }),
    })
    renderApp('/profil')
    await waitFor(() => expect(calls.some((call) => call.path === '/api/v1/adv/orders')).toBe(true))
    expect(await screen.findByRole('link', { name: 'Commandes' })).toHaveAttribute('href', '/adv/commandes')
    expect(screen.queryByRole('link', { name: /Commandes \(/ })).not.toBeInTheDocument()
  })

  it('asks nothing of the queue without order.view, and nothing for the Admin', async () => {
    for (const user of [adv({ permissions: [] }), makeUser('admin', { permissions: ['order.view'] })]) {
      const { calls } = mockApi({
        'GET /api/v1/auth/me': () => json(user),
        'GET /api/v1/notifications': () =>
          json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, unread_count: 0 } }),
      })
      const { unmount } = renderApp('/profil')
      await screen.findByRole('link', { name: 'Commandes' })
      expect(calls.some((call) => call.path === '/api/v1/adv/orders')).toBe(false)
      unmount()
    }
  })
})
