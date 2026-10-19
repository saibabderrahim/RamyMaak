import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import type { Order } from '../../../shared/api/types'
import { formatAmount } from '../../../shared/format'
import { purgeSessionStorage } from '../../../shared/storage'
import { json, problem } from '../../../test/utils'
import { closedOrder, makeLine, makeOrder, ORDER_ID, ORDER_URL, orangeLine, ordersApi, plain, pommeLine, renderOrders } from './testing'

afterEach(() => purgeSessionStorage())

const PATH = `/client/commandes/${ORDER_ID}`

async function renderDetail(order: Order, state?: unknown) {
  const api = ordersApi({ [ORDER_URL]: () => json(order) })
  renderOrders(PATH, state)
  await screen.findByRole('heading', { level: 1, name: `Commande ${order.number}` })
  return api
}

/** Étapes de la frise : libellé (avec l'état lu par les lecteurs d'écran) et étape actuelle. */
function timeline() {
  const steps = within(screen.getByRole('region', { name: 'Suivi de la commande' })).getAllByRole('listitem')
  // Texte visible : sans la marque décorative ni l'état réservé aux lecteurs d'écran.
  const label = (step: HTMLElement) => (step.textContent ?? '').replace(/^[✓●×]/, '').replace(/ — étape (franchie|actuelle|à venir)/, '')
  return {
    labels: steps.map(label),
    current: steps.filter((step) => step.getAttribute('aria-current') === 'step').map(label),
    states: steps.map((step) => step.getAttribute('data-state')),
  }
}

const history = () =>
  within(screen.getByRole('region', { name: 'Historique' }))
    .getAllByRole('listitem')
    .map((item) => plain(item.textContent ?? ''))

const SENT = { from: null, to: 'SUBMITTED', version: 1, at: '2026-10-08T08:40:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0012', comment: null } as const

