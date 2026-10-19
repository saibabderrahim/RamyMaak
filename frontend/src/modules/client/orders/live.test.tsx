import { act, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { purgeSessionStorage } from '../../../shared/storage'
import { disconnectRealtime } from '../../../shared/realtime'
import { json } from '../../../test/utils'
import { CLIENT_ID, clientUser, closedOrder, LIST_URL, makeOrder, makePage, makeSummary, ORDER_ID, ORDER_URL, ordersApi, renderOrders } from './testing'

// Temps réel simulé (même principe que shared/realtime/realtime.test.tsx).
const fake = vi.hoisted(() => ({ listeners: new Map<string, Set<(payload: unknown) => void>>() }))

vi.mock('pusher-js', () => ({
  default: class {
    connection = { bind: () => undefined }
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
  fake.listeners.clear()
  purgeSessionStorage()
})

const live = clientUser({ realtime: { key: 'reverb-key' } })
const broadcast = (event: string, payload: unknown) =>
  act(() => fake.listeners.get(`client.${CLIENT_ID}:.${event}`)?.forEach((listener) => listener(payload)))

describe('my orders, live (client.{id})', () => {
  it('reloads the detail when the order changes status (expiry at the closing, decisions in lot 6)', async () => {
    let order = makeOrder()
    ordersApi({ [ORDER_URL]: () => json(order) }, live)
    renderOrders(`/client/commandes/${ORDER_ID}`, undefined, live)
    const overview = await screen.findByRole('region', { name: 'Commande du jeudi 08/10/2026' })
    expect(within(overview).getByRole('button', { name: 'Annuler la commande' })).toBeInTheDocument()
    await waitFor(() => expect(fake.listeners.get(`client.${CLIENT_ID}:.OrderStatusChanged`)?.size).toBeGreaterThan(0))

    order = closedOrder({ status: 'EXPIRED', version: 2 })
    broadcast('OrderStatusChanged', { id: ORDER_ID, number: order.number, status: 'EXPIRED', version: 2, day: order.day })

    await waitFor(() => expect(within(overview).getByText('Expirée')).toBeInTheDocument())
    expect(within(overview).queryByRole('button', { name: 'Annuler la commande' })).not.toBeInTheDocument()
  })

  it('reloads the list on a new order sent from another account of the client', async () => {
    let orders = [makeSummary()]
    ordersApi({ [LIST_URL]: () => json(makePage(orders)) }, live)
    renderOrders('/client/commandes', undefined, live)
    await screen.findByRole('link', { name: /RMK-261008-00042/ })
    await waitFor(() => expect(fake.listeners.get(`client.${CLIENT_ID}:.OrderSubmitted`)?.size).toBeGreaterThan(0))

    orders = [makeSummary({ id: '01JA2Z6K8QW3X9M4T7RBN5CDEZ', number: 'RMK-261008-00051' }), ...orders]
    broadcast('OrderSubmitted', { id: '01JA2Z6K8QW3X9M4T7RBN5CDEZ', number: 'RMK-261008-00051', status: 'SUBMITTED', version: 1, day: '2026-10-08' })

    expect(await screen.findByRole('link', { name: /RMK-261008-00051/ })).toBeInTheDocument()
  })
})
