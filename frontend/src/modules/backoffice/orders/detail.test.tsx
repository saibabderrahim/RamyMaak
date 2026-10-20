import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { disconnectRealtime } from '../../../shared/realtime'
import { json, problem } from '../../../test/utils'
import {
  admin,
  adv,
  BULK_URL,
  cancelUrl,
  DAY,
  ID1,
  inPreparation,
  lben,
  makeOrder,
  orange,
  orderUrl,
  pdfUrl,
  prepared,
  renderOrder,
  shipped,
  shippingUrl,
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
      return 'blob:bon'
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

const calls = (all: Array<{ method: string; path: string; body: unknown }>, method: string, suffix: string) =>
  all.filter((call) => call.method === method && call.path.endsWith(suffix))

describe('ADV — order detail (EF-ADV-04)', () => {
  it('shows the client, the lines with their adjustment reasons, the totals and the history', async () => {
    renderOrder()

    expect(await screen.findByRole('heading', { level: 1, name: 'Commande RMK-261008-00042' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'File des commandes' })).toHaveAttribute('href', '/adv/commandes')

    // Résumé : statut, journée (suivi), région, dépôt, validation, commentaire du client.
    const summary = within(screen.getByRole('heading', { name: 'Commande', level: 2 }).closest('section')!)
    expect(summary.getByText('Validée')).toBeInTheDocument()
    expect(summary.getByText('Ajustée')).toBeInTheDocument()
    expect(summary.getByRole('link', { name: '08/10/2026' })).toHaveAttribute('href', '/adv/journees/2026-10-08/suivi')
    expect(summary.getByText('CENTRE — Centre')).toBeInTheDocument()
    expect(summary.getByText('BLIDA — Dépôt de Blida')).toBeInTheDocument()
    expect(summary.getByText('08/10/2026 10:15 — Karim Régional')).toBeInTheDocument()
    expect(summary.getByText('Livraison avant 10 h')).toBeInTheDocument()

    // Client : identité complète, téléphones en lien d'appel.
    const client = within(screen.getByRole('heading', { name: 'Client', level: 2 }).closest('section')!)
    expect(client.getByText('Superette El Baraka')).toBeInTheDocument()
    expect(client.getByText('12 rue des Frères Bouchama')).toBeInTheDocument()
    expect(client.getByText('Alger — Bab Ezzouar')).toBeInTheDocument()
    expect(client.getByText('000216001234567')).toBeInTheDocument()
    expect(client.getByRole('link', { name: '0550 12 34 56' })).toHaveAttribute('href', 'tel:0550123456')
    expect(client.getByRole('link', { name: '0770112233' })).toHaveAttribute('href', 'tel:0770112233')

    // Lignes : demandé, validé réduit, motif et commentaire d'ajustement.
    const lines = within(screen.getByRole('table', { name: 'Lignes de la commande' }))
    const lben = within(lines.getByText('Lben 1 L').closest('tr')!)
    expect(lben.getByText('1 250')).toBeInTheDocument()
    expect(lben.getByText('1 000')).toBeInTheDocument()
    expect(lben.getByText(/Réduit de 1 250 à 1 000/)).toBeInTheDocument()
    expect(lben.getByText(/Motif : Quota insuffisant/)).toBeInTheDocument()
    expect(lben.getByText('Reste région limité')).toBeInTheDocument()
    expect(lines.queryByRole('columnheader', { name: 'Expédié' })).not.toBeInTheDocument()

    expect(screen.getByText('Montants indicatifs : la facture ERP fait foi.')).toBeInTheDocument()

    // Historique : étapes, auteurs nommés.
    const history = within(screen.getByRole('heading', { name: 'Historique', level: 2 }).closest('section')!)
    expect(history.getByText('Envoyée')).toBeInTheDocument()
    expect(history.getByText('Validée')).toBeInTheDocument()
    expect(history.getByText('par Karim Régional')).toBeInTheDocument()
    expect(history.getByText('par Gérant C0012')).toBeInTheDocument()
  })

  it('moves a validated order to preparation with a one-order status change', async () => {
    const user = userEvent.setup()
    const { calls: all } = renderOrder({
      routes: { [BULK_URL]: () => json({ applied: [{ id: ID1, number: 'RMK-261008-00042', status: 'IN_PREPARATION', version: 4 }], refused: [] }) },
    })

    await user.click(await screen.findByRole('button', { name: 'Passer en préparation' }))

    expect(await screen.findByText('Statut mis à jour : En préparation.')).toBeInTheDocument()
    expect(calls(all, 'POST', '/adv/orders/bulk-status')[0]?.body).toEqual({ status: 'IN_PREPARATION', orders: [{ id: ID1, version: 3 }] })
    // Le détail est relu.
    await waitFor(() => expect(calls(all, 'GET', `/adv/orders/${ID1}`).length).toBeGreaterThan(1))
  })

  it('shows the refusal of the status change', async () => {
    const user = userEvent.setup()
    renderOrder({
      routes: {
        [BULK_URL]: () =>
          json({
            applied: [],
            refused: [{ id: ID1, number: 'RMK-261008-00042', code: 'VERSION_CONFLICT', detail: 'La commande a été modifiée par un autre utilisateur.', version: 4 }],
          }),
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Passer en préparation' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('La commande a été modifiée par un autre utilisateur.')
    expect(screen.queryByText(/Statut mis à jour/)).not.toBeInTheDocument()
  })

  it('downloads the order form (PDF) by an authenticated request', async () => {
    const user = userEvent.setup()
    renderOrder({
      routes: {
        [pdfUrl(ID1)]: () =>
          new Response('%PDF-1.7', {
            status: 200,
            headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="bon-de-commande-RMK-261008-00042.pdf"' },
          }),
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Bon de commande (PDF)' }))

    await waitFor(() => expect(downloads).toEqual(['bon-de-commande-RMK-261008-00042.pdf']))
    expect(await blobs[0]!.text()).toBe('%PDF-1.7')
  })

  it('shows a refusal of the PDF as a message, never as a file', async () => {
    const user = userEvent.setup()
    renderOrder({ routes: { [pdfUrl(ID1)]: () => problem(403, 'FORBIDDEN', 'Action non autorisée.') } })

    await user.click(await screen.findByRole('button', { name: 'Bon de commande (PDF)' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Action non autorisée.')
    expect(downloads).toEqual([])
  })

  it('says so when the order is not in the file of the account', async () => {
    renderOrder({ routes: { [orderUrl(ID1)]: () => problem(404, 'NOT_FOUND', 'Ressource introuvable.') } })

    expect(await screen.findByText('Commande introuvable')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: 'Commandes' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'File des commandes' })).toBeInTheDocument()
  })

  it('does not ask the server for a malformed identifier', async () => {
    const { calls: all } = renderOrder({ order: makeOrder({ id: 'pas-un-ulid' }) })

    expect(await screen.findByText('Commande introuvable')).toBeInTheDocument()
    expect(calls(all, 'GET', '/adv/orders/pas-un-ulid')).toHaveLength(0)
  })

  it('disables the actions while offline', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    renderOrder()

    await screen.findByRole('heading', { level: 1, name: 'Commande RMK-261008-00042' })
    expect(screen.getByRole('button', { name: 'Passer en préparation' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Annuler la commande' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Bon de commande (PDF)' })).toBeDisabled()
    expect(screen.getAllByText(/Hors ligne/).length).toBeGreaterThan(0)
  })

  it('rereads the order on the events of its day', async () => {
    const { calls: all } = renderOrder({ user: adv({ realtime: { key: 'app-key' } }) })
    await screen.findByRole('heading', { level: 1, name: 'Commande RMK-261008-00042' })
    await waitFor(() => expect(fake.listeners.get(`adv.${DAY}:.OrderShippingUpdated`)?.size).toBe(1))

    const reads = () => calls(all, 'GET', `/adv/orders/${ID1}`).length
    const before = reads()
    act(() => fake.listeners.get(`adv.${DAY}:.OrdersProcessed`)?.forEach((listener) => listener({ day: DAY, status: 'IN_PREPARATION', count: 3 })))
    await waitFor(() => expect(reads()).toBe(before + 1))
  })
})

describe('ADV — shipping (RG-A03, I-77)', () => {
  const shippedOrange = (qty: number) => orange({ shipped_qty: qty })

  it('shows the shipped quantity and the gap reason of the lines in preparation', async () => {
    renderOrder({ order: inPreparation() })

    const lines = within(await screen.findByRole('table', { name: 'Lignes de la commande' }))
    expect(lines.getByRole('columnheader', { name: 'Expédié' })).toBeInTheDocument()
    // Quantité non saisie : la validée est proposée (I-77).
    expect(lines.getByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })).toHaveValue('40')
    expect(lines.getByRole('textbox', { name: 'Quantité expédiée de Lben 1 L' })).toHaveValue('1000')
    expect(lines.queryByRole('combobox')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer l’expédition' })).toBeDisabled()
  })

  it('asks for a reason below the validated quantity, then saves a partial update', async () => {
    const user = userEvent.setup()
    const { calls: all } = renderOrder({
      order: inPreparation(),
      routes: { [shippingUrl(ID1)]: () => json(inPreparation({ version: 5, lines: [orange({ shipped_qty: 30, shipping_gap_reason: { code: 'RUPTURE', label: 'Rupture de stock' } }), lben()] })) },
    })
    const quantity = await screen.findByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })

    await user.clear(quantity)
    await user.type(quantity, '30')

    expect(screen.getByText('Motif obligatoire pour une quantité inférieure à la validée.')).toBeInTheDocument()
    expect(screen.getByText('Corrigez les lignes signalées avant d’enregistrer.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer l’expédition' })).toBeDisabled()

    await user.selectOptions(await screen.findByRole('combobox', { name: 'Motif d’écart de Jus orange 1 L' }), 'RUPTURE')
    expect(screen.queryByText('Motif obligatoire pour une quantité inférieure à la validée.')).not.toBeInTheDocument()
    expect(screen.getByText('Modifications non enregistrées')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Enregistrer l’expédition' }))

    expect(await screen.findByText('Expédition enregistrée.')).toBeInTheDocument()
    // Envoi partiel : seule la ligne modifiée, avec la version lue.
    expect(calls(all, 'PATCH', `/adv/orders/${ID1}/shipping`)[0]?.body).toEqual({
      version: 4,
      lines: [{ product_id: 42, shipped_qty: 30, reason_code: 'RUPTURE' }],
    })
    // Valeurs du serveur affichées, saisies abandonnées.
    expect(screen.getByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })).toHaveValue('30')
    expect(screen.queryByText('Modifications non enregistrées')).not.toBeInTheDocument()
  })

  it('refuses a quantity above the validated one and an unreadable one', async () => {
    const user = userEvent.setup()
    renderOrder({ order: inPreparation() })
    const quantity = await screen.findByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })

    await user.clear(quantity)
    await user.type(quantity, '41')
    expect(screen.getByText('Au plus la quantité validée.')).toBeInTheDocument()

    await user.clear(quantity)
    expect(screen.getByText('Quantité entière attendue.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer l’expédition' })).toBeDisabled()
  })

  it('drops the reason when the quantity goes back to the validated one', async () => {
    const user = userEvent.setup()
    const { calls: all } = renderOrder({
      order: inPreparation(),
      routes: { [shippingUrl(ID1)]: () => json(inPreparation({ version: 5 })) },
    })
    const quantity = await screen.findByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })
    await user.clear(quantity)
    await user.type(quantity, '10')
    await user.selectOptions(await screen.findByRole('combobox', { name: 'Motif d’écart de Jus orange 1 L' }), 'CASSE')

    await user.clear(quantity)
    await user.type(quantity, '40')

    expect(screen.queryByRole('combobox', { name: /Motif d’écart/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer l’expédition' })).toBeDisabled()
    expect(calls(all, 'PATCH', '/shipping')).toHaveLength(0)
  })

  it('saves the delivery note alone, empty to clear it', async () => {
    const user = userEvent.setup()
    const { calls: all } = renderOrder({
      order: prepared({ delivery_note_ref: 'BL-0001' }),
      routes: { [shippingUrl(ID1)]: () => json(prepared({ version: 6, delivery_note_ref: null })) },
    })
    const note = await screen.findByLabelText('Bon de livraison')
    expect(note).toHaveValue('BL-0001')

    await user.clear(note)
    await user.click(screen.getByRole('button', { name: 'Enregistrer l’expédition' }))

    await screen.findByText('Expédition enregistrée.')
    expect(calls(all, 'PATCH', '/shipping')[0]?.body).toEqual({ version: 5, delivery_note_ref: null })
  })

  it('lets only the delivery note change once shipped', async () => {
    const user = userEvent.setup()
    const { calls: all } = renderOrder({
      order: shipped(),
      routes: { [shippingUrl(ID1)]: () => json(shipped({ version: 8, delivery_note_ref: 'BL-0043' })) },
    })

    const lines = within(await screen.findByRole('table', { name: 'Lignes de la commande' }))
    expect(lines.queryByRole('textbox')).not.toBeInTheDocument()
    expect(lines.getByText('Rupture de stock')).toBeInTheDocument()
    expect(screen.getByText('Écart d’expédition')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Passer|Marquer|Annuler la commande/ })).not.toBeInTheDocument()

    const note = screen.getByLabelText('Bon de livraison')
    await user.clear(note)
    await user.type(note, 'BL-0043')
    await user.click(screen.getByRole('button', { name: 'Enregistrer l’expédition' }))

    await screen.findByText('Expédition enregistrée.')
    expect(calls(all, 'PATCH', '/shipping')[0]?.body).toEqual({ version: 7, delivery_note_ref: 'BL-0043' })
  })

  it('saves the pending input before the next step, with the new version', async () => {
    const user = userEvent.setup()
    const order: string[] = []
    const { calls: all } = renderOrder({
      order: inPreparation(),
      routes: {
        [shippingUrl(ID1)]: () => {
          order.push('PATCH')
          return json(inPreparation({ version: 5, lines: [orange({ shipped_qty: 35, shipping_gap_reason: { code: 'CASSE', label: 'Casse' } }), lben()] }))
        },
        [BULK_URL]: () => {
          order.push('POST')
          return json({ applied: [{ id: ID1, number: 'RMK-261008-00042', status: 'PREPARED', version: 6 }], refused: [] })
        },
      },
    })
    const quantity = await screen.findByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })
    await user.clear(quantity)
    await user.type(quantity, '35')
    await user.selectOptions(await screen.findByRole('combobox', { name: 'Motif d’écart de Jus orange 1 L' }), 'CASSE')
    expect(screen.getByText('Les saisies d’expédition sont enregistrées avant le changement de statut.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Marquer préparée' }))

    expect(await screen.findByText('Statut mis à jour : Préparée.')).toBeInTheDocument()
    expect(order).toEqual(['PATCH', 'POST'])
    expect(calls(all, 'POST', '/adv/orders/bulk-status')[0]?.body).toEqual({ status: 'PREPARED', orders: [{ id: ID1, version: 5 }] })
  })

  it('does not change the status when the pending input cannot be saved', async () => {
    const user = userEvent.setup()
    const { calls: all } = renderOrder({
      order: inPreparation(),
      routes: {
        [shippingUrl(ID1)]: () =>
          problem(422, 'VALIDATION_FAILED', 'Les données fournies ne sont pas valides.', { errors: { 'lines.0.shipped_qty': ['La quantité expédiée dépasse la quantité validée.'] } }),
      },
    })
    const quantity = await screen.findByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })
    await user.clear(quantity)
    await user.type(quantity, '39')
    await user.selectOptions(await screen.findByRole('combobox', { name: 'Motif d’écart de Jus orange 1 L' }), 'CASSE')

    await user.click(screen.getByRole('button', { name: 'Marquer préparée' }))

    // Le message du serveur est sur la ligne en cause.
    expect(await screen.findByText('La quantité expédiée dépasse la quantité validée.')).toBeInTheDocument()
    expect(calls(all, 'POST', '/adv/orders/bulk-status')).toHaveLength(0)

    // Le refus ne vaut que pour la saisie refusée : la corriger l'efface.
    await user.clear(quantity)
    await user.type(quantity, '38')
    expect(screen.queryByText('La quantité expédiée dépasse la quantité validée.')).not.toBeInTheDocument()
  })

  it('abandons the input and rereads the order after a version conflict', async () => {
    const user = userEvent.setup()
    let version = 4
    const { calls: all } = renderOrder({
      order: inPreparation(),
      routes: {
        [orderUrl(ID1)]: () => json(inPreparation({ version })),
        [shippingUrl(ID1)]: () => {
          version = 6
          return problem(409, 'VERSION_CONFLICT', 'La commande a été modifiée par un autre utilisateur.', { version: 6 })
        },
      },
    })
    const quantity = await screen.findByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })
    await user.clear(quantity)
    await user.type(quantity, '39')
    await user.selectOptions(await screen.findByRole('combobox', { name: 'Motif d’écart de Jus orange 1 L' }), 'CASSE')
    await user.click(screen.getByRole('button', { name: 'Enregistrer l’expédition' }))

    expect(await screen.findByText(/ressaisissez vos modifications/)).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })).toHaveValue('40')
    await waitFor(() => expect(calls(all, 'GET', `/adv/orders/${ID1}`).length).toBeGreaterThan(1))
  })

  it('warns that the order changed under an input in progress', async () => {
    const user = userEvent.setup()
    let current = inPreparation()
    const { queryClient } = renderOrder({ order: current, routes: { [orderUrl(ID1)]: () => json(current) } })
    const quantity = await screen.findByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })
    await user.clear(quantity)
    await user.type(quantity, '39')
    expect(screen.queryByText(/modifiée par un autre utilisateur/)).not.toBeInTheDocument()

    current = inPreparation({ version: 5, delivery_note_ref: 'BL-9' })
    await act(() => queryClient.invalidateQueries())

    expect(await screen.findByText(/modifiée par un autre utilisateur depuis le début de votre saisie/)).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })).toHaveValue('39')

    await user.click(screen.getByRole('button', { name: 'Annuler les saisies' }))
    expect(screen.getByRole('textbox', { name: 'Quantité expédiée de Jus orange 1 L' })).toHaveValue('40')
    expect(screen.queryByText(/modifiée par un autre utilisateur/)).not.toBeInTheDocument()
  })

  it('shows the shipped quantities read-only before preparation is over', async () => {
    renderOrder({ order: makeOrder({ lines: [shippedOrange(40), lben()] }) })

    const lines = within(await screen.findByRole('table', { name: 'Lignes de la commande' }))
    expect(lines.getByRole('columnheader', { name: 'Expédié' })).toBeInTheDocument()
    expect(lines.queryByRole('textbox')).not.toBeInTheDocument()
  })
})

