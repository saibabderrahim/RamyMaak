import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import type { Product, ProductFamily } from '../../../shared/api/types'
import { json, makeUser, mockApi, renderApp } from '../../../test/utils'

// RG-Q07 : un produit avec un quota publié > 0 sur la journée ouverte d'aujourd'hui
// n'est désactivé qu'à partir du lendemain (`pending_deactivation_on`).

const admin = makeUser('admin', { permissions: ['user.manage', 'client.manage', 'product.view', 'product.manage'] })
const adv = makeUser('adv', { permissions: ['region.view', 'client.view', 'product.view', 'quota.view'] })
const slow = { timeout: 5000 }

const families: ProductFamily[] = [{ id: 2, code: 'JUS', name: 'Jus', parent_id: null, sort_order: 10, products_count: 1 }]

const product: Product = {
  id: 42,
  sku: 'P0042',
  name: 'Jus orange 1 L',
  short_name: 'Orange 1 L',
  family: { id: 2, code: 'JUS', name: 'Jus' },
  format: '1 L',
  sales_unit_label: 'Fardeau 6 × 1 L',
  units_per_sales_unit: 6,
  sales_units_per_pallet: 80,
  ean: null,
  vat_rate: 19,
  is_active: true,
  pending_deactivation_on: null,
  is_new: false,
  is_featured: false,
  sort_order: 10,
  price: null,
  next_price: null,
  photos: [],
  created_at: '2026-10-01T08:00:00+00:00',
  updated_at: '2026-10-01T08:00:00+00:00',
}

const pending: Product = { ...product, pending_deactivation_on: '2026-10-11', updated_at: '2026-10-10T09:00:00+00:00' }

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } })

function catalogApi(user = admin, current: Product = product, patched: (body: Record<string, unknown>) => Product = () => current) {
  return mockApi({
    'GET /api/v1/auth/me': () => json(user),
    'GET /api/v1/notifications': () =>
      json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, unread_count: 0 } }),
    'GET /api/v1/product-families': () => json({ data: families }),
    'GET /api/v1/products': () => json(page([current])),
    'GET /api/v1/products/42': () => json(current),
    'GET /api/v1/products/42/prices': () => json({ data: [] }),
    'PATCH /api/v1/products/42': ({ body }) => json(patched(body as Record<string, unknown>)),
  })
}

beforeAll(async () => {
  await Promise.all([import('../../admin'), import('../../adv')])
}, 30_000)

afterEach(() => {
  vi.restoreAllMocks()
})

describe('back-office — scheduled product deactivation (RG-Q07)', () => {
  it('flags the scheduled deactivation in the products list', async () => {
    catalogApi(admin, pending)

    renderApp('/admin/produits')

    const row = (await screen.findByRole('link', { name: 'Jus orange 1 L' }, slow)).closest('tr')!
    expect(within(row).getByText('Actif')).toBeInTheDocument()
    expect(within(row).getByText('Désactivation programmée le 11/10/2026')).toBeInTheDocument()
    expect(row).toHaveTextContent('Un quota publié court aujourd’hui sur ce produit')
  })

  it('shows the scheduled deactivation instead of « saved » after deactivating a product with a published quota today', async () => {
    const { calls } = catalogApi(admin, product, (body) => ({
      ...product,
      ...body,
      // Le serveur garde le produit actif et programme la désactivation au lendemain.
      is_active: true,
      pending_deactivation_on: body.is_active === false ? '2026-10-11' : null,
      updated_at: '2026-10-10T09:00:00+00:00',
    }))
    const user = userEvent.setup()

    renderApp('/admin/produits/42')
    const active = await screen.findByLabelText('Produit actif (visible des clients)', undefined, slow)
    expect(active).toBeChecked()
    await user.click(active)
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(
      await screen.findByText(
        'Fiche produit enregistrée. Désactivation programmée le 11/10/2026 : un quota publié court aujourd’hui sur ce produit, il reste actif jusqu’à la fin de la journée (RG-Q07).',
      ),
    ).toBeInTheDocument()
    expect(screen.queryByText('Fiche produit enregistrée.')).not.toBeInTheDocument()
    expect(calls.find((call) => call.method === 'PATCH')?.body).toMatchObject({ is_active: false })
    expect(screen.getAllByText('Désactivation programmée le 11/10/2026').length).toBeGreaterThan(0)
    // La case reflète la désactivation demandée.
    expect(screen.getByLabelText('Produit actif (visible des clients)')).not.toBeChecked()
    expect(screen.getByText('Désactivation programmée le 11/10/2026 : cochez la case pour l’annuler.')).toBeInTheDocument()
  })

  it('explains a scheduled deactivation and keeps it when the sheet is saved again', async () => {
    const { calls } = catalogApi(admin, pending, (body) => ({ ...pending, ...body, is_active: true, updated_at: '2026-10-10T10:00:00+00:00' }))
    const user = userEvent.setup()

    renderApp('/admin/produits/42')

    expect(
      await screen.findByText(
        'Désactivation programmée le 11/10/2026 : un quota publié court aujourd’hui sur ce produit. Il reste actif et commandable jusqu’à la fin de la journée, puis sera désactivé automatiquement (RG-Q07).',
        undefined,
        slow,
      ),
    ).toBeInTheDocument()
    const active = await screen.findByLabelText('Produit actif (visible des clients)')
    expect(active).not.toBeChecked()
    expect(active).toHaveAccessibleDescription('Désactivation programmée le 11/10/2026 : cochez la case pour l’annuler.')

    // Autre modification : le statut n'est pas envoyé, la désactivation reste programmée.
    const shortName = screen.getByLabelText('Désignation courte')
    await user.clear(shortName)
    await user.type(shortName, 'Orange 1L')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText(/^Fiche produit enregistrée\. Désactivation programmée le 11\/10\/2026/)).toBeInTheDocument()
    const body = calls.find((call) => call.method === 'PATCH')?.body as Record<string, unknown>
    expect(body).toMatchObject({ short_name: 'Orange 1L' })
    expect(body).not.toHaveProperty('is_active')
  })

  it('cancels a scheduled deactivation when the product is ticked active again', async () => {
    const { calls } = catalogApi(admin, pending, (body) => ({
      ...pending,
      ...body,
      pending_deactivation_on: null,
      updated_at: '2026-10-10T10:00:00+00:00',
    }))
    const user = userEvent.setup()

    renderApp('/admin/produits/42')
    await user.click(await screen.findByLabelText('Produit actif (visible des clients)', undefined, slow))
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Fiche produit enregistrée.')).toBeInTheDocument()
    expect(calls.find((call) => call.method === 'PATCH')?.body).toMatchObject({ is_active: true })
    expect(screen.queryByText(/Désactivation programmée/)).not.toBeInTheDocument()
    expect(screen.getByLabelText('Produit actif (visible des clients)')).toBeChecked()
  })

  it('shows the scheduled deactivation on the read-only sheet', async () => {
    catalogApi(adv, pending)

    renderApp('/adv/produits/42')

    expect(await screen.findByText(/^Désactivation programmée le 11\/10\/2026 : un quota publié/, undefined, slow)).toBeInTheDocument()
    expect(screen.getAllByText('Désactivation programmée le 11/10/2026').length).toBeGreaterThan(0)
    expect(screen.queryByLabelText('Produit actif (visible des clients)')).not.toBeInTheDocument()
  })
})
