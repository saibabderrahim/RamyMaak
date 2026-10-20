import type { InfiniteData } from '@tanstack/react-query'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import type { RegionalOrder, RegionalOrdersPage } from '../../../../shared/api/types'
import { json } from '../../../../test/utils'
import { regionalOrdersListKey } from '../api'
import {
  CENTRE,
  COUNTS,
  DAY,
  decidable,
  EST,
  makeOrder,
  ORDER_ID,
  ORDERS_URL,
  orderUrl,
  ordersPage,
  REGIONS_URL,
  regional,
  rejectUrl,
  renderOrders,
  SECOND,
  summary,
  opened,
  validateUrl,
} from '../testing'

afterEach(() => window.dispatchEvent(new Event('online')))

const SECOND_ID = SECOND.id
const THIRD = summary({ id: '01JA2Z6K8QW3X9M4T7RBN5CDEH', number: 'RMK-261008-00044', submitted_at: '2026-10-08T09:30:00+00:00' })

/** Commande du client Grossiste Benali, envoyée à 10:05. */
const secondOrder = (extra: Partial<RegionalOrder> = {}) =>
  decidable({ id: SECOND_ID, number: 'RMK-261008-00043', submitted_at: '2026-10-08T09:05:00+00:00', client: { ...makeOrder().client, id: 47, code: 'C0047', name: 'Grossiste Benali' }, ...extra })

const roomy = (order: RegionalOrder): RegionalOrder => ({
  ...order,
  lines: order.lines.map((line) => ({ ...line, context: { ...line.context, max_validatable: line.requested_qty, region_remaining: 5000 } })),
})

const decided = (order: RegionalOrder, status: 'VALIDATED' | 'REJECTED' = 'VALIDATED'): RegionalOrder => ({
  ...order,
  status,
  status_label: status === 'VALIDATED' ? 'Validée' : 'Rejetée',
  version: order.version + 1,
  decided_at: '2026-10-08T10:15:00+00:00',
  decided_by: { id: 7, name: 'Karim Régional' },
  can_validate: false,
  can_reject: false,
  lines: order.lines.map((line) => ({ ...line, validated_qty: status === 'VALIDATED' ? line.requested_qty : null })),
})

const where = () => screen.getByTestId('where').textContent
const findRow = (number: string) => screen.findByRole('link', { name: number })