describe('ADV — exceptional cancellation (RG-V07, I-75, I-76)', () => {
  it('cancels the order with a reason and a comment, and shows the result', async () => {
    const user = userEvent.setup()
    const cancelled = makeOrder({
      status: 'CANCELLED',
      status_label: 'Annulée',
      version: 4,
      can_cancel: false,
      next_statuses: [],
      decision_reason: { code: 'RUPTURE', label: 'Rupture de stock' },
      decision_comment: 'Lot bloqué',
      can: { next_statuses: [], edit_shipping_lines: false, edit_delivery_note: false, cancel: false, print: true },
    })
    const { calls: all } = renderOrder({ routes: { [cancelUrl(ID1)]: () => json(cancelled) } })

    await user.click(await screen.findByRole('button', { name: 'Annuler la commande' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Annuler la commande RMK-261008-00042' }))
    expect(dialog.getByText(/restituées au quota de la région/)).toBeInTheDocument()

    await user.selectOptions(await dialog.findByLabelText('Motif d’annulation'), 'RUPTURE')
    await user.type(dialog.getByLabelText('Commentaire'), 'Lot bloqué')
    await user.click(dialog.getByRole('button', { name: 'Annuler la commande' }))

    expect(await screen.findByText('Commande annulée : les quantités validées sont restituées au quota de la région.')).toBeInTheDocument()
    expect(calls(all, 'POST', `/adv/orders/${ID1}/cancel`)[0]?.body).toEqual({ version: 3, reason_code: 'RUPTURE', comment: 'Lot bloqué' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    // Détail à jour : plus d'action, motif de l'annulation affiché.
    expect(screen.queryByRole('button', { name: 'Annuler la commande' })).not.toBeInTheDocument()
    expect(screen.getByText('Motif : Rupture de stock')).toBeInTheDocument()
    expect(screen.getByText('Commentaire : Lot bloqué')).toBeInTheDocument()
  })

  it('requires a reason, and a comment with « Autre »', async () => {
    const user = userEvent.setup()
    const { calls: all } = renderOrder()
    await user.click(await screen.findByRole('button', { name: 'Annuler la commande' }))
    const dialog = within(await screen.findByRole('dialog'))

    await user.click(dialog.getByRole('button', { name: 'Annuler la commande' }))
    expect(await dialog.findByText('Choisissez un motif.')).toBeInTheDocument()

    await user.selectOptions(dialog.getByLabelText('Motif d’annulation'), 'AUTRE')
    await user.click(dialog.getByRole('button', { name: 'Annuler la commande' }))
    expect(await dialog.findByText('Commentaire obligatoire avec ce motif.')).toBeInTheDocument()
    expect(calls(all, 'POST', '/cancel')).toHaveLength(0)
  })

  it('shows the refusal inside the dialog, then rereads the order', async () => {
    const user = userEvent.setup()
    const { calls: all } = renderOrder({
      routes: { [cancelUrl(ID1)]: () => problem(409, 'INVALID_TRANSITION', 'Commande « Préparée » : impossible de passer à « Annulée ».') },
    })
    await user.click(await screen.findByRole('button', { name: 'Annuler la commande' }))
    const dialog = within(await screen.findByRole('dialog'))
    await user.selectOptions(await dialog.findByLabelText('Motif d’annulation'), 'DEMANDE_CLIENT')

    await user.click(dialog.getByRole('button', { name: 'Annuler la commande' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent('impossible de passer à « Annulée »')
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    await waitFor(() => expect(calls(all, 'GET', `/adv/orders/${ID1}`).length).toBeGreaterThan(1))
  })

  it('closes the dialog on « Retour » without cancelling', async () => {
    const user = userEvent.setup()
    const { calls: all } = renderOrder()
    await user.click(await screen.findByRole('button', { name: 'Annuler la commande' }))

    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Retour' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls(all, 'POST', '/cancel')).toHaveLength(0)
  })

  it('offers no cancellation once the order is prepared', async () => {
    renderOrder({ order: prepared() })

    await screen.findByRole('heading', { level: 1, name: 'Commande RMK-261008-00042' })
    expect(screen.getByRole('button', { name: 'Marquer expédiée' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Annuler la commande' })).not.toBeInTheDocument()
  })
})

describe('Admin — read-only detail (I-87)', () => {
  it('shows the order without any action, form or PDF', async () => {
    renderOrder({
      user: admin(),
      order: inPreparation({
        next_statuses: [],
        can_cancel: false,
        can: { next_statuses: [], edit_shipping_lines: false, edit_delivery_note: false, cancel: false, print: false },
      }),
    })

    expect(await screen.findByRole('heading', { level: 1, name: 'Commande RMK-261008-00042' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'File des commandes' })).toHaveAttribute('href', '/admin/commandes')
    expect(screen.getByRole('link', { name: '08/10/2026' })).toHaveAttribute('href', '/admin/journees/2026-10-08/suivi')
    expect(screen.queryAllByRole('button')).toHaveLength(0)
    expect(screen.queryByRole('button', { name: /Passer|Marquer|Annuler|Enregistrer|Bon de commande/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })
})
