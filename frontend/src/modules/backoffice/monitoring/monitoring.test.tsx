import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { disconnectRealtime } from '../../../shared/realtime'
import { json, problem } from '../../../test/utils'
import {
  admin,
  adv,
  CENTRE,
  DAY,
  EST,
  EXPORT_URL,
  makeMonitoring,
  MONITORING_URL,
  PICKING_URL,
  renderMonitoring,
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

const downloads: string[] = []
const blobs: Blob[] = []

beforeEach(() => {
  downloads.length = 0
  blobs.length = 0
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, writable: true, value: () => {} })
  Object.defineProperty(URL, 'createObjectURL', {
    configurable: true,
    writable: true,
    value: (blob: Blob) => {
      blobs.push(blob)
      return 'blob:suivi'
    },
  })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    downloads.push(this.download)
  })
})

afterEach(() => {
  disconnectRealtime()
  fake.stateChange = null
  fake.listeners.clear()
  vi.restoreAllMocks()
})

const file = (name: string, body = 'contenu') =>
  new Response(body, { status: 200, headers: { 'Content-Disposition': `attachment; filename="${name}"` } })

const regionRow = (code: string): HTMLElement => {
  const row = document.querySelector<HTMLElement>(`tr[data-region="${code}"]`)
  if (!row) throw new Error(`Région absente : ${code}`)
  return row
}