describe('regional order — after a decision', () => {
  it('offers the next order to validate, the oldest of the list it comes from', async () => {
    const user = userEvent.setup()
    const first = roomy(decidable())
    const second = roomy(secondOrder())
    // Liste non triée par le test : le serveur renvoie par heure d'envoi, la plus ancienne reste la première.
    const { queryClient, calls } = renderOrders({
      path: `/regional/commandes?day=${DAY}`,
      routes: {
        [ORDERS_URL]: () => json(ordersPage([summary(), SECOND, THIRD], { counts: { ...COUNTS, SUBMITTED: 3 } })),
        [orderUrl(ORDER_ID)]: () => json(first),
        [orderUrl(SECOND_ID)]: () => json(second),
        [validateUrl(ORDER_ID)]: () => json(decided(first)),
      },
    })

    await user.click(await findRow('RMK-261008-00042'))
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })
    await user.click(await screen.findByRole('button', { name: 'Valider' }))

    expect(await screen.findByText('Commande RMK-261008-00042 validée.')).toBeInTheDocument()
    const next = screen.getByRole('link', { name: /Commande suivante/ })
    expect(next).toHaveAccessibleName('Commande suivante — RMK-261008-00043')
    expect(next).toHaveAttribute('href', `/regional/commandes/${SECOND_ID}`)
    expect(screen.getByRole('link', { name: 'Retour à la liste' })).toHaveAttribute('href', `/regional/commandes?day=${DAY}`)
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(1)
    // La liste en cache passe la commande décidée à son nouveau statut (« Commande suivante » ne la reproposera pas)
    // et sera relue au retour de l'écran, la commande ayant quitté « À valider ».
    const key = regionalOrdersListKey(DAY, null, 'SUBMITTED')
    expect(queryClient.getQueryData<InfiniteData<RegionalOrdersPage>>(key)?.pages[0]?.data.map((order) => order.status)).toEqual(['VALIDATED', 'SUBMITTED', 'SUBMITTED'])
    expect(queryClient.getQueryState(key)?.isInvalidated).toBe(true)

    await user.click(next)
    expect(where()).toBe(`/regional/commandes/${SECOND_ID}`)
    expect(await screen.findByRole('heading', { name: 'Commande RMK-261008-00043' })).toBeInTheDocument()
    // Le compte rendu de la décision précédente ne suit pas.
    expect(screen.queryByText('Commande RMK-261008-00042 validée.')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Valider' })).toBeEnabled()
    // Le retour de cette commande reprend la même liste.
    expect(screen.getByRole('link', { name: /Commandes reçues/ })).toHaveAttribute('href', `/regional/commandes?day=${DAY}`)
  })

  it('goes on with the following order, and offers only the way back once the list is exhausted', async () => {
    const user = userEvent.setup()
    const first = roomy(decidable())
    const second = roomy(secondOrder())
    renderOrders({
      path: `/regional/commandes?day=${DAY}`,
      routes: {
        [ORDERS_URL]: () => json(ordersPage([summary(), SECOND], { counts: { ...COUNTS, SUBMITTED: 2 } })),
        [orderUrl(ORDER_ID)]: () => json(first),
        [orderUrl(SECOND_ID)]: () => json(second),
        [validateUrl(ORDER_ID)]: () => json(decided(first)),
        [rejectUrl(SECOND_ID)]: () => json(decided(second, 'REJECTED')),
      },
    })

    await user.click(await findRow('RMK-261008-00042'))
    await user.click(await screen.findByRole('button', { name: 'Valider' }))
    await user.click(await screen.findByRole('link', { name: /Commande suivante/ }))
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00043' })

    // Rejet de la dernière commande à valider : la commande décidée avant elle n'est plus proposée.
    await user.click(await screen.findByRole('button', { name: 'Rejeter' }))
    const dialog = await screen.findByRole('dialog', { name: 'Rejeter la commande RMK-261008-00043' })
    const select = within(dialog).getByRole('combobox', { name: 'Motif du rejet' })
    await waitFor(() => expect(select).toBeEnabled())
    await user.selectOptions(select, 'Doublon')
    await user.click(within(dialog).getByRole('button', { name: 'Rejeter la commande' }))

    expect(await screen.findByText('Commande RMK-261008-00043 rejetée.')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Commande suivante/ })).not.toBeInTheDocument()
    const back = screen.getByRole('link', { name: 'Retour à la liste' })
    expect(back).toHaveAttribute('href', `/regional/commandes?day=${DAY}`)

    await user.click(back)
    expect(where()).toBe(`/regional/commandes?day=${DAY}`)
  })

  it('stays within the region of the list it comes from', async () => {
    const user = userEvent.setup()
    const first = roomy(decidable())
    const estOrder = summary({ id: THIRD.id, number: 'RMK-261008-00044', region: EST, submitted_at: '2026-10-08T08:00:00+00:00' })
    renderOrders({
      path: `/regional/commandes?day=${DAY}&region=3`,
      routes: {
        [REGIONS_URL]: () => json(opened(DAY, [CENTRE, EST])),
        [ORDERS_URL]: ({ url }) =>
          json(url.searchParams.get('filter[region]') === '3' ? ordersPage([summary(), SECOND]) : ordersPage([estOrder, summary(), SECOND])),
        [orderUrl(ORDER_ID)]: () => json(first),
        [validateUrl(ORDER_ID)]: () => json(decided(first)),
      },
    })

    await user.click(await findRow('RMK-261008-00042'))
    await user.click(await screen.findByRole('button', { name: 'Valider' }))
    const next = await screen.findByRole('link', { name: /Commande suivante/ })
    expect(next).toHaveAccessibleName('Commande suivante — RMK-261008-00043')
    expect(screen.getByRole('link', { name: 'Retour à la liste' })).toHaveAttribute('href', `/regional/commandes?day=${DAY}&region=3`)
  })

  it('only offers the way back when the order was opened without a list (notification link)', async () => {
    const user = userEvent.setup()
    const order = roomy(decidable())
    renderOrders({ path: `/regional/commandes/${ORDER_ID}`, routes: { [orderUrl(ORDER_ID)]: () => json(order), [validateUrl(ORDER_ID)]: () => json(decided(order)) } })
    await user.click(await screen.findByRole('button', { name: 'Valider' }))

    expect(await screen.findByText('Commande RMK-261008-00042 validée.')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Commande suivante/ })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Retour à la liste' })).toHaveAttribute('href', `/regional/commandes?day=${DAY}`)
  })

  it('never proposes an order which is not waiting for a decision', async () => {
    const user = userEvent.setup()
    const order = roomy(decidable())
    renderOrders({
      path: `/regional/commandes?day=${DAY}`,
      routes: {
        [ORDERS_URL]: () => json(ordersPage([summary(), summary({ id: SECOND_ID, number: 'RMK-261008-00043', status: 'VALIDATED', status_label: 'Validée' })])),
        [orderUrl(ORDER_ID)]: () => json(order),
        [validateUrl(ORDER_ID)]: () => json(decided(order)),
      },
    })
    await user.click(await findRow('RMK-261008-00042'))
    await user.click(await screen.findByRole('button', { name: 'Valider' }))

    await screen.findByText('Commande RMK-261008-00042 validée.')
    expect(screen.queryByRole('link', { name: /Commande suivante/ })).not.toBeInTheDocument()
  })
})

