import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import type { Product, ProductFamily, ProductPhoto, ProductPrice } from '../../../shared/api/types'
import { businessToday, formatAmount } from '../../../shared/format'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'
import { mockApiWithUploads } from '../imports/testUtils'

const admin = makeUser('admin', { permissions: ['user.manage', 'client.manage', 'product.view', 'product.manage'] })
const adv = makeUser('adv', { permissions: ['region.view', 'client.view', 'product.view'] })

/** Montant tel que lu à l'écran (espaces insécables normalisés par Testing Library). */
const amount = (value: number) => formatAmount(value).replace(/\s/g, ' ')

function shiftDay(day: string, days: number): string {
  const date = new Date(`${day}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

const today = businessToday()

const photo = (id: number, isMain: boolean): ProductPhoto => ({
  id,
  is_main: isMain,
  urls: { thumb: `/media/${id}/p0042-thumb.webp`, medium: `/media/${id}/p0042-medium.webp`, large: `/media/${id}/p0042-large.webp` },
})

const families: ProductFamily[] = [
  { id: 2, code: 'JUS', name: 'Jus', parent_id: null, sort_order: 10, products_count: 4 },
  { id: 3, code: 'NECTAR', name: 'Nectars', parent_id: 2, sort_order: 20, products_count: 1 },
  { id: 4, code: 'LAIT', name: 'Produits laitiers', parent_id: null, sort_order: 30, products_count: 2 },
]

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
  ean: '6130000000420',
  vat_rate: 19,
  is_active: true,
  pending_deactivation_on: null,
  is_new: true,
  is_featured: false,
  sort_order: 10,
  price: { price_ht: 900, price_ttc: 1071, unit_price_ht: 150, valid_from: shiftDay(today, -8) },
  next_price: { price_ht: 950, valid_from: shiftDay(today, 23) },
  photos: [photo(31, true)],
  created_at: '2026-10-01T08:00:00+00:00',
  updated_at: '2026-10-01T08:00:00+00:00',
}

const lben: Product = {
  ...product,
  id: 43,
  sku: 'P0103',
  name: 'Lben 1 L',
  short_name: 'Lben 1 L',
  family: { id: 4, code: 'LAIT', name: 'Produits laitiers' },
  is_active: false,
  is_new: false,
  price: null,
  next_price: null,
  photos: [],
}

const history: ProductPrice[] = [
  { id: 11, price_ht: 950, valid_from: shiftDay(today, 23), valid_to: null, created_by: { id: 1, username: 'admin', name: 'Admin Ramy' }, created_at: '2026-10-05T09:00:00+00:00' },
  { id: 10, price_ht: 900, valid_from: shiftDay(today, -8), valid_to: shiftDay(today, 22), created_by: null, created_at: '2026-09-20T09:00:00+00:00' },
  { id: 9, price_ht: 850.5, valid_from: shiftDay(today, -60), valid_to: shiftDay(today, -9), created_by: null, created_at: '2026-08-01T09:00:00+00:00' },
]

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } })

const catalogRoutes = (user = admin) => ({
  'GET /api/v1/auth/me': () => json(user),
  'GET /api/v1/product-families': () => json({ data: families }),
  'GET /api/v1/products': () => json(page([product, lben])),
  'GET /api/v1/products/42': () => json(product),
  'GET /api/v1/products/42/prices': () => json({ data: history }),
})

// Modules Admin et ADV chargés à la demande : compilés une fois avant les tests.
beforeAll(async () => {
  await Promise.all([import('../../admin'), import('../../adv')])
}, 30_000)

afterEach(() => {
  vi.restoreAllMocks()
})

describe('back-office — products list', () => {
  it('lists products with thumbnail, prices HT and TTC and status', async () => {
    const { fetchMock } = mockApi(catalogRoutes())
    const user = userEvent.setup()

    renderApp('/admin/produits')

    const row = (await screen.findByRole('link', { name: 'Jus orange 1 L' })).closest('tr')!
    expect(row.querySelector('img')).toHaveAttribute('src', '/media/31/p0042-thumb.webp')
    expect(row.querySelector('img')).toHaveAttribute('loading', 'lazy')
    expect(row.querySelector('img')).toHaveAttribute('width', '48')
    expect(within(row).getByText('P0042')).toBeInTheDocument()
    expect(within(row).getByText('Fardeau 6 × 1 L')).toBeInTheDocument()
    expect(within(row).getByText(amount(900))).toBeInTheDocument()
    expect(within(row).getByText(amount(1071))).toBeInTheDocument()
    expect(within(row).getByText('Actif')).toBeInTheDocument()
    expect(within(row).getByText('Nouveau')).toBeInTheDocument()

    const other = screen.getByRole('link', { name: 'Lben 1 L' }).closest('tr')!
    expect(within(other).getByTestId('product-placeholder')).toBeInTheDocument()
    expect(other.querySelector('img')).toBeNull()
    expect(within(other).getAllByText('—')).toHaveLength(2)
    expect(within(other).getByText('Inactif')).toBeInTheDocument()

    expect(screen.getByRole('button', { name: 'Nouveau produit' })).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Famille'), '2')
    await user.selectOptions(screen.getByLabelText('Statut'), '0')
    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(([input]) => {
          const url = String(input)
          return url.includes('filter%5Bfamily_id%5D=2') && url.includes('filter%5Bis_active%5D=0')
        }),
      ).toBe(true),
    )
  })
})

describe('back-office — product creation', () => {
  it('creates a product and opens its page', async () => {
    const created = { ...product, short_name: 'Jus orange 1 L', photos: [], price: null, next_price: null, sort_order: 0 }
    const { calls } = mockApi({
      ...catalogRoutes(),
      'POST /api/v1/products': () => json(created, 201),
      'GET /api/v1/products/42': () => json(created),
      'GET /api/v1/products/42/prices': () => json({ data: [] }),
    })
    const user = userEvent.setup()

    renderApp('/admin/produits/nouveau')
    await user.type(await screen.findByLabelText('Code article'), 'p0042')
    await user.selectOptions(screen.getByLabelText('Famille'), '2')
    await user.type(screen.getByLabelText('Désignation'), 'Jus orange 1 L')
    await user.type(screen.getByLabelText('Format'), '1 L')
    await user.type(screen.getByLabelText('Libellé de l’UV'), 'Fardeau 6 × 1 L')
    await user.type(screen.getByLabelText('Unités par UV'), '6')
    await user.type(screen.getByLabelText('UV par palette'), '80')
    expect(screen.getByLabelText('Taux de TVA (%)')).toHaveValue('19')
    await user.click(screen.getByLabelText('Badge « Nouveau »'))
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/admin/produits/42'))
    expect(calls.find((c) => c.method === 'POST' && c.path === '/api/v1/products')?.body).toEqual({
      sku: 'P0042',
      name: 'Jus orange 1 L',
      short_name: null,
      family_id: 2,
      format: '1 L',
      sales_unit_label: 'Fardeau 6 × 1 L',
      units_per_sales_unit: 6,
      sales_units_per_pallet: 80,
      ean: null,
      vat_rate: 19,
      is_active: true,
      is_new: true,
      is_featured: false,
      sort_order: 0,
    })
    expect(await screen.findByText('Produit créé : ajoutez maintenant ses photos et son prix.')).toBeInTheDocument()
  })

  it('checks the form before sending and shows server errors on the fields', async () => {
    const { calls } = mockApi({
      ...catalogRoutes(),
      'POST /api/v1/products': () =>
        problem(422, 'VALIDATION_FAILED', 'La valeur du champ code article est déjà utilisée.', {
          errors: { sku: ['La valeur du champ code article est déjà utilisée.'] },
        }),
    })
    const user = userEvent.setup()

    renderApp('/admin/produits/nouveau')
    await user.click(await screen.findByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Choisissez une famille.')).toBeInTheDocument()
    expect(screen.getByLabelText('Code article')).toHaveAttribute('aria-invalid', 'true')
    expect(calls.some((c) => c.method === 'POST')).toBe(false)

    await user.type(screen.getByLabelText('Code article'), 'P0042')
    await user.selectOptions(screen.getByLabelText('Famille'), '3')
    await user.type(screen.getByLabelText('Désignation'), 'Nectar abricot')
    await user.type(screen.getByLabelText('Libellé de l’UV'), 'Fardeau 6 × 1 L')
    await user.type(screen.getByLabelText('Unités par UV'), '6')
    await user.type(screen.getByLabelText('Code EAN'), '123')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('8 à 14 chiffres.')).toBeInTheDocument()

    await user.clear(screen.getByLabelText('Code EAN'))
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('La valeur du champ code article est déjà utilisée.')).toBeInTheDocument()
    expect(screen.getByLabelText('Code article')).toHaveAttribute('aria-invalid', 'true')
  })

  it('updates the product sheet without sending the code', async () => {
    const { calls } = mockApi({
      ...catalogRoutes(),
      'PATCH /api/v1/products/42': ({ body }) => json({ ...product, ...(body as object), updated_at: '2026-10-09T10:00:00+00:00' }),
    })
    const user = userEvent.setup()

    renderApp('/admin/produits/42')
    const shortName = await screen.findByLabelText('Désignation courte')
    expect(screen.getByLabelText('Code article')).toBeDisabled()
    await user.clear(shortName)
    await user.type(shortName, 'Orange 1L')
    await user.click(screen.getByLabelText('Badge « Mis en avant »'))
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Fiche produit enregistrée.')).toBeInTheDocument()
    const body = calls.find((c) => c.method === 'PATCH')?.body as Record<string, unknown>
    expect(body).not.toHaveProperty('sku')
    expect(body).toMatchObject({ short_name: 'Orange 1L', is_featured: true, family_id: 2, vat_rate: 19, units_per_sales_unit: 6 })
  })
})

describe('back-office — product prices', () => {
  it('shows the current and next prices, the history, and adds a price', async () => {
    const { calls } = mockApi({
      ...catalogRoutes(),
      'POST /api/v1/products/42/prices': ({ body }) =>
        json({ id: 12, ...(body as object), valid_to: null, created_by: null, created_at: '2026-10-09T10:00:00+00:00' }, 201),
    })
    const user = userEvent.setup()

    renderApp('/admin/produits/42')

    const current = await screen.findByTestId('current-price')
    expect(current).toHaveTextContent(`${amount(900)} HT`)
    expect(current).toHaveTextContent(`${amount(1071)} TTC · TVA 19 %`)
    expect(current).toHaveTextContent(`Prix unitaire indicatif : ${amount(150)} HT (6 unités par UV)`)
    expect(screen.getByTestId('next-price')).toHaveTextContent(`${amount(950)} HT à partir du`)

    const rows = within(await screen.findByTestId('price-history')).getAllByRole('row')
    expect(rows).toHaveLength(3)
    expect(within(rows[0]!).getByText('Programmé')).toBeInTheDocument()
    expect(within(rows[0]!).getByText('Sans fin')).toBeInTheDocument()
    expect(within(rows[0]!).getByText('Admin Ramy')).toBeInTheDocument()
    expect(within(rows[1]!).getByText('En vigueur')).toBeInTheDocument()
    expect(within(rows[2]!).getByText('Échu')).toBeInTheDocument()
    expect(within(rows[2]!).getByText(amount(850.5))).toBeInTheDocument()

    const date = screen.getByLabelText('Date d’effet')
    expect(date).toHaveValue(today)
    await user.type(screen.getByLabelText('Prix HT par UV (DA)'), '1 020,5')
    await user.clear(date)
    await user.type(date, '2026-12-01')
    await user.click(screen.getByRole('button', { name: 'Enregistrer le prix' }))

    expect(await screen.findByText('Prix enregistré.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ price_ht: 1020.5, valid_from: '2026-12-01' })
    // Fiche et historique relus : le prix en vigueur ou le prochain prix peut avoir changé.
    await waitFor(() => expect(calls.filter((c) => c.method === 'GET' && c.path === '/api/v1/products/42/prices')).toHaveLength(2))
    expect(screen.getByLabelText('Prix HT par UV (DA)')).toHaveValue('')
  })

  it('rejects an amount with more than two decimals', async () => {
    const { calls } = mockApi(catalogRoutes())
    const user = userEvent.setup()

    renderApp('/admin/produits/42')
    await user.type(await screen.findByLabelText('Prix HT par UV (DA)'), '12,345')
    await user.click(screen.getByRole('button', { name: 'Enregistrer le prix' }))

    expect(await screen.findByText('Montant positif ou nul, deux décimales au plus.')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })
})

describe('back-office — product photos', () => {
  it('uploads a photo as multipart with the anti-CSRF token, then makes it the main one', async () => {
    let photos = [photo(31, true)]
    const { uploads, calls } = mockApiWithUploads({
      ...catalogRoutes(),
      'GET /api/v1/products/42': () => json({ ...product, photos }),
      'POST /api/v1/products/42/media': () => {
        photos = [photo(31, true), photo(32, false)]
        return json(photo(32, false), 201)
      },
      'PATCH /api/v1/products/42/media/32': () => {
        photos = [photo(32, true), photo(31, false)]
        return json({ data: photos })
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/produits/42')
    const tile = await screen.findByTestId('photo-31')
    const image = within(tile).getByRole('img', { name: 'Photo 1 de Jus orange 1 L' })
    expect(image).toHaveAttribute('loading', 'lazy')
    expect(image).toHaveAttribute('width', '200')
    expect(image).toHaveAttribute('height', '200')
    expect(within(tile).getByText('Principale')).toBeInTheDocument()

    await user.upload(screen.getByLabelText('Ajouter une photo'), new File(['png'], 'orange.png', { type: 'image/png' }))

    expect(await screen.findByText('Photo ajoutée.')).toBeInTheDocument()
    expect(uploads).toHaveLength(1)
    expect(uploads[0]!.path).toBe('/api/v1/products/42/media')
    expect((uploads[0]!.form.get('photo') as File).name).toBe('orange.png')
    expect(uploads[0]!.headers.get('X-XSRF-TOKEN')).toBe('token=1')
    expect(uploads[0]!.headers.has('Content-Type')).toBe(false)
    expect(calls.some((c) => c.path === '/sanctum/csrf-cookie')).toBe(true)

    await user.click(await screen.findByRole('button', { name: 'Définir la photo 2 comme principale' }))

    await waitFor(() => expect(screen.getAllByTestId(/^photo-/)[0]).toHaveAttribute('data-testid', 'photo-32'))
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ main: true })
    expect(within(screen.getByTestId('photo-32')).getByText('Principale')).toBeInTheDocument()
    expect(within(screen.getByTestId('photo-31')).queryByText('Principale')).not.toBeInTheDocument()
  })

  it('checks type and size before sending, and shows the server refusal', async () => {
    const { uploads } = mockApiWithUploads({
      ...catalogRoutes(),
      'POST /api/v1/products/42/media': () =>
        problem(422, 'VALIDATION_FAILED', 'Les données sont invalides.', { errors: { photo: ['Le fichier n’est pas une image valide.'] } }),
    })
    const user = userEvent.setup({ applyAccept: false })

    renderApp('/admin/produits/42')
    const input = await screen.findByLabelText('Ajouter une photo')
    expect(input).toHaveAttribute('accept', 'image/jpeg,image/png,image/webp')

    await user.upload(input, new File(['gif'], 'anim.gif', { type: 'image/gif' }))
    expect(await screen.findByText('Format accepté : JPEG, PNG ou WebP.')).toBeInTheDocument()

    await user.upload(input, new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'big.jpg', { type: 'image/jpeg' }))
    expect(await screen.findByText('La photo dépasse 5 Mo.')).toBeInTheDocument()
    expect(uploads).toHaveLength(0)

    await user.upload(input, new File(['jpg'], 'fake.jpg', { type: 'image/jpeg' }))
    expect(await screen.findByText('Le fichier n’est pas une image valide.')).toBeInTheDocument()
    expect(uploads).toHaveLength(1)
  })

  it('deletes a photo after confirmation and stops at five photos', async () => {
    let photos = [1, 2, 3, 4, 5].map((n) => photo(30 + n, n === 1))
    const { calls } = mockApi({
      ...catalogRoutes(),
      'GET /api/v1/products/42': () => json({ ...product, photos }),
      'DELETE /api/v1/products/42/media/32': () => {
        photos = photos.filter((p) => p.id !== 32)
        return new Response(null, { status: 204 })
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/produits/42')
    expect(await screen.findByText('5 photos au maximum : supprimez-en une pour en ajouter une autre.')).toBeInTheDocument()
    expect(screen.queryByLabelText('Ajouter une photo')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Supprimer la photo 2' }))
    let dialog = screen.getByRole('dialog', { name: 'Supprimer la photo 2 ?' })
    expect(dialog).toHaveTextContent('La photo et ses variantes seront supprimées définitivement.')
    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false)

    await user.click(screen.getByRole('button', { name: 'Supprimer la photo 2' }))
    dialog = screen.getByRole('dialog', { name: 'Supprimer la photo 2 ?' })
    await user.click(within(dialog).getByRole('button', { name: 'Supprimer' }))

    await waitFor(() => expect(screen.queryByTestId('photo-32')).not.toBeInTheDocument())
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.find((c) => c.method === 'DELETE')?.path).toBe('/api/v1/products/42/media/32')
    expect(await screen.findByLabelText('Ajouter une photo')).toBeInTheDocument()
  })
})

describe('back-office — product families', () => {
  it('creates a sub-family and edits a family without offering itself or a descendant as parent', async () => {
    const { calls } = mockApi({
      ...catalogRoutes(),
      'POST /api/v1/admin/product-families': ({ body }) => json({ id: 9, ...(body as object), products_count: 0 }, 201),
      'PATCH /api/v1/admin/product-families/2': ({ body }) => json({ ...families[0], ...(body as object) }),
    })
    const user = userEvent.setup()

    renderApp('/admin/produits?onglet=familles')
    const nectars = (await screen.findByText('Nectars')).closest('tr')!
    expect(within(nectars).getByText('Jus')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Nouvelle famille' }))
    await user.type(screen.getByLabelText('Code'), 'nectar_abr')
    await user.type(screen.getByLabelText('Libellé'), 'Nectars abricot')
    await user.selectOptions(screen.getByLabelText('Famille parente'), '3')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Famille créée.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ code: 'NECTAR_ABR', name: 'Nectars abricot', parent_id: 3, sort_order: 0 })

    await user.click(screen.getByRole('button', { name: 'Modifier la famille Jus' }))
    expect(screen.getByLabelText('Code')).toBeDisabled()
    const parent = screen.getByLabelText('Famille parente')
    const options = within(parent).getAllByRole('option').map((option) => option.getAttribute('value'))
    expect(options).toEqual(['', '4'])

    await user.selectOptions(parent, '4')
    await user.clear(screen.getByLabelText('Ordre d’affichage'))
    await user.type(screen.getByLabelText('Ordre d’affichage'), '5')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Famille enregistrée.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ name: 'Jus', parent_id: 4, sort_order: 5 })
  })
})

describe('back-office — products for the ADV (read only)', () => {
  it('shows the catalogue without any edit action', async () => {
    mockApi(catalogRoutes(adv))
    const user = userEvent.setup()

    renderApp('/adv/produits')
    await user.click(await screen.findByRole('link', { name: 'Jus orange 1 L' }))
    expect(screen.queryByRole('button', { name: 'Nouveau produit' })).not.toBeInTheDocument()

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/adv/produits/42'))
    expect(await screen.findByText('Consultation seule : la modification du catalogue est réservée aux utilisateurs habilités.')).toBeInTheDocument()
    expect(screen.getByText('Fardeau 6 × 1 L')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer' })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Ajouter une photo')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /principale|Supprimer/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer le prix' })).not.toBeInTheDocument()
    expect(within(await screen.findByTestId('price-history')).getAllByRole('row')).toHaveLength(3)

    await user.click(screen.getByRole('link', { name: 'Retour' }))
    await user.click(await screen.findByRole('tab', { name: 'Familles' }))
    expect(await screen.findByText('Nectars')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nouvelle famille' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Modifier/ })).not.toBeInTheDocument()
  })

  it('refuses the creation form without product.manage', async () => {
    mockApi(catalogRoutes(adv))

    renderApp('/adv/produits/nouveau')

    expect(await screen.findByText('Vous n’avez pas accès à cette page.')).toBeInTheDocument()
    expect(screen.queryByLabelText('Code article')).not.toBeInTheDocument()
  })
})
