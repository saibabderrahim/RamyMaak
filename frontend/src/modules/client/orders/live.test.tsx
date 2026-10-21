import { act, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { purgeSessionStorage } from '../../../shared/storage'
import { disconnectRealtime, NOTIFICATION_EVENT } from '../../../shared/realtime'
import { json } from '../../../test/utils'
import { CLIENT_ID, clientUser, closedOrder, LIST_URL, makeLine, makeOrder, makePage, makeSummary, ORDER_ID, ORDER_URL, orangeLine, ordersApi, renderOrders, USER_ID } from './testing'

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

  it('shows the decision, then the shipping, as the order moves on (OrderStatusChanged: validated with adjustment, then shipped)', async () => {
    let order = makeOrder()
    ordersApi({ [ORDER_URL]: () => json(order) }, live)
    renderOrders(`/client/commandes/${ORDER_ID}`, undefined, live)
    await screen.findByRole('region', { name: 'Commande du jeudi 08/10/2026' })
    await waitFor(() => expect(fake.listeners.get(`client.${CLIENT_ID}:.OrderStatusChanged`)?.size).toBeGreaterThan(0))
    expect(screen.queryByRole('region', { name: 'Validée avec ajustement' })).not.toBeInTheDocument()

    order = closedOrder({
      status: 'VALIDATED',
      status_label: 'Validée',
      version: 2,
      is_adjusted: true,
      decided_at: '2026-10-08T10:15:00+00:00',
      lines: [makeLine({ ...orangeLine, validated_qty: 30, adjustment_reason_code: 'QUOTA_INSUFFISANT', adjustment_reason: { code: 'QUOTA_INSUFFISANT', label: 'Quota insuffisant' } })],
      history: [
        { from: null, to: 'SUBMITTED', version: 1, at: '2026-10-08T08:40:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0012', comment: null },
        { from: 'SUBMITTED', to: 'VALIDATED', version: 2, at: '2026-10-08T10:15:00+00:00', actor: 'REGIONAL', by_name: null, comment: null },
      ],
    })
    broadcast('OrderStatusChanged', { id: ORDER_ID, number: order.number, status: 'VALIDATED', version: 2, day: order.day })
    expect(await screen.findByRole('region', { name: 'Validée avec ajustement' })).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Expédition' })).not.toBeInTheDocument()

    order = {
      ...order,
      status: 'SHIPPED',
      status_label: 'Expédiée',
      version: 5,
      shipped_at: '2026-10-10T07:30:00+00:00',
      delivery_note_ref: 'BL-0042',
      lines: [makeLine({ ...orangeLine, validated_qty: 30, shipped_qty: 30, adjustment_reason_code: 'QUOTA_INSUFFISANT', adjustment_reason: { code: 'QUOTA_INSUFFISANT', label: 'Quota insuffisant' } })],
    }
    broadcast('OrderStatusChanged', { id: ORDER_ID, number: order.number, status: 'SHIPPED', version: 5, day: order.day })

    const shipping = await screen.findByRole('region', { name: 'Expédition' })
    expect(shipping).toHaveTextContent('Bon de livraisonBL-0042')
    expect(screen.getByRole('article', { name: 'Jus orange 1 L' })).toHaveTextContent('Expédiée30')
  })

  it('reloads the client data when the ordering cutoff is extended (notification on user.{id}, RG-A05)', async () => {
    let order = closedOrder({ status: 'SUBMITTED' })
    const api = ordersApi({ [ORDER_URL]: () => json(order) }, live)
    renderOrders(`/client/commandes/${ORDER_ID}`, undefined, live)
    const overview = await screen.findByRole('region', { name: 'Commande du jeudi 08/10/2026' })
    expect(within(overview).queryByRole('button', { name: 'Annuler la commande' })).not.toBeInTheDocument()
    const notifications = `user.${USER_ID}:.${NOTIFICATION_EVENT}`
    await waitFor(() => expect(fake.listeners.get(notifications)?.size).toBeGreaterThan(0))
    const reads = api.requests(`GET /api/v1/client/orders/${ORDER_ID}`).length

    // Une autre notification ne relit rien.
    act(() => fake.listeners.get(notifications)?.forEach((listener) => listener({ type: 'ORDER_EXPIRED' })))
    order = makeOrder()
    act(() => fake.listeners.get(notifications)?.forEach((listener) => listener({ type: 'CUTOFF_EXTENDED' })))

    await waitFor(() => expect(within(overview).getByRole('button', { name: 'Annuler la commande' })).toBeInTheDocument())
    expect(api.requests(`GET /api/v1/client/orders/${ORDER_ID}`).length).toBe(reads + 1)
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
