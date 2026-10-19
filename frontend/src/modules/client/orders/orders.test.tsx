import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { formatAmount } from '../../../shared/format'
import { purgeSessionStorage } from '../../../shared/storage'
import { json } from '../../../test/utils'
import { LIST_URL, makePage, makeSummary, ordersApi, plain, renderOrders } from './testing'

afterEach(() => purgeSessionStorage())

const second = makeSummary({
  id: '01JA2Z6K8QW3X9M4T7RBN5CDEG',
  number: 'RMK-261007-00007',
  day: '2026-10-07',
  status: 'VALIDATED',
  status_label: 'Validée',
  version: 3,
  submitted_at: '2026-10-07T08:00:00+00:00',
  total_ttc: 12500.5,
  lines_count: 1,
  is_adjusted: true,
  can_modify: false,
  can_cancel: false,
})
const third = makeSummary({ id: '01JA2Z6K8QW3X9M4T7RBN5CDEH', number: 'RMK-261006-00003', day: '2026-10-06', status: 'EXPIRED', can_modify: false, can_cancel: false })

describe('my orders (EF-CLT-04)', () => {
  it('lists the orders, newest first, with their day, status, lines count and total TTC, each linking to its detail', async () => {
    const api = ordersApi({ [LIST_URL]: () => json(makePage([makeSummary(), second, third])) })
    renderOrders('/client/commandes')

    const list = await screen.findByRole('list', { name: 'Mes commandes, de la plus récente à la plus ancienne' })
    const links = within(list).getAllByRole('link')
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/client/commandes/01JA2Z6K8QW3X9M4T7RBN5CDEF',
      '/client/commandes/01JA2Z6K8QW3X9M4T7RBN5CDEG',
      '/client/commandes/01JA2Z6K8QW3X9M4T7RBN5CDEH',
    ])
    expect(links[0]).toHaveTextContent(plain(`RMK-261008-00042EnvoyéeCommande du 08/10/20263 produits${formatAmount(71026.95)} TTC`))
    expect(links[1]).toHaveTextContent(plain(`RMK-261007-00007ValidéeCommande du 07/10/20261 produit · Ajustée${formatAmount(12500.5)} TTC`))
    expect(within(links[2]!).getByText('Expirée')).toHaveAttribute('data-status', 'EXPIRED')

    // Une seule page : pas de pagination.
    expect(screen.queryByRole('navigation', { name: 'Pages de la liste' })).not.toBeInTheDocument()
    expect(api.queries('/api/v1/client/orders')).toEqual([{ page: '1' }])

    await userEvent.click(links[1]!)
    expect(screen.getByTestId('where')).toHaveTextContent('/client/commandes/01JA2Z6K8QW3X9M4T7RBN5CDEG')
  })

  it('invites to order from the catalogue when there is no order yet', async () => {
    ordersApi({ [LIST_URL]: () => json(makePage([])) })
    renderOrders('/client/commandes')

    expect(await screen.findByText('Vous n’avez encore envoyé aucune commande.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('link', { name: 'Voir le catalogue' }))
    expect(screen.getByTestId('where')).toHaveTextContent('/client/catalogue')
  })

  it('pages through the orders (?page=), 25 at a time', async () => {
    const api = ordersApi({
      [LIST_URL]: ({ url }) =>
        url.searchParams.get('page') === '2'
          ? json(makePage([third], { current_page: 2, last_page: 2, total: 26, from: 26, to: 26 }))
          : json(makePage([makeSummary(), second], { current_page: 1, last_page: 2, total: 26, from: 1, to: 25 })),
    })
    renderOrders('/client/commandes')

    const pages = await screen.findByRole('navigation', { name: 'Pages de la liste' })
    expect(pages).toHaveTextContent('Page 1 sur 2')
    expect(within(pages).getByRole('button', { name: /Page précédente/ })).toBeDisabled()

    await userEvent.click(within(pages).getByRole('button', { name: /Page suivante/ }))
    expect(await screen.findByRole('link', { name: /RMK-261006-00003/ })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /RMK-261008-00042/ })).not.toBeInTheDocument()
    expect(screen.getByTestId('where')).toHaveTextContent('/client/commandes?page=2')
    expect(screen.getByRole('navigation', { name: 'Pages de la liste' })).toHaveTextContent('Page 2 sur 2')
    expect(within(screen.getByRole('navigation', { name: 'Pages de la liste' })).getByRole('button', { name: /Page suivante/ })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: /Page précédente/ }))
    expect(await screen.findByRole('link', { name: /RMK-261008-00042/ })).toBeInTheDocument()
    expect(screen.getByTestId('where')).toHaveTextContent(/^\/client\/commandes$/)
    // Première page encore en cache : pas relue.
    expect(api.queries('/api/v1/client/orders').map((query) => query.page)).toEqual(['1', '2'])
  })

  it('opens on the page of the address and offers to go back to the first one past the last page', async () => {
    ordersApi({
      [LIST_URL]: ({ url }) =>
        url.searchParams.get('page') === '4'
          ? json(makePage([], { current_page: 4, last_page: 2, total: 26, from: null, to: null }))
          : json(makePage([makeSummary()], { current_page: 1, last_page: 2, total: 26 })),
    })
    renderOrders('/client/commandes?page=4')

    expect(await screen.findByText('Aucune commande sur cette page.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Revenir à la première page' }))
    expect(await screen.findByRole('link', { name: /RMK-261008-00042/ })).toBeInTheDocument()
  })

  it('shows the message passed by the previous screen (after a sending)', async () => {
    ordersApi({ [LIST_URL]: () => json(makePage([makeSummary()])) })
    renderOrders('/client/commandes', { notice: 'Commande RMK-261008-00042 envoyée.' })

    expect(await screen.findByRole('status')).toHaveTextContent('Commande RMK-261008-00042 envoyée.')
  })

  it('reports a loading error with a retry', async () => {
    let fail = true
    ordersApi({ [LIST_URL]: () => (fail ? json({ status: 500, code: 'SERVER_ERROR', detail: 'Erreur du serveur.' }, 500) : json(makePage([makeSummary()]))) })
    renderOrders('/client/commandes')

    expect(await screen.findByText('Erreur du serveur.', undefined, { timeout: 5000 })).toBeInTheDocument()
    fail = false
    await userEvent.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByRole('link', { name: /RMK-261008-00042/ })).toBeInTheDocument()
  })
})
