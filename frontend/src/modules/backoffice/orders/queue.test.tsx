import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { BulkStatusResult } from '../../../shared/api/types'
import { businessToday } from '../../../shared/format'
import { disconnectRealtime } from '../../../shared/realtime'
import { json, problem } from '../../../test/utils'
import {
  admin,
  adv,
  BULK_URL,
  COUNTS,
  DAY,
  ID1,
  ID2,
  ID3,
  ORDERS_URL,
  queuePage,
  renderQueue,
  SECOND,
  summary,
  THIRD,
} from './testing'

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

const emit = (channel: string, event: string, payload: unknown = {}) =>
  act(() => fake.listeners.get(`${channel}:.${event}`)?.forEach((listener) => listener(payload)))

/** Requêtes de la file, dans l'ordre. */
const queueRequests = (calls: Array<{ method: string; path: string }>) => calls.filter((call) => call.method === 'GET' && call.path === '/api/v1/adv/orders')

const rowOf = (number: string): HTMLElement => {
  const row = document.querySelector<HTMLElement>(`tr[data-order="${number}"]`)
  if (!row) throw new Error(`Ligne absente : ${number}`)
  return row
}

describe('ADV — order queue (EF-ADV-04)', () => {
  it('lists the validated orders with their tabs and counters, oldest validation first', async () => {
    const requests: URLSearchParams[] = []
    renderQueue({
      routes: {
        [ORDERS_URL]: ({ url }) => {
          requests.push(url.searchParams)
          return json(queuePage([summary(), SECOND, THIRD]))
        },
      },
    })

    const table = await screen.findByRole('table', { name: 'Commandes de l’étape' })
    expect(screen.getByRole('heading', { level: 1, name: 'Commandes' })).toBeInTheDocument()
    expect(Object.fromEntries(requests[0]!)).toEqual({ 'filter[status]': 'VALIDATED', sort: 'decided_at', page: '1', per_page: '50' })

    // Onglets avec les compteurs du serveur ; « À préparer » est l'onglet courant.
    const tabs = screen.getAllByRole('tab').map((tab) => tab.textContent)
    expect(tabs).toEqual(['À préparer (3)', 'En préparation (1)', 'Préparées (0)', 'Expédiées (2)', 'Annulées (0)'])
    expect(screen.getByRole('tab', { name: 'À préparer (3)' })).toHaveAttribute('aria-selected', 'true')

    expect(within(table).getAllByRole('row')).toHaveLength(1 + 3)
    const first = within(rowOf('RMK-261008-00042'))
    expect(first.getByRole('link', { name: 'RMK-261008-00042' })).toHaveAttribute('href', '/adv/commandes/' + ID1)
    expect(first.getByText('Validée')).toBeInTheDocument()
    expect(first.getByText('C0012')).toBeInTheDocument()
    expect(first.getByText('Superette El Baraka')).toBeInTheDocument()
    expect(first.getByText('Dépôt de Blida')).toBeInTheDocument()
    expect(first.getByText('par Karim Régional')).toBeInTheDocument()
    expect(first.getByRole('link', { name: '08/10/2026' })).toHaveAttribute('href', '/adv/journees/2026-10-08/suivi')

    // Commande ajustée à la validation et client bloqué : repères en couleur et en texte.
    const second = within(rowOf('RMK-261008-00043'))
    expect(second.getByText('Ajustée')).toBeInTheDocument()
    expect(second.getByText('Bloqué')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'RMK-261007-00031' })).toBeInTheDocument()
  })

  it('shows an empty queue, and says so when the filters leave nothing', async () => {
    const user = userEvent.setup()
    renderQueue({ routes: { [ORDERS_URL]: () => json(queuePage([], { counts: { ...COUNTS, VALIDATED: 0 } })) } })

    expect(await screen.findByText('Aucune commande à cette étape.')).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Région'), '3')
    expect(await screen.findByText('Aucune commande ne correspond à ces filtres.')).toBeInTheDocument()
  })

  it('opens another tab: its status, its own milestone column, the address', async () => {
    const requests: URLSearchParams[] = []
    const user = userEvent.setup()
    renderQueue({
      routes: {
        [ORDERS_URL]: ({ url }) => {
          requests.push(url.searchParams)
          return json(
            url.searchParams.get('filter[status]') === 'IN_PREPARATION'
              ? queuePage([summary({ status: 'IN_PREPARATION', next_statuses: ['PREPARED'], prep_started_at: '2026-10-08T10:20:00+00:00' })])
              : queuePage([summary()]),
          )
        },
      },
    })
    await screen.findByRole('table', { name: 'Commandes de l’étape' })
    expect(screen.queryByRole('columnheader', { name: 'Préparation commencée' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('tab', { name: 'En préparation (1)' }))

    await screen.findByRole('columnheader', { name: 'Préparation commencée' })
    expect(requests.at(-1)?.get('filter[status]')).toBe('IN_PREPARATION')
    expect(screen.getByTestId('where')).toHaveTextContent('/adv/commandes?onglet=en-preparation')
    expect(screen.getByText('08/10/2026 11:20')).toBeInTheDocument()
  })

  it('keeps the counters but not the orders of the previous tab while another tab loads', async () => {
    let release: (response: Response) => void = () => undefined
    const user = userEvent.setup()
    renderQueue({
      routes: {
        [ORDERS_URL]: ({ url }) =>
          url.searchParams.get('filter[status]') === 'IN_PREPARATION'
            ? new Promise<Response>((resolve) => (release = resolve))
            : json(queuePage([summary(), SECOND, THIRD])),
      },
    })
    await screen.findByRole('table', { name: 'Commandes de l’étape' })

    await user.click(screen.getByRole('tab', { name: 'En préparation (1)' }))

    expect(await screen.findByText('Chargement des commandes…')).toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Commandes de l’étape' })).not.toBeInTheDocument()
    expect(screen.queryByText('Aucune commande à cette étape.')).not.toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'À préparer (3)' })).toBeInTheDocument()

    release(json(queuePage([summary({ status: 'IN_PREPARATION', next_statuses: ['PREPARED'] })])))
    expect(await screen.findByRole('table', { name: 'Commandes de l’étape' })).toBeInTheDocument()
    expect(screen.queryByText('Chargement des commandes…')).not.toBeInTheDocument()
  })

  it('filters by day, region, depot, product and free search, and resets them', async () => {
    const requests: URLSearchParams[] = []
    const user = userEvent.setup()
    renderQueue({
      routes: {
        [ORDERS_URL]: ({ url }) => {
          requests.push(url.searchParams)
          return json(queuePage([summary()]))
        },
        'GET /api/v1/products': ({ url }) =>
          json({
            data: url.searchParams.get('filter[search]') === 'lben' ? [{ id: 57, sku: 'P0057', name: 'Lben 1 L', short_name: 'Lben 1 L' }] : [],
            meta: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1 },
          }),
        'GET /api/v1/products/57': () => json({ id: 57, sku: 'P0057', name: 'Lben 1 L', short_name: 'Lben 1 L' }),
      },
    })
    await screen.findByRole('table', { name: 'Commandes de l’étape' })
    // Régions et dépôts viennent de /regions.
    await screen.findByRole('option', { name: 'CENTRE — Centre' })
    const last = () => Object.fromEntries(requests.at(-1)!)

    await user.type(screen.getByLabelText('Journée'), DAY)
    await waitFor(() => expect(last()['filter[day]']).toBe(DAY))

    await user.selectOptions(screen.getByLabelText('Région'), '3')
    await waitFor(() => expect(last()['filter[region]']).toBe('3'))

    await user.selectOptions(screen.getByLabelText('Dépôt'), '1')
    await waitFor(() => expect(last()['filter[depot]']).toBe('1'))
    await user.selectOptions(screen.getByLabelText('Dépôt'), 'none')
    await waitFor(() => expect(last()['filter[depot]']).toBe('none'))

    // Recherche libre : une requête par pause de frappe, pas par touche.
    const before = requests.length
    await user.type(screen.getByLabelText('Client ou numéro'), 'baraka')
    await waitFor(() => expect(last()['filter[search]']).toBe('baraka'))
    expect(requests.length - before).toBeLessThanOrEqual(2)

    // Produit : saisie, choix dans la liste, puis retrait.
    await user.type(screen.getByLabelText('Produit'), 'lben')
    await waitFor(() => expect(document.querySelectorAll('datalist option')).toHaveLength(1))
    await user.clear(screen.getByLabelText('Produit'))
    await user.type(screen.getByLabelText('Produit'), 'P0057 — Lben 1 L')
    await waitFor(() => expect(last()['filter[product]']).toBe('57'))
    expect(await screen.findByText('P0057 — Lben 1 L')).toBeInTheDocument()

    expect(screen.getByTestId('where')).toHaveTextContent('/adv/commandes?jour=2026-10-08&region=3&depot=none&produit=57')

    await user.click(screen.getByRole('button', { name: 'Réinitialiser les filtres' }))
    // Les filtres retirés reprennent la file non filtrée, déjà en mémoire.
    expect(screen.getByTestId('where')).toHaveTextContent(/^\/adv\/commandes$/)
    expect(screen.getByLabelText('Client ou numéro')).toHaveValue('')
    expect(screen.getByLabelText('Région')).toHaveValue('')
    expect(screen.queryByRole('button', { name: 'Réinitialiser les filtres' })).not.toBeInTheDocument()
  })

  it('sorts, and pages through the results', async () => {
    const requests: URLSearchParams[] = []
    const user = userEvent.setup()
    renderQueue({
      routes: {
        [ORDERS_URL]: ({ url }) => {
          requests.push(url.searchParams)
          const page = Number(url.searchParams.get('page'))
          return json(queuePage([summary()], { page, lastPage: 3, total: 120 }))
        },
      },
    })
    await screen.findByText('Page 1 sur 3 · 120 résultat(s)')

    await user.click(screen.getByRole('button', { name: 'Suivant' }))
    await screen.findByText('Page 2 sur 3 · 120 résultat(s)')
    expect(requests.at(-1)?.get('page')).toBe('2')
    expect(screen.getByTestId('where')).toHaveTextContent('/adv/commandes?page=2')

    // Un autre tri revient à la première page.
    await user.selectOptions(screen.getByLabelText('Tri'), '-number')
    await waitFor(() => expect(requests.at(-1)?.get('sort')).toBe('-number'))
    expect(requests.at(-1)?.get('page')).toBe('1')
  })

  it('shows the server error without hiding the page', async () => {
    renderQueue({ routes: { [ORDERS_URL]: () => problem(500, 'SERVER_ERROR', 'Erreur du serveur.') } })

    expect(await screen.findByRole('alert')).toHaveTextContent('Erreur du serveur.')
    expect(screen.getByRole('tab', { name: 'À préparer' })).toBeInTheDocument()
  })
})

