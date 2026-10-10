import { QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createQueryClient } from '../api/queryClient'
import { meQueryKey } from '../auth/session'
import { json, makeUser } from '../../test/utils'
import { disconnectRealtime, useLiveRefresh } from '.'

type Listener = (payload: unknown) => void

const fake = vi.hoisted(() => ({
  pusherOptions: [] as Array<Record<string, unknown>>,
  stateChange: null as null | ((change: { current: string }) => void),
  listeners: new Map<string, Set<(payload: unknown) => void>>(),
  left: [] as string[],
  disconnected: 0,
}))

vi.mock('pusher-js', () => ({
  default: class {
    connection = { bind: (_: string, callback: (change: { current: string }) => void) => (fake.stateChange = callback) }
    constructor(_key: string, options: Record<string, unknown>) {
      fake.pusherOptions.push(options)
    }
  },
}))

vi.mock('laravel-echo', () => ({
  default: class {
    private(channel: string) {
      return {
        listen: (event: string, listener: Listener) => {
          const key = `${channel}:${event}`
          fake.listeners.set(key, (fake.listeners.get(key) ?? new Set()).add(listener))
        },
        stopListening: (event: string, listener: Listener) => fake.listeners.get(`${channel}:${event}`)?.delete(listener),
      }
    }
    leave(channel: string) {
      fake.left.push(channel)
    }
    disconnect() {
      fake.disconnected++
    }
  },
}))

function emit(channel: string, event: string, payload: unknown = {}) {
  act(() => fake.listeners.get(`${channel}:.${event}`)?.forEach((listener) => listener(payload)))
}

function Probe({ channel }: { channel: string | null }) {
  const interval = useLiveRefresh(channel, ['QuotaPublished'], [['quotas']])
  return <output>{String(interval)}</output>
}

function renderProbe(realtime: { key: string } | null, channel: string | null = 'adv.2026-10-08') {
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, makeUser('adv', { id: 4, realtime }))
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries')
  const view = render(
    <QueryClientProvider client={queryClient}>
      <Probe channel={channel} />
    </QueryClientProvider>,
  )
  return { ...view, invalidate }
}

afterEach(() => {
  disconnectRealtime()
  fake.pusherOptions = []
  fake.stateChange = null
  fake.listeners.clear()
  fake.left = []
  fake.disconnected = 0
})

describe('useLiveRefresh', () => {
  it('falls back to a 30 s refresh without realtime, and never connects', () => {
    renderProbe(null)
    expect(screen.getByRole('status')).toHaveTextContent('30000')
    expect(fake.pusherOptions).toHaveLength(0)
  })

  it('connects to the same host, refreshes on events and stops polling once connected', async () => {
    const { invalidate, unmount } = renderProbe({ key: 'app-key' })
    await waitFor(() => expect(fake.listeners.get('adv.2026-10-08:.QuotaPublished')?.size).toBe(1))
    expect(fake.pusherOptions[0]).toMatchObject({ wsHost: 'localhost', enabledTransports: ['ws', 'wss'], forceTLS: false })
    expect(screen.getByRole('status')).toHaveTextContent('30000')

    act(() => fake.stateChange?.({ current: 'connected' }))
    expect(screen.getByRole('status')).toHaveTextContent('false')

    emit('adv.2026-10-08', 'QuotaPublished', { region_id: 3 })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['quotas'] })

    // Coupure puis retour : rafraîchissement de secours, puis relecture des données.
    act(() => fake.stateChange?.({ current: 'unavailable' }))
    expect(screen.getByRole('status')).toHaveTextContent('30000')
    invalidate.mockClear()
    act(() => fake.stateChange?.({ current: 'connected' }))
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['quotas'] })

    unmount()
    expect(fake.listeners.get('adv.2026-10-08:.QuotaPublished')?.size).toBe(0)
    expect(fake.left).toEqual(['adv.2026-10-08'])
  })

  it('authorizes private channels with the session cookie and the anti-CSRF token', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      if (String(input) === '/sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=token%3D1; path=/'
        return new Response(null, { status: 204 })
      }
      return json({ auth: 'app-key:signature' })
    })
    vi.stubGlobal('fetch', fetchMock)
    renderProbe({ key: 'app-key' })
    await waitFor(() => expect(fake.pusherOptions).toHaveLength(1))

    const options = fake.pusherOptions[0]
    if (options === undefined) throw new Error('Pusher non créé')
    const { customHandler } = options.channelAuthorization as {
      customHandler: (params: { socketId: string; channelName: string }, callback: (error: Error | null, data: unknown) => void) => void
    }
    const callback = vi.fn()
    customHandler({ socketId: '1.2', channelName: 'private-adv.2026-10-08' }, callback)

    await waitFor(() => expect(callback).toHaveBeenCalledWith(null, { auth: 'app-key:signature' }))
    const [url, init] = fetchMock.mock.calls[1] as unknown as [string, RequestInit]
    expect(url).toBe('/broadcasting/auth')
    expect(new Headers(init?.headers).get('X-XSRF-TOKEN')).toBe('token=1')
    expect(init?.credentials).toBe('include')
    expect(JSON.parse(String(init?.body))).toEqual({ socket_id: '1.2', channel_name: 'private-adv.2026-10-08' })
  })

  it('closes the connection on logout', async () => {
    renderProbe({ key: 'app-key' })
    await waitFor(() => expect(fake.pusherOptions).toHaveLength(1))
    disconnectRealtime()
    await waitFor(() => expect(fake.disconnected).toBe(1))
  })
})