describe('regional order — decision taken', () => {
  it('shows a validation with adjustment: author, date, adjusted lines, reason labels and comments', async () => {
    const order = decided(roomy(decidable()))
    const adjusted: RegionalOrder = {
      ...order,
      is_adjusted: true,
      lines: order.lines.map((line) =>
        line.product_id === 57
          ? {
              ...line,
              validated_qty: 700,
              adjustment_reason_code: 'QUOTA_INSUFFISANT',
              adjustment_reason: { code: 'QUOTA_INSUFFISANT', label: 'Quota insuffisant' },
              adjustment_comment: 'Reste région limité',
            }
          : line,
      ),
    }
    renderOrders({ path: `/regional/commandes/${ORDER_ID}`, routes: { [orderUrl(ORDER_ID)]: () => json(adjusted) } })
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })

    const decision = screen.getByRole('heading', { name: 'Décision' }).closest('section')!
    expect(within(decision).getByText('Validée le 08/10/2026 11:15 par Karim Régional')).toBeInTheDocument()
    expect(within(decision).getByText('Validée avec ajustement')).toBeInTheDocument()
    expect(within(decision).getByText('1 ligne réduite')).toBeInTheDocument()
    expect(screen.getAllByText('Validée avec ajustement')).toHaveLength(1)

    const lben = screen.getByRole('list', { name: 'Produits commandés' }).querySelectorAll(':scope > li')[1] as HTMLElement
    expect(lben).toHaveTextContent('Quantité réduite de 1 250 à 700 · Motif : Quota insuffisant')
    expect(lben).toHaveTextContent('« Reste région limité »')
    expect(lben).toHaveTextContent('Validé : 700')
    // La ligne entière ne cite ni motif ni quantité réduite.
    expect(screen.getByRole('list', { name: 'Produits commandés' }).querySelectorAll(':scope > li')[0]).not.toHaveTextContent('Quantité réduite')
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('shows a rejection with its reason and its comment, the reason labelled even if it was deactivated since', async () => {
    const order: RegionalOrder = {
      ...decided(roomy(decidable()), 'REJECTED'),
      decision_reason_code: 'ANCIEN_MOTIF',
      decision_reason: { code: 'ANCIEN_MOTIF', label: 'Ancien motif' },
      decision_comment: 'Plus de stock',
    }
    renderOrders({ path: `/regional/commandes/${ORDER_ID}`, routes: { [orderUrl(ORDER_ID)]: () => json(order) } })
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })

    const decision = screen.getByRole('heading', { name: 'Décision' }).closest('section')!
    expect(within(decision).getByText('Rejetée le 08/10/2026 11:15 par Karim Régional')).toBeInTheDocument()
    expect(within(decision).getByText('Motif du rejet : Ancien motif')).toBeInTheDocument()
    expect(within(decision).getByText('Commentaire : Plus de stock')).toBeInTheDocument()
    expect(within(decision).queryByText('Validée avec ajustement')).not.toBeInTheDocument()
  })

  it('falls back on the humanized code when the server gives no label (data of an earlier lot)', async () => {
    const order: RegionalOrder = {
      ...decided(roomy(decidable()), 'REJECTED'),
      decision_reason_code: 'QUOTA_EPUISE',
      decision_reason: null,
      decided_by: null,
    }
    renderOrders({ path: `/regional/commandes/${ORDER_ID}`, routes: { [orderUrl(ORDER_ID)]: () => json(order) } })
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })
    // Sans libellé du serveur : le code rendu lisible (les accents ne se devinent pas).
    expect(screen.getByText('Motif du rejet : Quota epuise')).toBeInTheDocument()
    expect(screen.getByText('Rejetée le 08/10/2026 11:15')).toBeInTheDocument()
  })

  it('shows the cancellation by the ADV with its reason', async () => {
    const order: RegionalOrder = {
      ...decided(roomy(decidable())),
      status: 'CANCELLED',
      status_label: 'Annulée',
      decision_reason_code: 'RUPTURE',
      decision_reason: { code: 'RUPTURE', label: 'Rupture de stock' },
      decision_comment: 'Palette endommagée',
    }
    renderOrders({ path: `/regional/commandes/${ORDER_ID}`, routes: { [orderUrl(ORDER_ID)]: () => json(order) } })
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })
    expect(screen.getByText('Motif de l’annulation : Rupture de stock')).toBeInTheDocument()
    expect(screen.getByText('Commentaire : Palette endommagée')).toBeInTheDocument()
  })

  it('stays read-only for an order sent which the regional may not decide', async () => {
    renderOrders({ path: `/regional/commandes/${ORDER_ID}`, user: regional(), routes: { [orderUrl(ORDER_ID)]: () => json(makeOrder()) } })
    await screen.findByRole('heading', { name: 'Commande RMK-261008-00042' })

    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Décision sur la commande' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Décision' })).not.toBeInTheDocument()
    expect(screen.getByRole('list', { name: 'Produits commandés' })).toHaveTextContent('Demandé : 40')
  })
})