describe('ADV — bulk status change (RG-A02, I-78)', () => {
  const selectBoth = async (user: ReturnType<typeof userEvent.setup>) => {
    await screen.findByRole('table', { name: 'Commandes de l’étape' })
    await user.click(screen.getByRole('checkbox', { name: 'Sélectionner la commande RMK-261008-00042' }))
    await user.click(screen.getByRole('checkbox', { name: 'Sélectionner la commande RMK-261008-00043' }))
  }

  it('sends the selected orders with their versions, then reports applied and refused ones', async () => {
    const result: BulkStatusResult = {
      applied: [{ id: ID1, number: 'RMK-261008-00042', status: 'IN_PREPARATION', version: 4 }],
      refused: [{ id: ID2, number: 'RMK-261008-00043', code: 'VERSION_CONFLICT', detail: 'La commande a été modifiée.', version: 3 }],
    }
    const user = userEvent.setup()
    const { calls } = renderQueue({ routes: { [BULK_URL]: () => json(result) } })
    await selectBoth(user)

    expect(screen.getByText('2 commandes sélectionnées')).toBeInTheDocument()
    const reads = queueRequests(calls).length
    await user.click(screen.getByRole('button', { name: 'Passer en préparation (2)' }))

    const report = await screen.findByRole('region', { name: 'Résultat du traitement' })
    expect(calls.find((call) => call.path === '/api/v1/adv/orders/bulk-status')?.body).toEqual({
      status: 'IN_PREPARATION',
      orders: [
        { id: ID1, version: 3 },
        { id: ID2, version: 2 },
      ],
    })
    expect(within(report).getByText(/Passage en préparation : 1 appliquée \/ 1 refusée/)).toBeInTheDocument()
    expect(within(report).getByText('RMK-261008-00043')).toBeInTheDocument()
    expect(within(report).getByText(/modifiée entre-temps/)).toBeInTheDocument()
    expect(within(report).getByText('La commande a été modifiée.')).toBeInTheDocument()

    // Sélection vidée, file relue.
    expect(screen.queryByText(/commandes sélectionnées/)).not.toBeInTheDocument()
    await waitFor(() => expect(queueRequests(calls).length).toBeGreaterThan(reads))

    await user.click(within(report).getByRole('button', { name: 'Fermer le résultat' }))
    expect(screen.queryByRole('region', { name: 'Résultat du traitement' })).not.toBeInTheDocument()
  })

  it('explains each refusal reason and keeps the applied count when nothing is refused', async () => {
    const user = userEvent.setup()
    renderQueue({
      routes: {
        [BULK_URL]: () =>
          json({
            applied: [{ id: ID1, number: 'RMK-261008-00042', status: 'IN_PREPARATION', version: 4 }],
            refused: [
              { id: ID2, number: 'RMK-261008-00043', code: 'INVALID_TRANSITION', detail: 'Commande « Préparée » : impossible.' },
              { id: ID3, number: null, code: 'NOT_FOUND', detail: 'Commande introuvable.' },
            ],
          }),
      },
    })
    await selectBoth(user)
    await user.click(screen.getByRole('button', { name: 'Passer en préparation (2)' }))

    const report = await screen.findByRole('region', { name: 'Résultat du traitement' })
    expect(within(report).getByText(/1 appliquée \/ 2 refusées/)).toBeInTheDocument()
    expect(within(report).getByText(/étape non permise/)).toBeInTheDocument()
    expect(within(report).getByText(/introuvable ou hors de votre périmètre/)).toBeInTheDocument()
    expect(within(report).getByText(ID3)).toBeInTheDocument()
  })

  it('sends only the orders that may take the step', async () => {
    const user = userEvent.setup()
    const frozen = summary({ id: ID2, number: 'RMK-261008-00043', next_statuses: [] })
    const { calls } = renderQueue({
      routes: {
        [ORDERS_URL]: () => json(queuePage([summary(), frozen])),
        [BULK_URL]: () => json({ applied: [{ id: ID1, number: 'RMK-261008-00042', status: 'IN_PREPARATION', version: 4 }], refused: [] }),
      },
    })
    await selectBoth(user)

    expect(screen.getByText('1 commande sélectionnée ne peut plus passer à cette étape : elle ne sera pas envoyée.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Passer en préparation (1)' }))

    await screen.findByRole('region', { name: 'Résultat du traitement' })
    expect(calls.find((call) => call.path === '/api/v1/adv/orders/bulk-status')?.body).toEqual({ status: 'IN_PREPARATION', orders: [{ id: ID1, version: 3 }] })
  })

  it('selects the whole page, and caps the selection at 100 orders', async () => {
    const rows = Array.from({ length: 101 }, (_, index) =>
      summary({ id: `01JA2Z6K8QW3X9M4T7RBN5C${String(index).padStart(3, '0')}`, number: `RMK-${String(index).padStart(5, '0')}` }),
    )
    const user = userEvent.setup()
    renderQueue({ routes: { [ORDERS_URL]: () => json(queuePage(rows)) } })
    await screen.findByRole('table', { name: 'Commandes de l’étape' })

    await user.click(screen.getByRole('checkbox', { name: 'Sélectionner les commandes de la page' }))

    expect(screen.getByText('100 commandes sélectionnées')).toBeInTheDocument()
    expect(screen.getByText('La sélection est limitée à 100 commandes.')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Sélectionner la commande RMK-00100' })).toBeDisabled()
    expect(screen.getByRole('checkbox', { name: 'Sélectionner la commande RMK-00099' })).toBeEnabled()

    await user.click(screen.getByRole('button', { name: 'Tout désélectionner' }))
    expect(screen.queryByText(/commandes sélectionnées/)).not.toBeInTheDocument()
  })

  it('offers the next step of each tab, and none on the last ones', async () => {
    const user = userEvent.setup()
    renderQueue({
      routes: {
        [ORDERS_URL]: ({ url }) => {
          const status = url.searchParams.get('filter[status]')
          if (status === 'PREPARED') return json(queuePage([summary({ status: 'PREPARED', next_statuses: ['SHIPPED'] })]))
          if (status === 'SHIPPED') return json(queuePage([summary({ status: 'SHIPPED', next_statuses: [], can_cancel: false })]))
          return json(queuePage([summary()]))
        },
      },
    })
    await screen.findByRole('table', { name: 'Commandes de l’étape' })

    await user.click(screen.getByRole('tab', { name: /Préparées/ }))
    await user.click(await screen.findByRole('checkbox', { name: 'Sélectionner la commande RMK-261008-00042' }))
    expect(screen.getByRole('button', { name: 'Marquer expédiées (1)' })).toBeEnabled()

    await user.click(screen.getByRole('tab', { name: /Expédiées/ }))
    await user.click(await screen.findByRole('checkbox', { name: 'Sélectionner la commande RMK-261008-00042' }))
    expect(screen.getByText('1 commande sélectionnée')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Marquer|Passer en préparation/ })).not.toBeInTheDocument()
  })

  it('shows the refusal of the whole request and keeps the selection', async () => {
    const user = userEvent.setup()
    renderQueue({ routes: { [BULK_URL]: () => problem(403, 'FORBIDDEN', 'Action non autorisée.') } })
    await selectBoth(user)

    await user.click(screen.getByRole('button', { name: 'Passer en préparation (2)' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Action non autorisée.')
    expect(screen.getByText('2 commandes sélectionnées')).toBeInTheDocument()
  })

  it('disables the change while offline', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    const user = userEvent.setup()
    renderQueue()
    await selectBoth(user)

    expect(screen.getByRole('button', { name: 'Passer en préparation (2)' })).toBeDisabled()
    expect(screen.getAllByText(/Hors ligne/).length).toBeGreaterThan(0)
  })
})

