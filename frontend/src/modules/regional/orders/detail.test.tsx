import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { disconnectRealtime } from '../../../shared/realtime'
import { json, problem } from '../../../test/utils'
import { DAY, makeOrder, ORDER_ID, orderUrl, ORDERS_URL, ordersPage, regional, renderOrders, summary } from './testing'

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

const DETAIL = `/regional/commandes/${ORDER_ID}`
const where = () => screen.getByTestId('where').textContent
const findTitle = () => screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })
/** Ligne de la commande : l'élément de liste qui porte le contexte du produit. */
const lineOf = (name: string) => screen.getByRole('list', { name: `Quantités de « ${name} »` }).closest('li')!
const contextOf = (name: string) => within(screen.getByRole('list', { name: `Quantités de « ${name} »` })).getAllByRole('listitem').map((item) => item.textContent)

describe('regional order — detail', () => {
  it('shows the order, its client, its lines with their context, the totals and the history', async () => {
    renderOrders({ path: DETAIL })
    await findTitle()

    expect(screen.getByText('Envoyée', { selector: 'span' })).toBeInTheDocument()
    expect(screen.getByText('Journée du jeudi 08/10/2026')).toBeInTheDocument()
    expect(screen.getByText('Envoyée le 08/10/2026 09:40 par Gérant C0012')).toBeInTheDocument()

    // Client : appel en un geste sur chacun de ses numéros, région de la commande.
    const client = screen.getByRole('heading', { name: 'Client' }).closest('section')!
    expect(within(client).getByText('Superette El Baraka')).toBeInTheDocument()
    expect(within(client).getByText('C0012 · Région Centre (CENTRE)')).toBeInTheDocument()
    expect(within(client).queryByText('Bloqué')).not.toBeInTheDocument()
    expect(within(client).getByRole('link', { name: 'Appeler Superette El Baraka au 0550 12 34 56' })).toHaveAttribute('href', 'tel:0550123456')
    expect(within(client).getByRole('link', { name: 'Appeler Superette El Baraka au 0770112233' })).toHaveAttribute('href', 'tel:0770112233')
    expect(within(client).getByRole('link', { name: 'Voir la fiche du client' })).toHaveAttribute('href', '/regional/clients/12')

    const totals = screen.getByRole('heading', { name: 'Montants' }).closest('section')!
    expect(totals).toHaveTextContent('Total HT58 500,00 DA')
    expect(totals).toHaveTextContent('TVA11 115,00 DA')
    expect(totals).toHaveTextContent('Total TTC69 615,00 DA')
    expect(within(totals).getByText('Montants indicatifs : la facture fait foi.')).toBeInTheDocument()

    const comment = screen.getByRole('heading', { name: 'Commentaire du client' }).closest('section')!
    expect(comment).toHaveTextContent('Livraison avant 10 h')

    // Lignes : quantité demandée, contexte publié, prix figé et montant HT.
    const lines = within(screen.getByRole('list', { name: 'Produits commandés' })).getAllByRole('listitem').filter((item) => item.parentElement?.getAttribute('aria-label') === 'Produits commandés')
    expect(lines).toHaveLength(2)
    const orange = lineOf('Jus orange 1 L')
    expect(within(orange).getByRole('img', { name: 'Jus orange 1 L' })).toHaveAttribute('src', '/media/42/thumb.webp')
    expect(orange).toHaveTextContent('P0042 · Fardeau 6 × 1 L · 900,00 DA HT l’unité de vente')
    expect(orange).toHaveTextContent('Montant HT : 36 000,00 DA')
    expect(within(orange).getByText('Demandé : 40')).toBeInTheDocument()
    expect(contextOf('Jus orange 1 L')).toEqual(['Borne : 50', 'Déjà validé pour ce client : 0', 'Quota région : 1 500', 'Reste région : 1 500'])

    const lben = lineOf('Lben 1 L')
    expect(within(lben).getByRole('img', { name: 'Lben 1 L' })).toBeInTheDocument()
    expect(within(lben).getByText('Demandé : 1 250')).toBeInTheDocument()
    expect(contextOf('Lben 1 L')).toEqual(['Pas de borne', 'Déjà validé pour ce client : 30', 'Quota région : 800', 'Reste région : 720'])

    // Historique, auteurs nommés.
    const history = within(screen.getByRole('list', { name: 'Historique' })).getAllByRole('listitem')
    expect(history.map((entry) => entry.textContent)).toEqual([
      'Commande envoyée08/10/2026 09:40 · par Gérant C0012 (client)',
      'Commande modifiée (version 2)08/10/2026 10:12 · par Gérant C0012 (client)',
    ])

    // Sans droit de décision (can_validate et can_reject faux) : lecture seule, ni panneau de décision ni action.
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Décision sur la commande' })).not.toBeInTheDocument()
  })

  it('offers the decision on the lines instead of the read-only lines when the regional may validate (lot 6)', async () => {
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(makeOrder({ can_validate: true, can_reject: true })) } })
    await findTitle()

    expect(await screen.findByRole('group', { name: 'Décision sur la commande' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Valider' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Rejeter' })).toBeInTheDocument()
    // Les lignes à décider portent le même contexte publié que les lignes en lecture seule.
    expect(screen.getAllByRole('article').map((line) => line.getAttribute('aria-label'))).toEqual(['Jus orange 1 L', 'Lben 1 L'])
    expect(contextOf('Jus orange 1 L').map((fact) => fact?.replace(/\u00a0/g, ' '))).toEqual(
      expect.arrayContaining(['Borne : 50', 'Déjà validé pour ce client : 0', 'Reste région : 1 500']),
    )
    // Client et historique restent affichés.
    expect(screen.getByRole('link', { name: 'Voir la fiche du client' })).toBeInTheDocument()
    expect(screen.getByRole('list', { name: 'Historique' })).toBeInTheDocument()
  })

  it('stays read-only once the order is decided, even when the regional could decide', async () => {
    renderOrders({
      path: DETAIL,
      routes: { [orderUrl(ORDER_ID)]: () => json(makeOrder({ status: 'VALIDATED', status_label: 'Validée', can_validate: true, can_reject: true })) },
    })
    await findTitle()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Décision sur la commande' })).not.toBeInTheDocument()
  })

  it('shows a blocked client, a client without phone, the validated quantities and an automatic expiry', async () => {
    const order = makeOrder({
      status: 'EXPIRED',
      status_label: 'Expirée',
      comment: null,
      client: { id: 47, code: 'C0047', name: 'Grossiste Benali', status: 'BLOCKED', phone: null, phone2: ' ' },
      lines: [{ ...makeOrder().lines[0]!, validated_qty: 30, shipped_qty: 28 }],
      history: [
        { from: null, to: 'SUBMITTED', version: 1, at: '2026-10-08T08:40:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0047', comment: null },
        { from: 'SUBMITTED', to: 'EXPIRED', version: 2, at: '2026-10-08T22:00:00+00:00', actor: 'SYSTEM', by_name: null, comment: null },
      ],
    })
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(order) } })
    await findTitle()

    const client = screen.getByRole('heading', { name: 'Client' }).closest('section')!
    expect(within(client).getByText('Bloqué')).toBeInTheDocument()
    expect(within(client).getByText('Aucun téléphone renseigné.')).toBeInTheDocument()
    expect(within(client).queryByRole('link', { name: /Appeler/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Commentaire du client' })).not.toBeInTheDocument()
    expect(screen.getByText('Expirée', { selector: 'span' })).toBeInTheDocument()

    expect(contextOf('Jus orange 1 L')).toEqual(['Validé : 30', 'Expédié : 28', 'Borne : 50', 'Déjà validé pour ce client : 0', 'Quota région : 1 500', 'Reste région : 1 500'])
    const history = within(screen.getByRole('list', { name: 'Historique' })).getAllByRole('listitem')
    expect(history[1]).toHaveTextContent('Commande expirée à la clôture de la journée08/10/2026 23:00 · automatiquement')
  })

  it('explains an unknown order, or one outside the regions of the regional', async () => {
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => problem(404, 'NOT_FOUND', 'Commande introuvable.') } })
    expect(await screen.findByText('Commande introuvable ou hors de vos régions.')).toBeInTheDocument()
    const back = screen.getByRole('link', { name: /Commandes reçues/ })
    expect(back).toHaveAttribute('href', '/regional/commandes')

    await userEvent.setup().click(back)
    expect(where()).toBe('/regional/commandes')
  })

  it('offers to retry when the order cannot be read', async () => {
    let failing = true
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => (failing ? problem(500, 'SERVER_ERROR', 'Erreur') : json(makeOrder())) } })
    const retry = await screen.findByRole('button', { name: 'Réessayer' })
    failing = false
    await userEvent.setup().click(retry)
    expect(await findTitle()).toBeInTheDocument()
  })

  it('goes back to the list it was opened from, or to the day of the order', async () => {
    const tomorrowOrder = makeOrder({ day: '2026-10-09' })
    const first = renderOrders({
      path: `/regional/commandes?day=${DAY}&onglet=annulees&region=3`,
      routes: { [ORDERS_URL]: () => json(ordersPage([summary({ status: 'CANCELLED' })])) },
    })
    const user = userEvent.setup()
    await user.click(await screen.findByRole('link', { name: 'RMK-261008-00042' }))
    await findTitle()
    await user.click(screen.getByRole('link', { name: /Commandes reçues/ }))
    expect(where()).toBe(`/regional/commandes?day=${DAY}&onglet=annulees&region=3`)
    // Liste relue au retour : des commandes ont pu arriver pendant la lecture du détail.
    await waitFor(() => expect(first.requests(ORDERS_URL)).toHaveLength(2))
    expect(await screen.findByRole('link', { name: 'RMK-261008-00042' })).toBeInTheDocument()
    first.unmount()

    // Lien d'une notification (N-05) : la journée de la commande.
    renderOrders({ path: DETAIL, routes: { [orderUrl(ORDER_ID)]: () => json(tomorrowOrder) } })
    await findTitle()
    expect(screen.getByRole('link', { name: /Commandes reçues/ })).toHaveAttribute('href', '/regional/commandes?day=2026-10-09')
  })

  it('rereads the order on the events of its region that day', async () => {
    let version = 2
    const { requests } = renderOrders({
      path: DETAIL,
      user: regional({ realtime: { key: 'app-key' } }),
      routes: { [orderUrl(ORDER_ID)]: () => json(version === 2 ? makeOrder() : makeOrder({ status: 'CANCELLED', status_label: 'Annulée', version })) },
    })
    await findTitle()
    const channel = `region-day.3.${DAY}`
    for (const event of ['OrderSubmitted', 'OrderUpdated', 'OrderCancelled', 'OrdersExpired', 'DistributionPublished', 'QuotaPublished']) {
      await waitFor(() => expect(fake.listeners.get(`${channel}:.${event}`)?.size).toBe(1))
    }
    act(() => fake.stateChange?.({ current: 'connected' }))

    version = 3
    act(() => fake.listeners.get(`${channel}:.OrderCancelled`)?.forEach((listener) => listener({ id: ORDER_ID, number: 'RMK-261008-00042', status: 'CANCELLED', version: 3, day: DAY, client_id: 12 })))
    expect(await screen.findByText('Annulée', { selector: 'span' })).toBeInTheDocument()
    expect(requests(orderUrl(ORDER_ID))).toHaveLength(2)
  })
})