describe('order detail (EF-CLT-04)', () => {
  it('shows a submitted order: day, status, sending, deadline, timeline, lines, totals, history and actions', async () => {
    await renderDetail(makeOrder({ comment: 'Livraison avant 10 h' }))

    const overview = screen.getByRole('region', { name: 'Commande du jeudi 08/10/2026' })
    expect(within(overview).getByText('Envoyée')).toHaveAttribute('data-status', 'SUBMITTED')
    expect(overview).toHaveTextContent('Envoyée le 08/10/2026 09:40 par Gérant C0012')
    expect(overview).toHaveTextContent('Modifiable ou annulable jusqu’à 13:00.')
    const actions = within(overview).getByRole('group', { name: 'Actions sur la commande' })
    expect(within(actions).getAllByRole('button').map((button) => button.textContent)).toEqual([
      'Modifier la commande',
      'Annuler la commande',
      'Recommander',
    ])

    expect(timeline()).toEqual({
      labels: ['Envoyée08/10/2026 09:40', 'Validée', 'En préparation', 'Préparée', 'Expédiée'],
      current: ['Envoyée08/10/2026 09:40'],
      states: ['current', 'upcoming', 'upcoming', 'upcoming', 'upcoming'],
    })
    expect(screen.getByRole('region', { name: 'Suivi de la commande' })).toHaveTextContent('Envoyée — étape actuelle')

    const orange = screen.getByRole('article', { name: 'Jus orange 1 L' })
    expect(within(orange).getByRole('img', { name: 'Jus orange 1 L' })).toHaveAttribute('src', '/media/p42-200.webp')
    expect(orange).toHaveTextContent(plain(`Fardeau 6 × 1 L · ${formatAmount(900)} HT l’unité de vente`))
    expect(orange).toHaveTextContent(plain(`Demandée40Montant HT${formatAmount(36000)}`))
    expect(orange).not.toHaveTextContent('Validée')
    expect(orange).not.toHaveTextContent('Expédiée')
    // Lben : pas de photo, visuel par défaut.
    expect(within(screen.getByRole('article', { name: 'Lben 1 L' })).getByRole('img', { name: 'Lben 1 L' })).not.toHaveAttribute('src')

    expect(screen.getByRole('region', { name: 'Votre commentaire' })).toHaveTextContent('Livraison avant 10 h')
    const totals = screen.getByRole('region', { name: 'Montants' })
    expect(totals).toHaveTextContent(plain(`Total HT${formatAmount(59805)}TVA${formatAmount(11221.95)}Total TTC${formatAmount(71026.95)}`))
    expect(totals).toHaveTextContent('Montants indicatifs : la facture fait foi.')
    expect(history()).toEqual(['08/10/2026 09:40Commande envoyéepar Gérant C0012'])
  })

  it('shows the validated and adjusted quantities with their reason, and the shipped ones', async () => {
    await renderDetail(
      closedOrder({
        status: 'SHIPPED',
        status_label: 'Expédiée',
        version: 4,
        is_adjusted: true,
        decided_at: '2026-10-08T10:15:00+00:00',
        total_ht: 49500,
        lines: [
          makeLine({ ...orangeLine, validated_qty: 30, shipped_qty: 28, amount_ht: 27000, adjustment_reason_code: 'QUOTA_INSUFFISANT' }),
          makeLine({ ...pommeLine, validated_qty: 25, shipped_qty: 25 }),
        ],
        history: [
          SENT,
          { from: 'SUBMITTED', to: 'SUBMITTED', version: 2, at: '2026-10-08T09:05:00+00:00', actor: 'CLIENT', by_name: 'Associé C0012', comment: null },
          { from: 'SUBMITTED', to: 'VALIDATED', version: 3, at: '2026-10-08T10:15:00+00:00', actor: 'REGIONAL', by_name: null, comment: 'Reste région limité' },
          { from: 'VALIDATED', to: 'IN_PREPARATION', version: 4, at: '2026-10-09T06:00:00+00:00', actor: 'ADV', by_name: null, comment: null },
          { from: 'IN_PREPARATION', to: 'PREPARED', version: 5, at: '2026-10-09T09:00:00+00:00', actor: 'ADV', by_name: null, comment: null },
          { from: 'PREPARED', to: 'SHIPPED', version: 6, at: '2026-10-10T07:30:00+00:00', actor: 'ADV', by_name: null, comment: null },
        ],
      }),
    )

    expect(screen.getByText('Des quantités ont été ajustées à la validation : l’écart figure sur chaque ligne concernée.')).toBeInTheDocument()
    const orange = screen.getByRole('article', { name: 'Jus orange 1 L' })
    expect(orange).toHaveTextContent(plain(`Demandée40Validée30Expédiée28Montant HT${formatAmount(27000)}`))
    expect(orange).toHaveTextContent('Quantité réduite de 40 à 30 · Motif : Quota insuffisant')
    const pomme = screen.getByRole('article', { name: 'Jus pomme 1 L' })
    expect(pomme).toHaveTextContent('Demandée25Validée25Expédiée25')
    expect(pomme).not.toHaveTextContent('Quantité réduite')

    expect(timeline()).toEqual({
      labels: ['Envoyée08/10/2026 09:40', 'Validée08/10/2026 11:15', 'En préparation09/10/2026 07:00', 'Préparée09/10/2026 10:00', 'Expédiée10/10/2026 08:30'],
      current: ['Expédiée10/10/2026 08:30'],
      states: ['done', 'done', 'done', 'done', 'current'],
    })
    expect(history()).toEqual([
      '08/10/2026 09:40Commande envoyéepar Gérant C0012',
      '08/10/2026 10:05Commande modifiée (version 2)par Associé C0012',
      '08/10/2026 11:15Commande validéepar votre commercial régionalReste région limité',
      '09/10/2026 07:00Préparation commencéepar l’administration des ventes',
      '09/10/2026 10:00Commande préparéepar l’administration des ventes',
      '10/10/2026 08:30Commande expédiéepar l’administration des ventes',
    ])

    // Plus d'action du client sur une commande validée, sauf « Recommander ».
    const actions = screen.getByRole('group', { name: 'Actions sur la commande' })
    expect(within(actions).getAllByRole('button').map((button) => button.textContent)).toEqual(['Recommander'])
    expect(screen.queryByText(/Modifiable/)).not.toBeInTheDocument()
  })

  it('shows a validated order on its Validée step', async () => {
    await renderDetail(
      closedOrder({
        status: 'VALIDATED',
        history: [SENT, { from: 'SUBMITTED', to: 'VALIDATED', version: 2, at: '2026-10-08T10:15:00+00:00', actor: 'REGIONAL', by_name: null, comment: null }],
      }),
    )
    expect(timeline().current).toEqual(['Validée08/10/2026 11:15'])
    expect(timeline().states).toEqual(['done', 'current', 'upcoming', 'upcoming', 'upcoming'])
  })

  it('shows a rejected order with the reason and comment of the decision', async () => {
    await renderDetail(
      closedOrder({
        status: 'REJECTED',
        status_label: 'Rejetée',
        version: 2,
        decided_at: '2026-10-08T10:15:00+00:00',
        decision_reason_code: 'RUPTURE_STOCK',
        decision_comment: 'Plus de stock cette semaine.',
        history: [SENT, { from: 'SUBMITTED', to: 'REJECTED', version: 2, at: '2026-10-08T10:15:00+00:00', actor: 'REGIONAL', by_name: null, comment: 'Plus de stock cette semaine.' }],
      }),
    )

    const decision = screen.getByRole('region', { name: 'Motif du rejet' })
    expect(decision).toHaveTextContent('Motif : Rupture stock')
    expect(decision).toHaveTextContent('Commentaire : Plus de stock cette semaine.')
    expect(decision).toHaveTextContent('Le 08/10/2026 11:15')
    expect(timeline()).toEqual({
      labels: ['Envoyée08/10/2026 09:40', 'Rejetée08/10/2026 11:15'],
      current: ['Rejetée08/10/2026 11:15'],
      states: ['done', 'current'],
    })
    expect(history().at(-1)).toBe('08/10/2026 11:15Commande rejetéepar votre commercial régionalPlus de stock cette semaine.')
    expect(within(screen.getByRole('region', { name: 'Commande du jeudi 08/10/2026' })).getByText('Rejetée')).toHaveAttribute('data-status', 'REJECTED')
  })

  it('shows an order expired at the closing of the day', async () => {
    await renderDetail(
      closedOrder({
        status: 'EXPIRED',
        history: [SENT, { from: 'SUBMITTED', to: 'EXPIRED', version: 2, at: '2026-10-08T22:00:00+00:00', actor: 'SYSTEM', by_name: null, comment: null }],
      }),
    )

    expect(timeline()).toEqual({
      labels: ['Envoyée08/10/2026 09:40', 'Expirée08/10/2026 23:00'],
      current: ['Expirée08/10/2026 23:00'],
      states: ['done', 'current'],
    })
    expect(history().at(-1)).toBe('08/10/2026 23:00Commande expirée à la clôture de la journéeautomatiquement')
    expect(screen.queryByRole('region', { name: /Motif/ })).not.toBeInTheDocument()
  })

  it('shows an order cancelled by the client', async () => {
    await renderDetail(
      closedOrder({
        status: 'CANCELLED',
        history: [SENT, { from: 'SUBMITTED', to: 'CANCELLED', version: 2, at: '2026-10-08T09:30:00+00:00', actor: 'CLIENT', by_name: 'Gérant C0012', comment: null }],
      }),
    )

    expect(timeline()).toEqual({
      labels: ['Envoyée08/10/2026 09:40', 'Annulée08/10/2026 10:30'],
      current: ['Annulée08/10/2026 10:30'],
      states: ['done', 'current'],
    })
    expect(history().at(-1)).toBe('08/10/2026 10:30Commande annuléepar Gérant C0012')
  })

  it('lets a blocked client cancel, not modify, until the deadline', async () => {
    await renderDetail(makeOrder({ can_modify: false }))

    const overview = screen.getByRole('region', { name: 'Commande du jeudi 08/10/2026' })
    expect(overview).toHaveTextContent('Annulable jusqu’à 13:00.')
    expect(within(overview).getAllByRole('button').map((button) => button.textContent)).toEqual(['Annuler la commande', 'Recommander'])
  })

  it('shows the message passed by the cart after a sending', async () => {
    await renderDetail(makeOrder(), { notice: 'Commande RMK-261008-00042 envoyée.' })
    expect(screen.getAllByRole('status').find((status) => status.textContent === 'Commande RMK-261008-00042 envoyée.')).toBeDefined()
  })

  it('says the order is not found (unknown, or another client’s) with a way back', async () => {
    ordersApi({ [ORDER_URL]: () => problem(404, 'NOT_FOUND', 'Commande introuvable.') })
    renderOrders(PATH)

    expect(await screen.findByRole('heading', { level: 1, name: 'Commande introuvable' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('link', { name: 'Retour à mes commandes' }))
    expect(screen.getByTestId('where')).toHaveTextContent(/^\/client\/commandes$/)
  })
})