describe('ADV — day monitoring (EF-ADV-03)', () => {
  it('shows the key figures, the regions with their alerts and the product × region matrix', async () => {
    renderMonitoring()

    await screen.findByRole('table', { name: 'Régions' })
    expect(screen.getByRole('heading', { level: 1, name: 'Suivi de la journée' })).toBeInTheDocument()
    expect(screen.getByText('Journée du jeudi 08/10/2026')).toBeInTheDocument()
    expect(screen.getByText('Ouverte')).toBeInTheDocument()

    // Chiffres clés : commandes par statut, clients, quotas.
    const tile = (name: string) => document.querySelector<HTMLElement>(`[data-tile="${name}"]`)!
    expect(tile('SUBMITTED')).toHaveTextContent('À valider4')
    expect(tile('VALIDATED')).toHaveTextContent('À préparer8')
    expect(tile('IN_PREPARATION')).toHaveTextContent('En préparation2')
    expect(tile('SHIPPED')).toHaveTextContent('Expédiées1')
    expect(tile('REJECTED')).toHaveTextContent('Rejetées1')
    expect(tile('clients')).toHaveTextContent('17 / 40')
    expect(tile('quotas')).toHaveTextContent('700 / 1 300')
    expect(tile('quotas')).toHaveTextContent('25 en attente de validation')
    // Les statuts de la file ADV ouvrent la file sur la journée.
    expect(within(tile('VALIDATED')).getByRole('link')).toHaveAttribute('href', `/adv/commandes?jour=${DAY}&onglet=a-preparer`)
    expect(within(tile('SUBMITTED')).queryByRole('link')).not.toBeInTheDocument()

    // Régions : statut, heures limites, clients, commandes, dernière validation, alertes (texte et couleur).
    const centre = within(regionRow('CENTRE'))
    expect(centre.getByText('Dépôt de Blida')).toBeInTheDocument()
    expect(centre.getByText('Répartition publiée')).toBeInTheDocument()
    expect(centre.getByText('Validation 15:00')).toBeInTheDocument()
    expect(centre.getByText('17 sur 40')).toBeInTheDocument()
    expect(centre.getByText('8 à préparer')).toBeInTheDocument()
    expect(centre.getByText('4 à valider')).toBeInTheDocument()
    expect(centre.getByText('08/10/2026 14:20')).toBeInTheDocument()
    expect(centre.getByText('Validation en retard')).toBeInTheDocument()
    expect(centre.queryByText('Répartition en retard')).not.toBeInTheDocument()
    const est = within(regionRow('EST'))
    expect(est.getByText('Sans dépôt')).toBeInTheDocument()
    expect(est.getByText('Répartition en retard')).toBeInTheDocument()
    expect(est.getByText('Aucune commande')).toBeInTheDocument()
  })

  it('marks a region without any alert for screen readers', async () => {
    renderMonitoring({ routes: { [MONITORING_URL]: () => json(makeMonitoring({ regions: [CENTRE, { ...EST, alerts: [] }] })) } })

    await screen.findByRole('table', { name: 'Régions' })
    const est = within(regionRow('EST'))
    expect(est.getByText('Aucune alerte')).toHaveClass('sr-only')
    expect(est.queryByText('Répartition en retard')).not.toBeInTheDocument()
  })

  it('highlights the sold-out cells in the matrix, with the pending quantities', async () => {
    renderMonitoring()

    const matrix = within(await screen.findByRole('table', { name: 'Quantités validées sur quota, par produit et par région' }))
    expect(within(matrix.getAllByRole('rowgroup')[0]!).getAllByRole('columnheader').map((header) => header.textContent)).toEqual(['Produit', 'CENTRE', 'EST', 'Total'])
    // Familles en sous-titres.
    expect(matrix.getByText('Jus')).toBeInTheDocument()
    expect(matrix.getByText('Produits laitiers')).toBeInTheDocument()

    const orangeCentre = matrix.getByRole('cell', { name: 'Orange 1 L, Centre : 300 validés sur 300, 25 en attente' })
    expect(orangeCentre).toHaveAttribute('data-sold-out', 'true')
    expect(orangeCentre).toHaveClass('bg-red-100')
    expect(orangeCentre).toHaveTextContent('300 / 300')
    expect(orangeCentre).toHaveTextContent('Épuisé')
    expect(orangeCentre).toHaveTextContent('+25 en attente')

    const lbenCentre = matrix.getByRole('cell', { name: 'Lben 1 L, Centre : 400 validés sur 600, 0 en attente' })
    expect(lbenCentre).not.toHaveAttribute('data-sold-out')
    expect(lbenCentre).not.toHaveClass('bg-red-100')
    expect(lbenCentre).toHaveTextContent('400 / 600')

    // Pas de cellule pour un produit sans quota dans la région ; total du produit avec le reste.
    const orange = within(matrix.getByText('Orange 1 L').closest('tr')!)
    expect(orange.getByText('—')).toBeInTheDocument()
    expect(orange.getAllByText('300 / 300')).toHaveLength(2)
    expect(within(matrix.getByText('Lben 1 L').closest('tr')!).getByText('reste 600')).toBeInTheDocument()
  })

  it('links the regions to their orders and to their distribution grid, per right', async () => {
    renderMonitoring()

    await screen.findByRole('table', { name: 'Régions' })
    expect(screen.getByRole('link', { name: 'Commandes de la région Centre' })).toHaveAttribute('href', `/adv/commandes?jour=${DAY}&region=3`)
    expect(screen.getByRole('link', { name: 'Répartition de la région Centre' })).toHaveAttribute('href', `/adv/journees/${DAY}/repartition/3`)
    expect(screen.getByRole('link', { name: 'Fiche de la journée' })).toHaveAttribute('href', `/adv/journees/${DAY}`)
    expect(screen.getByRole('link', { name: 'Commandes de la journée' })).toHaveAttribute('href', `/adv/commandes?jour=${DAY}`)
  })

  it('shows an empty matrix and no regions without failing', async () => {
    renderMonitoring({ routes: { [MONITORING_URL]: () => json(makeMonitoring({ regions: [], products: [], cells: {} })) } })

    expect(await screen.findByText('Aucune région dans votre périmètre pour cette journée.')).toBeInTheDocument()
    expect(screen.getByText('Aucun quota publié ni aucune commande à valider pour cette journée.')).toBeInTheDocument()
  })

  it('explains a day that is not opened, and a malformed date', async () => {
    renderMonitoring({ routes: { [MONITORING_URL]: () => problem(404, 'NOT_FOUND', 'Ressource introuvable.') } })
    expect(await screen.findByText('Cette journée n’est pas ouverte : il n’y a rien à suivre.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: 'Suivi de la journée' })).toBeInTheDocument()
  })

  it('says so for a malformed date', async () => {
    renderMonitoring({ path: '{base}/journees/demain/suivi' })
    expect(await screen.findByText('Date de journée invalide : format attendu AAAA-MM-JJ.')).toBeInTheDocument()
  })

  it('shows the server error', async () => {
    renderMonitoring({ routes: { [MONITORING_URL]: () => problem(500, 'SERVER_ERROR', 'Erreur du serveur.') } })
    expect(await screen.findByRole('alert')).toHaveTextContent('Erreur du serveur.')
  })

  it('keeps the last monitoring on screen when a reread fails', async () => {
    let failing = false
    const { queryClient } = renderMonitoring({
      routes: { [MONITORING_URL]: () => (failing ? problem(500, 'SERVER_ERROR', 'Erreur du serveur.') : json(makeMonitoring())) },
    })
    await screen.findByRole('table', { name: 'Régions' })

    failing = true
    await act(() => queryClient.invalidateQueries())

    expect(await screen.findByRole('alert')).toHaveTextContent('Erreur du serveur.')
    expect(screen.getByRole('table', { name: 'Régions' })).toBeInTheDocument()
  })
})

describe('ADV — live monitoring', () => {
  it('listens to the day and to each visible region, and rereads on their events', async () => {
    const { calls } = renderMonitoring({ user: adv({ realtime: { key: 'app-key' } }) })
    await screen.findByRole('table', { name: 'Régions' })

    for (const event of ['QuotaPublished', 'DistributionPublished', 'DayClosed', 'OrderValidated', 'OrdersProcessed', 'OrderShippingUpdated', 'OrderCancelledByAdv']) {
      await waitFor(() => expect(fake.listeners.get(`adv.${DAY}:.${event}`)?.size).toBe(1))
    }
    for (const region of [CENTRE.region.id, EST.region.id]) {
      for (const event of ['OrderSubmitted', 'OrderUpdated', 'OrderCancelled', 'OrdersExpired', 'OrderRejected']) {
        await waitFor(() => expect(fake.listeners.get(`region-day.${region}.${DAY}:.${event}`)?.size).toBe(1))
      }
      // Déjà reçus une fois sur adv.{jour} : pas une relecture de plus par région (I-97).
      for (const event of ['OrderValidated', 'OrdersProcessed', 'OrderCancelledByAdv']) {
        expect(fake.listeners.get(`region-day.${region}.${DAY}:.${event}`)?.size ?? 0).toBe(0)
      }
    }

    const reads = () => calls.filter((call) => call.path === `/api/v1/adv/days/${DAY}/monitoring`).length
    const before = reads()
    act(() => fake.listeners.get(`adv.${DAY}:.OrderValidated`)?.forEach((listener) => listener({ id: 'X', status: 'VALIDATED', version: 2, day: DAY })))
    await waitFor(() => expect(reads()).toBe(before + 1))

    act(() => fake.listeners.get(`region-day.3.${DAY}:.OrderSubmitted`)?.forEach((listener) => listener({ id: 'Y', day: DAY })))
    await waitFor(() => expect(reads()).toBe(before + 2))
  })

  it('shows the new numbers once reread', async () => {
    let data = makeMonitoring()
    renderMonitoring({ user: adv({ realtime: { key: 'app-key' } }), routes: { [MONITORING_URL]: () => json(data) } })
    await screen.findByRole('table', { name: 'Régions' })
    await waitFor(() => expect(fake.listeners.get(`adv.${DAY}:.OrdersProcessed`)?.size).toBe(1))

    data = makeMonitoring({ totals: { ...data.totals, orders: { ...data.totals.orders, VALIDATED: 3, IN_PREPARATION: 7 } } })
    act(() => fake.listeners.get(`adv.${DAY}:.OrdersProcessed`)?.forEach((listener) => listener({ day: DAY, status: 'IN_PREPARATION', count: 5 })))

    await waitFor(() => expect(document.querySelector('[data-tile="IN_PREPARATION"]')).toHaveTextContent('En préparation7'))
    expect(document.querySelector('[data-tile="VALIDATED"]')).toHaveTextContent('À préparer3')
  })
})

describe('ADV — picking list (RG-A04, EF-ADV-05)', () => {
  it('downloads the picking list by region in PDF by default', async () => {
    const user = userEvent.setup()
    const queries: URLSearchParams[] = []
    renderMonitoring({
      routes: {
        [PICKING_URL]: ({ url }) => {
          queries.push(url.searchParams)
          return file('bon-de-preparation-2026-10-08.pdf', '%PDF-1.7')
        },
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Bon de préparation' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Bon de préparation — jeudi 08/10/2026' }))
    expect(dialog.getByRole('radio', { name: /Par région/ })).toBeChecked()
    expect(dialog.getByRole('radio', { name: 'PDF' })).toBeChecked()

    await user.click(dialog.getByRole('button', { name: 'Télécharger' }))

    await waitFor(() => expect(downloads).toEqual(['bon-de-preparation-2026-10-08.pdf']))
    expect(Object.fromEntries(queries[0]!)).toEqual({ format: 'pdf', group: 'region' })
    expect(await blobs[0]!.text()).toBe('%PDF-1.7')
    // Boîte fermée une fois le fichier enregistré.
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('downloads it by depot in Excel', async () => {
    const user = userEvent.setup()
    const queries: URLSearchParams[] = []
    renderMonitoring({
      routes: {
        [PICKING_URL]: ({ url }) => {
          queries.push(url.searchParams)
          return file('bon-de-preparation-2026-10-08.xlsx')
        },
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Bon de préparation' }))
    const dialog = within(await screen.findByRole('dialog'))
    await user.click(dialog.getByRole('radio', { name: /Par dépôt/ }))
    await user.click(dialog.getByRole('radio', { name: 'Excel (XLSX)' }))
    await user.click(dialog.getByRole('button', { name: 'Télécharger' }))

    await waitFor(() => expect(downloads).toEqual(['bon-de-preparation-2026-10-08.xlsx']))
    expect(Object.fromEntries(queries[0]!)).toEqual({ format: 'xlsx', group: 'depot' })
  })

  it('names the file itself when the server gives no name', async () => {
    const user = userEvent.setup()
    renderMonitoring({ routes: { [PICKING_URL]: () => new Response('xlsx', { status: 200 }) } })

    await user.click(await screen.findByRole('button', { name: 'Bon de préparation' }))
    const dialog = within(await screen.findByRole('dialog'))
    await user.click(dialog.getByRole('radio', { name: 'Excel (XLSX)' }))
    await user.click(dialog.getByRole('button', { name: 'Télécharger' }))

    await waitFor(() => expect(downloads).toEqual(['bon-de-preparation-2026-10-08.xlsx']))
  })

  it('shows a refusal as a message in the dialog, never as a file', async () => {
    const user = userEvent.setup()
    renderMonitoring({ routes: { [PICKING_URL]: () => problem(403, 'FORBIDDEN', 'Action non autorisée.') } })

    await user.click(await screen.findByRole('button', { name: 'Bon de préparation' }))
    const dialog = within(await screen.findByRole('dialog'))
    await user.click(dialog.getByRole('button', { name: 'Télécharger' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent('Action non autorisée.')
    expect(downloads).toEqual([])
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('cannot be requested while offline', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    const user = userEvent.setup()
    renderMonitoring()

    await user.click(await screen.findByRole('button', { name: 'Bon de préparation' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect(dialog.getByRole('button', { name: 'Télécharger' })).toBeDisabled()
    expect(dialog.getByText('Hors ligne : les téléchargements sont indisponibles.')).toBeInTheDocument()
  })

  it('closes the dialog on Cancel without downloading', async () => {
    const user = userEvent.setup()
    const { calls } = renderMonitoring()
    await user.click(await screen.findByRole('button', { name: 'Bon de préparation' }))

    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Annuler' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.some((call) => call.path.endsWith('/picking-list'))).toBe(false)
  })
})

describe('ADV and Admin — orders export (RG-A04, EF-ADV-07)', () => {
  it.each([
    ['xlsx', 'Excel (XLSX)', 'commandes-2026-10-08.xlsx'],
    ['csv', 'CSV (Excel français)', 'commandes-2026-10-08.csv'],
    ['erp', 'ERP (format provisoire)', 'commandes-erp-2026-10-08.csv'],
  ] as const)('downloads the %s export', async (format, label, filename) => {
    const user = userEvent.setup()
    const queries: URLSearchParams[] = []
    renderMonitoring({
      routes: {
        [EXPORT_URL]: ({ url }) => {
          queries.push(url.searchParams)
          return file(filename)
        },
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Export' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Export des commandes — jeudi 08/10/2026' }))
    expect(dialog.getByRole('radio', { name: /Excel \(XLSX\)/ })).toBeChecked()
    await user.click(dialog.getByRole('radio', { name: new RegExp(label.replace(/[()]/g, '\\$&')) }))
    await user.click(dialog.getByRole('button', { name: 'Télécharger' }))

    await waitFor(() => expect(downloads).toEqual([filename]))
    expect(Object.fromEntries(queries[0]!)).toEqual({ format })
  })

  it('shows a refusal of the export as a message (too many lines)', async () => {
    const user = userEvent.setup()
    renderMonitoring({
      routes: { [EXPORT_URL]: () => problem(422, 'VALIDATION_FAILED', 'Export trop volumineux : filtrez par région.', { errors: { filter: ['Export trop volumineux : filtrez par région.'] } }) },
    })

    await user.click(await screen.findByRole('button', { name: 'Export' }))
    const dialog = within(await screen.findByRole('dialog'))
    await user.click(dialog.getByRole('button', { name: 'Télécharger' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent('Export trop volumineux')
    expect(downloads).toEqual([])
  })

  it('gives the Admin the export, read-only, and no picking list', async () => {
    const user = userEvent.setup()
    renderMonitoring({ user: admin(), routes: { [EXPORT_URL]: () => file('commandes-2026-10-08.xlsx') } })

    await screen.findByRole('table', { name: 'Régions' })
    expect(screen.queryByRole('button', { name: 'Bon de préparation' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Fiche de la journée' })).toHaveAttribute('href', `/admin/journees/${DAY}`)
    expect(screen.getByRole('link', { name: 'Commandes de la région Centre' })).toHaveAttribute('href', `/admin/commandes?jour=${DAY}&region=3`)

    await user.click(screen.getByRole('button', { name: 'Export' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Télécharger' }))
    await waitFor(() => expect(downloads).toEqual(['commandes-2026-10-08.xlsx']))
  })
})
