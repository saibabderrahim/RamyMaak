import { QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import '../i18n'
import { createQueryClient } from '../../../shared/api/queryClient'
import type {
  CurrentUser,
  Day,
  DayStatus,
  PublicationResult,
  QuotaCellInput,
  QuotaMatrix,
  QuotaMatrixProduct,
  RegionDayStatus,
  SaveQuotasResult,
} from '../../../shared/api/types'
import { meQueryKey } from '../../../shared/auth/session'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'
import { formatQuantity } from './matrix'
import QuotaMatrixPage from './QuotaMatrixPage'

const DAY = '2026-10-08'
const MATRIX_URL = `GET /api/v1/adv/days/${DAY}/quotas`
const SAVE_URL = `PUT /api/v1/adv/days/${DAY}/quotas`
const PUBLISH_URL = `POST /api/v1/adv/days/${DAY}/quotas/publish`
const COPY_URL = `POST /api/v1/adv/days/${DAY}/quotas/copy`

const ADV_PERMISSIONS = ['quota.view', 'quota.edit', 'quota.publish', 'day.manage', 'day.extend-cutoff', 'region.view', 'client.view', 'product.view']
const adv = () => makeUser('adv', { permissions: ADV_PERMISSIONS, perimeter: { all_regions: true, region_ids: [] } })
const admin = () => makeUser('admin', { permissions: ['user.manage', 'quota.view', 'day.extend-cutoff', 'region.view', 'region.manage'] })

const STATUS_LABELS: Record<RegionDayStatus, string> = {
  QUOTA_DRAFT: 'Quotas en brouillon',
  QUOTA_PUBLISHED: 'Quotas publiés',
  DISTRIBUTION_DRAFT: 'Répartition en cours',
  DISTRIBUTION_PUBLISHED: 'Répartition publiée',
  CLOSED: 'Clôturée',
}

const CENTRE = { id: 3, code: 'CENTRE', name: 'Centre' }
const EST = { id: 4, code: 'EST', name: 'Est' }

function makeDay(status: DayStatus = 'OPEN'): Day {
  return {
    day: DAY,
    weekday: 4,
    is_working_day: true,
    status,
    status_label: status === 'OPEN' ? 'Ouverte' : 'Clôturée',
    delivery_date: '2026-10-11',
    cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00' },
    cutoffs_at: {
      distribution: '2026-10-08T09:00:00+00:00',
      ordering: '2026-10-08T12:00:00+00:00',
      validation: '2026-10-08T14:00:00+00:00',
      closing: '2026-10-08T22:00:00+00:00',
    },
    closed_at: status === 'CLOSED' ? '2026-10-08T22:00:00+00:00' : null,
    regions: [
      {
        region: CENTRE,
        status: 'QUOTA_PUBLISHED',
        status_label: STATUS_LABELS.QUOTA_PUBLISHED,
        quota_version: 2,
        quota_published_at: '2026-10-08T06:55:00+00:00',
        quota_last_published_at: '2026-10-08T07:10:00+00:00',
        pending_drafts: 2,
        cutoffs: { distribution: '10:00', ordering: '13:00' },
        extended: { distribution: false, ordering: false },
      },
      {
        region: EST,
        status: 'QUOTA_DRAFT',
        status_label: STATUS_LABELS.QUOTA_DRAFT,
        quota_version: 0,
        quota_published_at: null,
        quota_last_published_at: null,
        pending_drafts: 1,
        cutoffs: { distribution: '10:00', ordering: '13:00' },
        extended: { distribution: false, ordering: false },
      },
    ],
    created_by: { id: 4, username: 'a.adv', name: 'ADV' },
    created_at: '2026-10-07T15:00:00+00:00',
  }
}

const JUS = { id: 2, code: 'JUS', name: 'Jus' }
const NECTAR = { id: 5, code: 'NECTAR', name: 'Nectars' }

const product = (id: number, shortName: string, family: QuotaMatrixProduct['family'], isActive = true): QuotaMatrixProduct => ({
  id,
  sku: `P00${id}`,
  name: `${shortName} (désignation complète)`,
  short_name: shortName,
  family,
  sales_unit_label: 'Fardeau 6 × 1 L',
  is_active: isActive,
})

/**
 * Lignes (ordre de navigation) : 0 Orange, 1 Pomme, 2 Abricot, 3 Pêche
 * (inactif, lecture seule), 4 Goyave ; colonnes : 0 CENTRE, 1 EST.
 */
function makeMatrix(status: DayStatus = 'OPEN'): QuotaMatrix {
  return {
    day: makeDay(status),
    regions: [
      { ...CENTRE, status: status === 'CLOSED' ? 'CLOSED' : 'QUOTA_PUBLISHED', quota_version: 2, pending_drafts: 2 },
      { ...EST, status: status === 'CLOSED' ? 'CLOSED' : 'QUOTA_DRAFT', quota_version: 0, pending_drafts: 1 },
    ],
    products: [
      product(42, 'Orange 1 L', JUS),
      product(43, 'Pomme 1 L', JUS),
      product(50, 'Abricot 1 L', NECTAR),
      product(51, 'Pêche 1 L', NECTAR, false),
      product(52, 'Goyave 1 L', NECTAR),
    ],
    cells: {
      '42': { '3': { quantity: 500, draft: 450, validated: 0 }, '4': { quantity: 0, draft: 200, validated: 0 } },
      '43': { '3': { quantity: 300, draft: 250, validated: 300 } },
      '50': { '3': { quantity: 100, draft: null, validated: 20 } },
      '51': { '3': { quantity: 20, draft: null, validated: 0 } },
    },
  }
}

/** Réponse du PUT : les cellules envoyées passent en brouillon (quota publié de la matrice d'origine). */
function savedCells(body: unknown): SaveQuotasResult {
  const { cells } = body as { cells: QuotaCellInput[] }
  const matrix = makeMatrix()
  return {
    changed: cells.length,
    cells: cells.map(({ product_id, region_id, quantity }) => {
      const current = matrix.cells[String(product_id)]?.[String(region_id)]
      const published = current?.quantity ?? 0
      return { product_id, region_id, quantity: published, draft: quantity === published ? null : quantity, validated: current?.validated ?? 0 }
    }),
  }
}

type Routes = Parameters<typeof mockApi>[0]

function Where() {
  const location = useLocation()
  return <output data-testid="where">{`${location.pathname}${location.search}`}</output>
}

/** Écran seul, sous la route du rôle : utilisateur connu, aucune autre requête. */
function renderMatrix(user: CurrentUser = adv(), routes: Routes = {}, path = `/${user.role}/journees/${DAY}/quotas`) {
  const mock = mockApi({ 'GET /api/v1/auth/me': () => json(user), [MATRIX_URL]: () => json(makeMatrix()), ...routes })
  const queryClient = createQueryClient()
  queryClient.setQueryData(meQueryKey, user)
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/adv/journees/:day/quotas" element={<QuotaMatrixPage />} />
          <Route path="/admin/journees/:day/quotas" element={<QuotaMatrixPage />} />
          <Route path="*" element={null} />
        </Routes>
        <Where />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { ...mock, queryClient, requests: (route: string) => mock.calls.filter((call) => `${call.method} ${call.path}` === route) }
}

const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

/** Cellule d'un produit (nom court) dans une région (code). */
const cellOf = (productName: string, region: string) => screen.getByRole('gridcell', { name: new RegExp(`^${escape(productName)} — ${region} :`) })

const editor = (productName: string, region: string) => screen.getByRole('textbox', { name: `Quota ${productName} — ${region}` })

const grid = () => screen.findByRole('grid', { name: 'Quotas du 08/10/2026 par produit et par région' })

/** Quantité affichée (espace insécable) ; `text` : telle que lue par toHaveTextContent (espaces normalisés). */
const nbsp = (value: number) => formatQuantity(value)
const text = (value: number) => formatQuantity(value).replace(/\u00a0/g, ' ')

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('back-office — quota matrix: display', () => {
  it('shows products grouped by family, regions with their status, displayed values and totals', async () => {
    renderMatrix()
    const table = await grid()

    expect(screen.getByRole('heading', { name: 'Quotas du 08/10/2026' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Journée du 08/10/2026' })).toHaveAttribute('href', `/adv/journees/${DAY}`)
    expect(screen.getByText('Livraison le 11/10/2026')).toBeInTheDocument()

    // Groupes de familles, dans l'ordre reçu, avant leurs produits.
    const groups = Array.from(table.querySelectorAll('tbody'))
    expect(groups).toHaveLength(2)
    expect(groups.map((group) => group.querySelector('tr')?.textContent)).toEqual(['Jus', 'Nectars'])
    expect(groups.map((group) => group.querySelectorAll('tr').length)).toEqual([3, 4])
    expect(screen.getAllByRole('rowheader').map((header) => header.textContent)).toEqual(['Jus', 'P0042', 'P0043', 'Nectars', 'P0050', 'P0051', 'P0052', 'Total'])

    // En-têtes de régions : code, statut, version, brouillons en attente.
    const centre = screen.getByRole('columnheader', { name: /CENTRE/ })
    expect(within(centre).getByText('Quotas publiés')).toBeInTheDocument()
    expect(within(centre).getByText('Version 2')).toBeInTheDocument()
    expect(within(centre).getByText('2 brouillons')).toBeInTheDocument()
    const est = screen.getByRole('columnheader', { name: /EST/ })
    expect(within(est).getByText('Quotas en brouillon')).toBeInTheDocument()
    expect(within(est).getByText('Non publiée')).toBeInTheDocument()
    expect(within(est).getByText('1 brouillon')).toBeInTheDocument()

    // Valeur affichée = brouillon ?? publié ; brouillon : fond distinct et « publié : 500 ».
    const orange = cellOf('Orange 1 L', 'CENTRE')
    expect(orange).toHaveTextContent('450')
    expect(orange).toHaveAttribute('title', 'publié : 500')
    expect(orange).toHaveAttribute('data-draft', 'true')
    expect(orange).toHaveClass('bg-amber-100')
    expect(orange).toHaveAccessibleName('Orange 1 L — CENTRE : 450, brouillon (publié : 500)')
    expect(cellOf('Orange 1 L', 'EST')).toHaveAttribute('title', 'publié : 0')

    // Sous le validé : en rouge, « déjà validé : 300 ».
    const pomme = cellOf('Pomme 1 L', 'CENTRE')
    expect(pomme).toHaveTextContent('250')
    expect(pomme).toHaveAttribute('data-below', 'true')
    expect(pomme).toHaveClass('text-red-700')
    expect(pomme).toHaveAttribute('title', 'publié : 300 · déjà validé : 300')

    // Quota publié sans brouillon : pas de repère.
    const abricot = cellOf('Abricot 1 L', 'CENTRE')
    expect(abricot).toHaveTextContent('100')
    expect(abricot).not.toHaveAttribute('data-draft')
    expect(abricot).toHaveAttribute('title', 'déjà validé : 20')
    expect(cellOf('Goyave 1 L', 'EST')).toHaveTextContent('0')

    // Produit désactivé gardant un quota publié : ligne en lecture seule.
    const peche = cellOf('Pêche 1 L', 'CENTRE')
    expect(peche).toHaveTextContent('20')
    expect(peche).toHaveAttribute('aria-readonly', 'true')
    expect(within(peche.closest('tr')!).getByText('Inactif')).toBeInTheDocument()

    // Totaux par ligne, par colonne et général, sur la valeur affichée.
    expect(screen.getByRole('gridcell', { name: 'Total Orange 1 L : 650' })).toHaveTextContent('650')
    expect(screen.getByRole('gridcell', { name: 'Total Pomme 1 L : 250' })).toBeInTheDocument()
    expect(screen.getByRole('gridcell', { name: 'Total Goyave 1 L : 0' })).toBeInTheDocument()
    expect(screen.getByRole('gridcell', { name: 'Total CENTRE : 820' })).toBeInTheDocument()
    expect(screen.getByRole('gridcell', { name: 'Total EST : 200' })).toBeInTheDocument()
    expect(screen.getByRole('gridcell', { name: `Total général : ${nbsp(1020)}` })).toHaveTextContent(text(1020))

    // Actions de l'ADV.
    for (const name of ['Publier…', 'Copier depuis la journée…', 'Importer', 'Télécharger le gabarit']) {
      expect(screen.getByRole('button', { name })).toBeInTheDocument()
    }
    expect(table).not.toHaveAttribute('aria-readonly')
  })

  it('answers « Journée non ouverte » with a link to the calendar when the day is not opened', async () => {
    renderMatrix(adv(), { [MATRIX_URL]: () => problem(404, 'NOT_FOUND', 'Ressource introuvable.') })

    expect(await screen.findByRole('heading', { name: 'Journée non ouverte' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Aller au calendrier des journées' })).toHaveAttribute('href', '/adv/journees')
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
  })

  it('is wired to the ADV route', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(adv()), [MATRIX_URL]: () => json(makeMatrix()) })
    renderApp(`/adv/journees/${DAY}/quotas`)
    expect(await screen.findByRole('grid', { name: 'Quotas du 08/10/2026 par produit et par région' }, { timeout: 10_000 })).toBeInTheDocument()
  }, 20_000)
})

describe('back-office — quota matrix: keyboard entry', () => {
  it('moves like a spreadsheet with arrows, Tab and Enter, and typing replaces the value', async () => {
    renderMatrix(adv(), { [SAVE_URL]: ({ body }) => json(savedCells(body)) })
    const user = userEvent.setup()
    await grid()

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveFocus()
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveAttribute('aria-selected', 'true')

    await user.keyboard('{ArrowRight}')
    expect(cellOf('Orange 1 L', 'EST')).toHaveFocus()
    await user.keyboard('{ArrowDown}')
    expect(cellOf('Pomme 1 L', 'EST')).toHaveFocus()
    // Tab au bout de la ligne : début de la ligne suivante ; Maj+Tab revient.
    await user.keyboard('{Tab}')
    expect(cellOf('Abricot 1 L', 'CENTRE')).toHaveFocus()
    await user.keyboard('{Shift>}{Tab}{/Shift}')
    expect(cellOf('Pomme 1 L', 'EST')).toHaveFocus()
    await user.keyboard('{Enter}')
    expect(cellOf('Abricot 1 L', 'EST')).toHaveFocus()
    await user.keyboard('{Shift>}{Enter}{/Shift}')
    expect(cellOf('Pomme 1 L', 'EST')).toHaveFocus()
    await user.keyboard('{ArrowUp}{ArrowLeft}{ArrowLeft}{ArrowUp}')
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveFocus()

    // Une frappe ouvre la saisie et remplace la valeur ; Entrée valide et descend.
    await user.keyboard('7')
    expect(editor('Orange 1 L', 'CENTRE')).toHaveValue('7')
    expect(editor('Orange 1 L', 'CENTRE')).toHaveFocus()
    await user.keyboard('5{Enter}')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveTextContent(/^75$/)
    expect(cellOf('Pomme 1 L', 'CENTRE')).toHaveFocus()
    expect(screen.getByRole('gridcell', { name: 'Total Orange 1 L : 275' })).toBeInTheDocument()
    expect(screen.getByRole('gridcell', { name: 'Total CENTRE : 445' })).toBeInTheDocument()

    // F2 garde la valeur ; Tab valide et passe à droite.
    await user.keyboard('{F2}')
    expect(editor('Pomme 1 L', 'CENTRE')).toHaveValue('250')
    await user.keyboard('0{Tab}')
    expect(cellOf('Pomme 1 L', 'CENTRE')).toHaveTextContent(text(2500))
    expect(cellOf('Pomme 1 L', 'CENTRE')).not.toHaveAttribute('data-below')
    expect(cellOf('Pomme 1 L', 'EST')).toHaveFocus()

    // Double-clic : modification de la valeur affichée.
    await user.dblClick(cellOf('Abricot 1 L', 'CENTRE'))
    expect(editor('Abricot 1 L', 'CENTRE')).toHaveValue('100')
    await user.keyboard('{Backspace}{Backspace}{Backspace}9{ArrowDown}')
    expect(cellOf('Abricot 1 L', 'CENTRE')).toHaveTextContent(/^9$/)
    // Sous le validé (20) : en rouge.
    expect(cellOf('Abricot 1 L', 'CENTRE')).toHaveAttribute('data-below', 'true')
    expect(cellOf('Pêche 1 L', 'CENTRE')).toHaveFocus()

    // Produit inactif : pas de saisie.
    await user.keyboard('3')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(cellOf('Pêche 1 L', 'CENTRE')).toHaveTextContent('20')
  })

  it('clears a cell to 0 with Delete or Backspace, cancels with Escape, and refuses non-integers on the spot', async () => {
    const { requests } = renderMatrix(adv(), { [SAVE_URL]: ({ body }) => json(savedCells(body)) })
    const user = userEvent.setup()
    await grid()

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('{Delete}')
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveTextContent(/^0$/)

    // Échap annule la saisie en cours.
    await user.keyboard('9')
    expect(editor('Orange 1 L', 'CENTRE')).toHaveValue('9')
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveTextContent(/^0$/)
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveFocus()

    await user.keyboard('{ArrowDown}{Backspace}')
    expect(cellOf('Pomme 1 L', 'CENTRE')).toHaveTextContent(/^0$/)

    // Saisie refusée sur place : affichée en erreur, jamais envoyée.
    await user.keyboard('{ArrowDown}12,5{Enter}')
    const abricot = cellOf('Abricot 1 L', 'CENTRE')
    expect(abricot).toHaveTextContent('12,5')
    expect(abricot).toHaveAttribute('aria-invalid', 'true')
    expect(abricot).toHaveAttribute('title', 'déjà validé : 20 · Nombre entier de 0 à 1 000 000 attendu.')
    expect(screen.getByText('1 cellule en erreur, non enregistrée')).toBeInTheDocument()
    // Le total garde la valeur du serveur.
    expect(screen.getByRole('gridcell', { name: 'Total Abricot 1 L : 100' })).toBeInTheDocument()

    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({
      cells: [
        { region_id: 3, product_id: 42, quantity: 0 },
        { region_id: 3, product_id: 43, quantity: 0 },
      ],
    })

    // Échap sur la cellule en erreur : retour à la valeur du serveur.
    await user.keyboard('{ArrowUp}{Escape}')
    expect(cellOf('Abricot 1 L', 'CENTRE')).toHaveTextContent(/^100$/)
    expect(cellOf('Abricot 1 L', 'CENTRE')).not.toHaveAttribute('aria-invalid')
    expect(screen.queryByText('1 cellule en erreur, non enregistrée')).not.toBeInTheDocument()
  })

  it('pastes a block copied from Excel from the active cell, clipped to the grid', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderMatrix(adv(), { [SAVE_URL]: ({ body }) => json(savedCells(body)) })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await grid()

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.paste('1 200\t300\r\n40\t5\r\n')

    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveTextContent(text(1200))
    expect(cellOf('Orange 1 L', 'EST')).toHaveTextContent(/^300$/)
    expect(cellOf('Pomme 1 L', 'CENTRE')).toHaveTextContent(/^40$/)
    expect(cellOf('Pomme 1 L', 'EST')).toHaveTextContent(/^5$/)
    expect(cellOf('Abricot 1 L', 'CENTRE')).toHaveTextContent(/^100$/)
    expect(screen.getByText('4 cellules collées.')).toBeInTheDocument()

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({
      cells: [
        { region_id: 3, product_id: 42, quantity: 1200 },
        { region_id: 4, product_id: 42, quantity: 300 },
        { region_id: 3, product_id: 43, quantity: 40 },
        { region_id: 4, product_id: 43, quantity: 5 },
      ],
    })
  })

  it('reads the clipboard after Ctrl+V when the browser sends no paste event', async () => {
    renderMatrix(adv(), { [SAVE_URL]: ({ body }) => json(savedCells(body)) })
    const user = userEvent.setup()
    await grid()

    await navigator.clipboard.writeText('11\t12\n')
    await user.click(cellOf('Goyave 1 L', 'CENTRE'))
    await user.keyboard('{Control>}v{/Control}')

    await waitFor(() => expect(cellOf('Goyave 1 L', 'CENTRE')).toHaveTextContent(/^11$/))
    expect(cellOf('Goyave 1 L', 'EST')).toHaveTextContent(/^12$/)
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('skips inactive products and drops what falls outside the grid when pasting', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { requests } = renderMatrix(adv(), { [SAVE_URL]: ({ body }) => json(savedCells(body)) })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await grid()

    // Depuis Abricot / EST : la 2e colonne du bloc sort de la grille, la
    // ligne de Pêche (inactif) est sautée, la 4e ligne n'a plus de produit.
    await user.click(cellOf('Abricot 1 L', 'EST'))
    await user.paste('7\t70\n8\t80\nx\t90\n')

    expect(cellOf('Abricot 1 L', 'EST')).toHaveTextContent(/^7$/)
    expect(cellOf('Pêche 1 L', 'EST')).toHaveTextContent(/^0$/)
    expect(cellOf('Goyave 1 L', 'EST')).toHaveTextContent(/^8$/)

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({
      cells: [
        { region_id: 4, product_id: 50, quantity: 7 },
        { region_id: 4, product_id: 52, quantity: 8 },
      ],
    })
  })
})

describe('back-office — quota matrix: autosave', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    // 08:12 UTC = 09:12 à Alger.
    vi.setSystemTime(new Date('2026-10-08T08:12:00Z'))
  })

  it('sends the edits in one batch after a short pause and shows when they were saved', async () => {
    const { requests } = renderMatrix(adv(), { [SAVE_URL]: ({ body }) => json(savedCells(body)) })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await grid()

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('600{Enter}310{Tab}50{Enter}')
    expect(requests(SAVE_URL)).toHaveLength(0)
    expect(screen.getByText('Enregistrement…')).toBeInTheDocument()

    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))
    expect(requests(SAVE_URL)[0]!.body).toEqual({
      cells: [
        { region_id: 3, product_id: 42, quantity: 600 },
        { region_id: 3, product_id: 43, quantity: 310 },
        { region_id: 4, product_id: 43, quantity: 50 },
      ],
    })
    expect(await screen.findByText('Enregistré à 09:12')).toBeInTheDocument()

    // État renvoyé reporté dans la matrice : brouillons de la région EST (+1).
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveAttribute('title', 'publié : 500')
    expect(cellOf('Pomme 1 L', 'EST')).toHaveTextContent(/^50$/)
    expect(within(screen.getByRole('columnheader', { name: /EST/ })).getByText('2 brouillons')).toBeInTheDocument()
    expect(within(screen.getByRole('columnheader', { name: /CENTRE/ })).getByText('2 brouillons')).toBeInTheDocument()
  })

  it('keeps one request in flight and keeps unsaved edits above refreshed data', async () => {
    const pending: Array<() => void> = []
    let refreshed = 0
    const { requests } = renderMatrix(adv(), {
      [MATRIX_URL]: () => {
        refreshed++
        return json(makeMatrix())
      },
      [SAVE_URL]: ({ body }) => new Promise<Response>((resolve) => pending.push(() => resolve(json(savedCells(body))))),
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await grid()

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('600{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(1))

    // Pas de second envoi tant que le premier n'a pas répondu.
    await user.keyboard('310{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))
    expect(requests(SAVE_URL)).toHaveLength(1)

    // Rafraîchissement de secours (30 s sans temps réel) : les saisies restent affichées.
    await act(() => vi.advanceTimersByTimeAsync(30_000))
    await waitFor(() => expect(refreshed).toBeGreaterThanOrEqual(2))
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveTextContent(/^600$/)
    expect(cellOf('Pomme 1 L', 'CENTRE')).toHaveTextContent(/^310$/)

    await act(async () => pending.shift()!())
    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(2))
    expect(requests(SAVE_URL)[1]!.body).toEqual({ cells: [{ region_id: 3, product_id: 43, quantity: 310 }] })
    await act(async () => pending.shift()!())
    expect(await screen.findByText('Enregistré à 09:12')).toBeInTheDocument()
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveTextContent(/^600$/)
  })

  it('shows a 422 on the offending cell and saves the others', async () => {
    let attempts = 0
    const { requests } = renderMatrix(adv(), {
      [SAVE_URL]: ({ body }) =>
        ++attempts === 1
          ? problem(422, 'VALIDATION_FAILED', 'Région hors de votre périmètre.', { errors: { 'cells.1.region_id': ['Région hors de votre périmètre.'] } })
          : json(savedCells(body)),
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await grid()

    await user.click(cellOf('Goyave 1 L', 'CENTRE'))
    await user.keyboard('600{Tab}700{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))

    await waitFor(() => expect(requests(SAVE_URL)).toHaveLength(2))
    expect(requests(SAVE_URL)[1]!.body).toEqual({ cells: [{ region_id: 3, product_id: 52, quantity: 600 }] })
    const refused = cellOf('Goyave 1 L', 'EST')
    expect(refused).toHaveTextContent(/^700$/)
    expect(refused).toHaveAttribute('aria-invalid', 'true')
    expect(refused).toHaveAttribute('title', 'publié : 0 · Région hors de votre périmètre.')
    expect(refused).toHaveAccessibleName(/erreur : Région hors de votre périmètre\./)
    expect(await screen.findByText('1 cellule en erreur, non enregistrée')).toBeInTheDocument()
    expect(screen.getByText('Enregistré à 09:12')).toBeInTheDocument()
  })

  it('offers a retry after a failed save', async () => {
    let attempts = 0
    const { requests } = renderMatrix(adv(), {
      [SAVE_URL]: ({ body }) => (++attempts === 1 ? problem(500, 'SERVER_ERROR', 'Erreur interne.') : json(savedCells(body))),
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await grid()

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('600{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))

    expect(await screen.findByText('Échec de l’enregistrement des brouillons. Erreur interne.')).toBeInTheDocument()
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveTextContent(/^600$/)
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await screen.findByText('Enregistré à 09:12')).toBeInTheDocument()
    expect(requests(SAVE_URL)).toHaveLength(2)
    expect(requests(SAVE_URL)[1]!.body).toEqual(requests(SAVE_URL)[0]!.body)
  })

  it('switches to read-only when the day was closed meanwhile (409 DAY_NOT_OPEN)', async () => {
    let closed = false
    renderMatrix(adv(), {
      [MATRIX_URL]: () => json(makeMatrix(closed ? 'CLOSED' : 'OPEN')),
      [SAVE_URL]: () => {
        closed = true
        return problem(409, 'DAY_NOT_OPEN', 'La journée du 08/10/2026 est clôturée : les quotas ne sont plus modifiables.')
      },
    })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await grid()

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('600{Enter}')
    await act(() => vi.advanceTimersByTimeAsync(600))

    expect(
      await screen.findByText('La journée a été clôturée : les quotas ne sont plus modifiables et les dernières saisies non enregistrées sont perdues.'),
    ).toBeInTheDocument()
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveTextContent(/^450$/)
    expect(screen.queryByRole('button', { name: 'Publier…' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Copier depuis la journée…' })).not.toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('grid')).toHaveAttribute('aria-readonly', 'true'))

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('5')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('warns before leaving the page while edits are not saved', async () => {
    renderMatrix(adv(), { [SAVE_URL]: ({ body }) => json(savedCells(body)) })
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await grid()

    const unsavedEvent = () => {
      const event = new Event('beforeunload', { cancelable: true })
      window.dispatchEvent(event)
      return event.defaultPrevented
    }
    expect(unsavedEvent()).toBe(false)

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('600{Enter}')
    expect(unsavedEvent()).toBe(true)

    await act(() => vi.advanceTimersByTimeAsync(600))
    await screen.findByText('Enregistré à 09:12')
    expect(unsavedEvent()).toBe(false)
  })
})

describe('back-office — quota matrix: read-only', () => {
  it('is read-only for the Admin, without entry nor actions', async () => {
    const { requests } = renderMatrix(admin())
    const table = await grid()

    expect(screen.getByText('Consultation seule : la saisie et la publication des quotas sont réservées à l’ADV.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Journée du 08/10/2026' })).toHaveAttribute('href', `/admin/journees/${DAY}`)
    expect(table).toHaveAttribute('aria-readonly', 'true')
    for (const name of ['Publier…', 'Copier depuis la journée…', 'Importer', 'Télécharger le gabarit']) {
      expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
    }

    const user = userEvent.setup()
    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('{ArrowRight}')
    expect(cellOf('Orange 1 L', 'EST')).toHaveFocus()
    await user.keyboard('5{F2}{Delete}')
    await user.paste('1\t2')
    await user.dblClick(cellOf('Orange 1 L', 'EST'))
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(cellOf('Orange 1 L', 'EST')).toHaveTextContent(/^200$/)
    expect(cellOf('Orange 1 L', 'EST')).toHaveAttribute('aria-readonly', 'true')
    expect(requests(SAVE_URL)).toHaveLength(0)
  })

  it('drops the entry in progress when the day gets closed by a refresh', async () => {
    let closed = false
    const { queryClient, requests } = renderMatrix(adv(), {
      [MATRIX_URL]: () => json(makeMatrix(closed ? 'CLOSED' : 'OPEN')),
      [SAVE_URL]: ({ body }) => json(savedCells(body)),
    })
    const user = userEvent.setup()
    await grid()

    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('9')
    expect(editor('Orange 1 L', 'CENTRE')).toHaveValue('9')

    // Événement DayClosed du canal adv.{day} : la matrice est relue.
    closed = true
    await act(() => queryClient.invalidateQueries({ queryKey: ['backoffice', 'quotas', 'matrix', DAY] }))

    expect(await screen.findByText('Journée clôturée : les quotas ne sont plus modifiables.')).toBeInTheDocument()
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(cellOf('Orange 1 L', 'CENTRE')).toHaveTextContent(/^450$/)
    await user.keyboard('{Enter}')
    expect(requests(SAVE_URL)).toHaveLength(0)
  })

  it('is read-only once the day is closed', async () => {
    renderMatrix(adv(), { [MATRIX_URL]: () => json(makeMatrix('CLOSED')) })
    const table = await grid()

    expect(screen.getByText('Journée clôturée : les quotas ne sont plus modifiables.')).toBeInTheDocument()
    expect(table).toHaveAttribute('aria-readonly', 'true')
    expect(screen.queryByRole('button', { name: 'Publier…' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Importer' })).not.toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(cellOf('Orange 1 L', 'CENTRE'))
    await user.keyboard('5')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })
})

describe('back-office — quota matrix: publication', () => {
  it('publishes a selection of regions and shows the published versions', async () => {
    let published = false
    const { requests } = renderMatrix(adv(), {
      [MATRIX_URL]: () => {
        const matrix = makeMatrix()
        if (published) matrix.regions[0] = { ...matrix.regions[0]!, quota_version: 3, pending_drafts: 0 }
        return json(matrix)
      },
      [PUBLISH_URL]: () => {
        published = true
        const result: PublicationResult = { published: [{ region_id: 3, code: 'CENTRE', version: 3, first: false, changes: 2 }], skipped: [] }
        return json(result)
      },
    })
    const user = userEvent.setup()
    await grid()

    await user.click(screen.getByRole('button', { name: 'Publier…' }))
    const dialog = screen.getByRole('dialog', { name: 'Publier les quotas' })
    expect(within(dialog).getByText('CENTRE — Centre')).toBeInTheDocument()
    expect(within(dialog).getByText('modifications (version 2 en cours) · 2 brouillons')).toBeInTheDocument()
    expect(within(dialog).getByText('première publication · 1 brouillon')).toBeInTheDocument()
    expect(within(dialog).getByRole('radio', { name: 'Toutes les régions' })).toBeChecked()
    expect(within(dialog).queryByRole('checkbox')).not.toBeInTheDocument()

    await user.click(within(dialog).getByRole('radio', { name: 'Une sélection de régions' }))
    const centre = within(dialog).getByRole('checkbox', { name: /CENTRE — Centre/ })
    const est = within(dialog).getByRole('checkbox', { name: /EST — Est/ })
    expect(centre).toBeChecked()
    expect(est).toBeChecked()
    await user.click(est)
    await user.click(within(dialog).getByRole('button', { name: 'Publier' }))

    expect(await screen.findByText('Publication terminée.')).toBeInTheDocument()
    const report = screen.getByRole('dialog', { name: 'Publier les quotas' })
    expect(within(report).getByText('CENTRE : version 3, 2 quotas modifiés')).toBeInTheDocument()
    expect(within(report).getByRole('button', { name: 'Revenir à la matrice' })).toHaveFocus()
    expect(requests(PUBLISH_URL)[0]!.body).toEqual({ region_ids: [3] })

    // Matrice relue : version et brouillons à jour.
    await waitFor(() => expect(within(screen.getByRole('columnheader', { name: /CENTRE/ })).getByText('Version 3')).toBeInTheDocument())
    await user.click(within(report).getByRole('button', { name: 'Revenir à la matrice' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publier…' })).toHaveFocus()
  })

  it('publishes all regions after saving pending edits, and explains the skipped regions', async () => {
    const { calls, requests } = renderMatrix(adv(), {
      [SAVE_URL]: ({ body }) => json(savedCells(body)),
      [PUBLISH_URL]: () =>
        json({
          published: [{ region_id: 4, code: 'EST', version: 1, first: true, changes: 1 }],
          skipped: [
            { region_id: 3, code: 'CENTRE', reason: 'NO_CHANGES' },
            { region_id: 5, code: 'OUEST', reason: 'EMPTY' },
            { region_id: 6, code: 'SUD', reason: 'CLOSED' },
          ],
        } satisfies PublicationResult),
    })
    const user = userEvent.setup()
    await grid()

    // Saisie encore en attente au moment de publier : enregistrée d'abord.
    await user.click(cellOf('Goyave 1 L', 'EST'))
    await user.keyboard('40{Enter}')
    await user.click(screen.getByRole('button', { name: 'Publier…' }))
    const dialog = screen.getByRole('dialog', { name: 'Publier les quotas' })
    await user.click(within(dialog).getByRole('button', { name: 'Publier' }))

    expect(await screen.findByText('EST : version 1, première publication')).toBeInTheDocument()
    const report = screen.getByRole('dialog', { name: 'Publier les quotas' })
    expect(within(report).getByText('CENTRE : aucune modification à publier')).toBeInTheDocument()
    expect(within(report).getByText('OUEST : aucun quota supérieur à 0')).toBeInTheDocument()
    expect(within(report).getByText('SUD : journée de la région clôturée')).toBeInTheDocument()
    expect(requests(PUBLISH_URL)[0]!.body).toEqual({ all: true })
    const order = calls.map((call) => `${call.method} ${call.path}`).filter((route) => route === SAVE_URL || route === PUBLISH_URL)
    expect(order).toEqual([SAVE_URL, PUBLISH_URL])
    expect(requests(SAVE_URL)[0]!.body).toEqual({ cells: [{ region_id: 4, product_id: 52, quantity: 40 }] })
  })

  it('lists the quotas below the validated quantity (409) and highlights their cells', async () => {
    renderMatrix(adv(), {
      [PUBLISH_URL]: () =>
        problem(409, 'QUOTA_BELOW_VALIDATED', 'Le quota ne peut pas descendre sous la quantité déjà validée.', {
          violations: [{ region_id: 3, region: 'CENTRE', product_id: 43, sku: 'P0043', validated: 300, requested: 250 }],
        }),
    })
    const user = userEvent.setup()
    await grid()

    await user.click(screen.getByRole('button', { name: 'Publier…' }))
    const dialog = screen.getByRole('dialog', { name: 'Publier les quotas' })
    await user.click(within(dialog).getByRole('button', { name: 'Publier' }))

    const alert = await within(dialog).findByRole('alert')
    expect(alert).toHaveTextContent('Publication refusée : des quotas descendent sous la quantité déjà validée (RG-Q05). Rien n’a été publié.')
    expect(within(alert).getByText('CENTRE — P0043 : déjà validé 300, demandé 250')).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    const pomme = cellOf('Pomme 1 L', 'CENTRE')
    expect(pomme).toHaveClass('ring-red-600')
    expect(pomme).toHaveAccessibleName(/publication refusée : sous le déjà validé/)

    // Cellule corrigée : elle n'est plus signalée.
    await user.click(pomme)
    await user.keyboard('300{Enter}')
    expect(cellOf('Pomme 1 L', 'CENTRE')).not.toHaveAccessibleName(/publication refusée/)
  })

  it('shows the validation message of a refused publication (422)', async () => {
    renderMatrix(adv(), {
      [PUBLISH_URL]: () =>
        problem(422, 'VALIDATION_FAILED', 'Région EST : aucun quota supérieur à 0 à publier.', {
          errors: { region_ids: ['Région EST : aucun quota supérieur à 0 à publier.'] },
        }),
    })
    const user = userEvent.setup()
    await grid()

    await user.click(screen.getByRole('button', { name: 'Publier…' }))
    const dialog = screen.getByRole('dialog', { name: 'Publier les quotas' })
    await user.click(within(dialog).getByRole('radio', { name: 'Une sélection de régions' }))
    await user.click(within(dialog).getByRole('checkbox', { name: /CENTRE/ }))
    await user.click(within(dialog).getByRole('button', { name: 'Publier' }))
    expect(await within(dialog).findByText('Région EST : aucun quota supérieur à 0 à publier.')).toBeInTheDocument()

    // Sélection vide : refusée sur place.
    await user.click(within(dialog).getByRole('checkbox', { name: /EST/ }))
    await user.click(within(dialog).getByRole('button', { name: 'Publier' }))
    expect(within(dialog).getByText('Choisissez au moins une région.')).toBeInTheDocument()
  })

  it('rereads the matrix, read-only, when the day was closed before publishing (409 DAY_NOT_OPEN)', async () => {
    let closed = false
    renderMatrix(adv(), {
      [MATRIX_URL]: () => json(makeMatrix(closed ? 'CLOSED' : 'OPEN')),
      [PUBLISH_URL]: () => {
        closed = true
        return problem(409, 'DAY_NOT_OPEN', 'La journée du 08/10/2026 est clôturée.')
      },
    })
    const user = userEvent.setup()
    await grid()

    await user.click(screen.getByRole('button', { name: 'Publier…' }))
    const dialog = screen.getByRole('dialog', { name: 'Publier les quotas' })
    await user.click(within(dialog).getByRole('button', { name: 'Publier' }))
    expect(await within(dialog).findByText('La journée du 08/10/2026 est clôturée.')).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    expect(await screen.findByText('Journée clôturée : les quotas ne sont plus modifiables.')).toBeInTheDocument()
    expect(screen.getByRole('grid')).toHaveAttribute('aria-readonly', 'true')
    expect(screen.queryByRole('button', { name: 'Publier…' })).not.toBeInTheDocument()
  })
})

describe('back-office — quota matrix: copy, import, template', () => {
  it('copies the published quotas of another day as drafts', async () => {
    let gets = 0
    const { requests } = renderMatrix(adv(), {
      [MATRIX_URL]: () => {
        gets++
        return json(makeMatrix())
      },
      [COPY_URL]: ({ body }) =>
        (body as { source_day: string }).source_day === '2026-10-01'
          ? problem(422, 'VALIDATION_FAILED', 'Aucune journée n’a été ouverte le 01/10/2026.', {
              errors: { source_day: ['Aucune journée n’a été ouverte le 01/10/2026.'] },
            })
          : json({ changed: 120 }),
    })
    const user = userEvent.setup()
    await grid()

    await user.click(screen.getByRole('button', { name: 'Copier depuis la journée…' }))
    const dialog = screen.getByRole('dialog', { name: 'Copier depuis une autre journée' })
    expect(within(dialog).getByRole('radio', { name: /Compléter/ })).toBeChecked()

    // Même journée : refusée sur place.
    const source = within(dialog).getByLabelText('Journée source')
    fireEvent.change(source, { target: { value: DAY } })
    await user.click(within(dialog).getByRole('button', { name: 'Copier' }))
    expect(await within(dialog).findByText('Choisissez une autre journée que celle-ci.')).toBeInTheDocument()

    // Erreur du serveur sur le champ.
    fireEvent.change(source, { target: { value: '2026-10-01' } })
    await user.click(within(dialog).getByRole('button', { name: 'Copier' }))
    expect(await within(dialog).findByText('Aucune journée n’a été ouverte le 01/10/2026.')).toBeInTheDocument()

    fireEvent.change(source, { target: { value: '2026-10-07' } })
    await user.click(within(dialog).getByRole('radio', { name: /Remplacer/ }))
    await user.click(within(dialog).getByRole('checkbox', { name: 'CENTRE — Centre' }))
    const before = gets
    await user.click(within(dialog).getByRole('button', { name: 'Copier' }))

    expect(await screen.findByText('120 cellules copiées en brouillon depuis la journée du 07/10/2026.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(requests(COPY_URL).at(-1)!.body).toEqual({ source_day: '2026-10-07', mode: 'REPLACE', region_ids: [3] })
    await waitFor(() => expect(gets).toBeGreaterThan(before))
  })

  it('copies for all regions when none is checked', async () => {
    const { requests } = renderMatrix(adv(), { [COPY_URL]: () => json({ changed: 0 }) })
    const user = userEvent.setup()
    await grid()

    await user.click(screen.getByRole('button', { name: 'Copier depuis la journée…' }))
    const dialog = screen.getByRole('dialog', { name: 'Copier depuis une autre journée' })
    fireEvent.change(within(dialog).getByLabelText('Journée source'), { target: { value: '2026-10-07' } })
    await user.click(within(dialog).getByRole('button', { name: 'Copier' }))

    expect(await screen.findByText('Aucune cellule modifiée par la copie depuis la journée du 07/10/2026.')).toBeInTheDocument()
    expect(requests(COPY_URL)[0]!.body).toEqual({ source_day: '2026-10-07', mode: 'FILL' })
  })

  it('leads to the imports with the Quotas type and the day preselected', async () => {
    renderMatrix()
    const user = userEvent.setup()
    await grid()

    await user.click(screen.getByRole('button', { name: 'Importer' }))
    expect(screen.getByTestId('where')).toHaveTextContent(`/adv/imports?type=QUOTAS&day=${DAY}`)
  })

  describe('template', () => {
    const downloads: string[] = []
    const blobs: Blob[] = []

    beforeAll(() => {
      Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, writable: true, value: () => {} })
    })

    beforeEach(() => {
      downloads.length = 0
      blobs.length = 0
      Object.defineProperty(URL, 'createObjectURL', {
        configurable: true,
        writable: true,
        value: (blob: Blob) => {
          blobs.push(blob)
          return 'blob:gabarit'
        },
      })
      vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
        downloads.push(this.download)
      })
    })

    it('downloads the template of the day', async () => {
      const days: Array<string | null> = []
      renderMatrix(adv(), {
        'GET /api/v1/imports/templates/quotas': ({ url }) => {
          days.push(url.searchParams.get('day'))
          return new Response('xlsx', {
            status: 200,
            headers: { 'Content-Disposition': 'attachment; filename="quotas-2026-10-08.xlsx"' },
          })
        },
      })
      const user = userEvent.setup()
      await grid()

      await user.click(screen.getByRole('button', { name: 'Télécharger le gabarit' }))
      await waitFor(() => expect(downloads).toEqual(['quotas-2026-10-08.xlsx']))
      expect(days).toEqual([DAY])
      expect(await blobs[0]!.text()).toBe('xlsx')
    })

    it('shows why the template could not be downloaded', async () => {
      renderMatrix(adv(), {
        'GET /api/v1/imports/templates/quotas': () => problem(422, 'VALIDATION_FAILED', 'La journée du 08/10/2026 est clôturée.'),
      })
      const user = userEvent.setup()
      await grid()

      await user.click(screen.getByRole('button', { name: 'Télécharger le gabarit' }))
      expect(await screen.findByText('La journée du 08/10/2026 est clôturée.')).toBeInTheDocument()
      expect(downloads).toEqual([])
    })
  })
})