describe('Admin — read-only queue (I-87)', () => {
  it('lists the orders without selection nor actions, linking to the Admin detail', async () => {
    renderQueue({ user: admin() })

    await screen.findByRole('table', { name: 'Commandes de l’étape' })
    expect(screen.getByText('Consultation seule : le traitement des commandes est réservé à l’ADV.')).toBeInTheDocument()
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'RMK-261008-00042' })).toHaveAttribute('href', '/admin/commandes/' + ID1)
    screen.getAllByRole('link', { name: '08/10/2026' }).forEach((link) => expect(link).toHaveAttribute('href', '/admin/journees/2026-10-08/suivi'))
  })

  it('does not link to the monitoring without dashboard.view', async () => {
    renderQueue({ user: adv({ permissions: ['order.view', 'order.process', 'region.view'] }) })

    await screen.findByRole('table', { name: 'Commandes de l’étape' })
    expect(screen.queryByRole('link', { name: '08/10/2026' })).not.toBeInTheDocument()
    expect(screen.getAllByText('08/10/2026').length).toBeGreaterThan(0)
  })
})

describe('ADV — live queue (adv.{day})', () => {
  it('listens to every displayed day and to today, and rereads the queue on the order events', async () => {
    const { calls } = renderQueue({ user: adv({ realtime: { key: 'app-key' } }) })
    await screen.findByRole('table', { name: 'Commandes de l’étape' })

    // Journées affichées (07 et 08/10) et aujourd'hui.
    for (const day of new Set([businessToday(), '2026-10-07', DAY])) {
      for (const event of ['OrderValidated', 'OrdersProcessed', 'OrderShippingUpdated', 'OrderCancelledByAdv', 'DayClosed']) {
        await waitFor(() => expect(fake.listeners.get(`adv.${day}:.${event}`)?.size).toBe(1))
      }
    }

    const before = queueRequests(calls).length
    emit(`adv.${DAY}`, 'OrderValidated', { id: ID1, status: 'VALIDATED', version: 3, day: DAY })
    await waitFor(() => expect(queueRequests(calls).length).toBe(before + 1))

    emit('adv.2026-10-07', 'OrdersProcessed', { day: '2026-10-07', status: 'IN_PREPARATION', count: 12 })
    await waitFor(() => expect(queueRequests(calls).length).toBe(before + 2))
  })

  it('regroups a burst of events in one read', async () => {
    const { calls } = renderQueue({ user: adv({ realtime: { key: 'app-key' } }) })
    await screen.findByRole('table', { name: 'Commandes de l’étape' })
    await waitFor(() => expect(fake.listeners.get(`adv.${DAY}:.OrderValidated`)?.size).toBe(1))

    const before = queueRequests(calls).length
    for (let index = 0; index < 10; index++) emit(`adv.${DAY}`, 'OrderValidated', { id: ID1, status: 'VALIDATED', version: index, day: DAY })
    await waitFor(() => expect(queueRequests(calls).length).toBe(before + 1))
    await new Promise((resolve) => setTimeout(resolve, 700))
    expect(queueRequests(calls).length).toBe(before + 1)
  })

  it('keeps the selection when the queue is reread', async () => {
    const user = userEvent.setup()
    const { calls } = renderQueue({ user: adv({ realtime: { key: 'app-key' } }) })
    await screen.findByRole('table', { name: 'Commandes de l’étape' })
    await user.click(screen.getByRole('checkbox', { name: 'Sélectionner la commande RMK-261008-00042' }))
    await waitFor(() => expect(fake.listeners.get(`adv.${DAY}:.OrderValidated`)?.size).toBe(1))

    const before = queueRequests(calls).length
    emit(`adv.${DAY}`, 'OrderValidated', { id: ID2, status: 'VALIDATED', version: 2, day: DAY })
    await waitFor(() => expect(queueRequests(calls).length).toBe(before + 1))

    expect(screen.getByRole('checkbox', { name: 'Sélectionner la commande RMK-261008-00042' })).toBeChecked()
    expect(screen.getByText('1 commande sélectionnée')).toBeInTheDocument()
  })
})
