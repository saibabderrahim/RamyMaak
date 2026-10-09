import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import type { Import } from '../../../shared/api/types'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'
import { mockApiWithUploads } from './testUtils'

const admin = makeUser('admin', { permissions: ['user.manage', 'client.manage', 'product.view', 'product.manage'] })
const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } })

const pending: Import = {
  id: 9,
  type: 'PRODUCTS',
  type_label: 'Produits',
  status: 'PENDING',
  original_name: 'produits.xlsx',
  rows_total: 0,
  rows_processed: 0,
  rows_ok: 0,
  rows_error: 0,
  summary: null,
  errors: [],
  errors_truncated: false,
  preview: [],
  preview_truncated: false,
  has_error_report: false,
  user: { id: 1, username: 'admin', name: 'Admin Ramy' },
  created_at: '2026-10-09T08:00:00+00:00',
  analyzed_at: null,
  applied_at: null,
  failure_message: null,
}

const analyzing: Import = { ...pending, status: 'ANALYZING', rows_total: 1520, rows_processed: 500 }

const ready: Import = {
  ...pending,
  status: 'READY',
  rows_total: 1520,
  rows_processed: 1520,
  rows_ok: 1520,
  summary: { create: 1200, update: 300, unchanged: 20 },
  preview: [
    {
      row: 2,
      key: 'P0042',
      action: 'update',
      changes: { name: { old: 'Jus orange', new: 'Jus orange 1 L' }, vat_rate: { old: 9, new: 19 }, is_active: { old: false, new: true } },
    },
    { row: 3, key: 'P0999', action: 'create', changes: { name: { old: null, new: 'Nectar abricot' }, family: { old: null, new: 'NECTAR' } } },
  ],
  preview_truncated: true,
  analyzed_at: '2026-10-09T08:01:00+00:00',
}

const invalid: Import = {
  ...pending,
  id: 10,
  type: 'CLIENTS',
  type_label: 'Clients',
  status: 'INVALID',
  original_name: 'clients.xlsx',
  rows_total: 3,
  rows_processed: 3,
  rows_ok: 1,
  rows_error: 2,
  summary: { create: 1, update: 0, unchanged: 0 },
  errors: [
    { row: 14, column: 'code_region', message: 'Ligne 14, colonne code_region : Code région inconnu : EST2' },
    { row: 22, column: 'code_client', message: 'Ligne 22 : client C0012 déjà présent ligne 9' },
  ],
  errors_truncated: true,
  has_error_report: true,
  analyzed_at: '2026-10-09T08:01:00+00:00',
}

// Module Admin chargé à la demande : compilé une fois avant les tests.
beforeAll(async () => {
  await import('../../admin')
}, 30_000)

afterEach(() => {
  vi.restoreAllMocks()
})

describe('back-office — import upload and follow-up', () => {
  it('uploads a workbook, follows the analysis, previews the changes and applies after confirmation', async () => {
    let polls = 0
    const { uploads, calls } = mockApiWithUploads({
      'GET /api/v1/auth/me': () => json(admin),
      'GET /api/v1/imports': () => json(page([])),
      'POST /api/v1/imports': () => json(pending, 202),
      'GET /api/v1/imports/9': () => json(++polls === 1 ? analyzing : ready),
      'POST /api/v1/imports/9/apply': () => json({ ...ready, status: 'APPLIED', applied_at: '2026-10-09T08:05:00+00:00' }),
    })
    const user = userEvent.setup()

    renderApp('/admin/imports')
    await user.click(await screen.findByRole('radio', { name: /^Produits/ }))
    expect(screen.getByRole('link', { name: 'Télécharger le gabarit « Produits »' })).toHaveAttribute('href', '/api/v1/imports/templates/products')
    expect(screen.getByLabelText('Fichier Excel (.xlsx)')).toHaveAttribute('accept', expect.stringContaining('.xlsx'))

    await user.upload(screen.getByLabelText('Fichier Excel (.xlsx)'), new File(['xlsx'], 'produits.xlsx', { type: XLSX }))
    await user.click(screen.getByRole('button', { name: 'Déposer et analyser' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/admin/imports/9'))
    expect(uploads).toHaveLength(1)
    expect(uploads[0]!.path).toBe('/api/v1/imports')
    expect(uploads[0]!.form.get('type')).toBe('PRODUCTS')
    expect((uploads[0]!.form.get('file') as File).name).toBe('produits.xlsx')
    expect(uploads[0]!.headers.get('X-XSRF-TOKEN')).toBe('token=1')

    // Réponse 202 affichée aussitôt, puis interrogation chaque seconde.
    expect(screen.getByRole('heading', { name: 'Import n° 9 — Produits' })).toBeInTheDocument()
    expect(screen.getByText('Fichier en attente d’analyse…')).toBeInTheDocument()
    expect(await screen.findByText('Analyse en cours : 500 / 1 520 lignes', {}, { timeout: 3_000 })).toBeInTheDocument()
    expect(screen.getByRole('progressbar', { name: 'Progression de l’analyse' })).toHaveAttribute('aria-valuenow', '33')

    expect(await screen.findByText('Aperçu des changements', {}, { timeout: 3_000 })).toBeInTheDocument()
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument()
    expect(screen.getByText('Analyse terminée sans erreur : vérifiez les changements avant d’appliquer.')).toBeInTheDocument()
    expect(screen.getByText('Seules les 200 premières lignes modifiées sont affichées ; les compteurs portent sur tout le fichier.')).toBeInTheDocument()

    const rows = within(screen.getByTestId('import-preview')).getAllByRole('row')
    expect(rows).toHaveLength(2)
    expect(rows[0]).toHaveTextContent('Modification')
    expect(rows[0]).toHaveTextContent('Désignation : Jus orange → Jus orange 1 L')
    expect(rows[0]).toHaveTextContent('Taux de TVA : 9 → 19')
    expect(rows[0]).toHaveTextContent('Actif : Non → Oui')
    expect(rows[1]).toHaveTextContent('Création')
    expect(rows[1]).toHaveTextContent('Famille : NECTAR')
    expect(rows[1]).not.toHaveTextContent('→')

    // Analyse terminée : plus d'interrogation.
    await new Promise((resolve) => setTimeout(resolve, 1_200))
    expect(polls).toBe(2)

    await user.click(screen.getByRole('button', { name: 'Appliquer' }))
    const dialog = screen.getByRole('dialog', { name: 'Appliquer l’import ?' })
    expect(dialog).toHaveTextContent('1 200 création(s) et 300 modification(s) seront enregistrées en une seule fois')
    expect(calls.some((c) => c.path === '/api/v1/imports/9/apply')).toBe(false)
    await user.click(within(dialog).getByRole('button', { name: 'Appliquer' }))
    expect(await screen.findByText(/^Import appliqué le 09\/10\/2026 \d{2}:\d{2} : 1\s200 création\(s\), 300 modification\(s\)\.$/)).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST' && c.path === '/api/v1/imports/9/apply')).toHaveLength(1)
    expect(screen.queryByRole('button', { name: 'Appliquer' })).not.toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  }, 15_000)

  it('does nothing without confirmation', async () => {
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(admin),
      'GET /api/v1/imports/9': () => json(ready),
    })
    const user = userEvent.setup()

    renderApp('/admin/imports/9')
    await user.click(await screen.findByRole('button', { name: 'Appliquer' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Annuler' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.some((c) => c.path === '/api/v1/imports/9/apply')).toBe(false)
  })

  it('shows the errors and the annotated file of an invalid import, without any apply action', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(admin),
      'GET /api/v1/imports/10': () => json(invalid),
    })

    renderApp('/admin/imports/10')

    expect(
      await screen.findByText('Le fichier contient 2 ligne(s) en erreur : il ne peut pas être appliqué. Corrigez-le puis déposez-le de nouveau.'),
    ).toBeInTheDocument()
    const rows = within(screen.getByTestId('import-errors')).getAllByRole('row')
    expect(rows).toHaveLength(2)
    expect(within(rows[0]!).getByText('14')).toBeInTheDocument()
    expect(within(rows[0]!).getByText('code_region')).toBeInTheDocument()
    expect(within(rows[0]!).getByText('Ligne 14, colonne code_region : Code région inconnu : EST2')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Télécharger le fichier annoté' })).toHaveAttribute('href', '/api/v1/imports/10/error-report')
    expect(screen.getByText('Seules les 500 premières erreurs sont affichées : le fichier annoté les signale toutes.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Déposer un nouveau fichier' })).toHaveAttribute('href', '/admin/imports')
    expect(screen.queryByRole('button', { name: 'Appliquer' })).not.toBeInTheDocument()
    expect(screen.queryByText('Aperçu des changements')).not.toBeInTheDocument()
  })

  it('shows the server refusal when new errors appear at apply time, then the invalid report', async () => {
    let current: Import = ready
    mockApi({
      'GET /api/v1/auth/me': () => json(admin),
      'GET /api/v1/imports/9': () => json(current),
      'POST /api/v1/imports/9/apply': () => {
        current = { ...invalid, id: 9, type: 'PRODUCTS', type_label: 'Produits' }
        return problem(409, 'IMPORT_HAS_ERRORS', 'Le fichier contient des erreurs : il ne peut pas être appliqué.')
      },
    })
    const user = userEvent.setup()

    renderApp('/admin/imports/9')
    await user.click(await screen.findByRole('button', { name: 'Appliquer' }))
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Appliquer' }))

    expect(await screen.findByText('Le fichier contient des erreurs : il ne peut pas être appliqué.')).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'Télécharger le fichier annoté' })).toHaveAttribute('href', '/api/v1/imports/9/error-report')
    expect(screen.queryByRole('button', { name: 'Appliquer' })).not.toBeInTheDocument()
  })

  it('shows the failure message of a failed import', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(admin),
      'GET /api/v1/imports/11': () => json({ ...pending, id: 11, status: 'FAILED', failure_message: 'Classeur illisible.' }),
    })

    renderApp('/admin/imports/11')

    expect(await screen.findByText('L’import a échoué : Classeur illisible.')).toBeInTheDocument()
    expect(screen.getByText('Échec')).toBeInTheDocument()
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument()
  })
})

describe('back-office — import form and history', () => {
  it('offers only the types allowed by the permissions and checks the file', async () => {
    const productsOnly = makeUser('admin', { permissions: ['user.manage', 'product.view', 'product.manage'] })
    const { uploads } = mockApiWithUploads({
      'GET /api/v1/auth/me': () => json(productsOnly),
      'GET /api/v1/imports': () => json(page([])),
      'POST /api/v1/imports': () =>
        problem(422, 'VALIDATION_FAILED', 'Les données sont invalides.', {
          errors: { file: ['Classeur avec macros refusé : enregistrez-le au format .xlsx.'] },
        }),
    })
    const user = userEvent.setup({ applyAccept: false })

    renderApp('/admin/imports')
    const radios = await screen.findAllByRole('radio')
    expect(radios.map((radio) => radio.getAttribute('value'))).toEqual(['PRODUCTS', 'PRICES'])
    expect(screen.getByRole('radio', { name: /^Produits/ })).toBeChecked()
    expect(screen.getByRole('link', { name: 'Télécharger le gabarit « Produits »' })).toBeInTheDocument()

    await user.click(screen.getByRole('radio', { name: /^Prix/ }))
    expect(screen.getByRole('link', { name: 'Télécharger le gabarit « Prix »' })).toHaveAttribute('href', '/api/v1/imports/templates/prices')

    await user.click(screen.getByRole('button', { name: 'Déposer et analyser' }))
    expect(await screen.findByText('Choisissez un fichier.')).toBeInTheDocument()

    const input = screen.getByLabelText('Fichier Excel (.xlsx)')
    await user.upload(input, new File(['xls'], 'prix.xls', { type: 'application/vnd.ms-excel' }))
    await user.click(screen.getByRole('button', { name: 'Déposer et analyser' }))
    expect(await screen.findByText('Fichier .xlsx attendu, 5 Mo maximum.')).toBeInTheDocument()
    expect(uploads).toHaveLength(0)

    await user.upload(input, new File(['xlsx'], 'prix.xlsx', { type: XLSX }))
    await user.click(screen.getByRole('button', { name: 'Déposer et analyser' }))
    expect(await screen.findByText('Classeur avec macros refusé : enregistrez-le au format .xlsx.')).toBeInTheDocument()
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(uploads[0]!.form.get('type')).toBe('PRICES')
    expect(screen.getByTestId('location')).toHaveTextContent('/admin/imports')
  })

  it('lists the history with status and counters, and filters it', async () => {
    const { fetchMock } = mockApi({
      'GET /api/v1/auth/me': () => json(admin),
      'GET /api/v1/imports': () => json(page([invalid, { ...ready, status: 'APPLIED' }])),
    })
    const user = userEvent.setup()

    renderApp('/admin/imports')

    const first = (await screen.findByRole('link', { name: 'clients.xlsx' })).closest('tr')!
    expect(within(first).getByText('Clients')).toBeInTheDocument()
    expect(within(first).getByText('Erreurs')).toBeInTheDocument()
    expect(within(first).getByText('2 en erreur')).toBeInTheDocument()
    expect(within(first).getByText('Admin Ramy')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'clients.xlsx' })).toHaveAttribute('href', '/admin/imports/10')

    const second = screen.getByRole('link', { name: 'produits.xlsx' }).closest('tr')!
    expect(within(second).getByText('Appliqué')).toBeInTheDocument()
    expect(second).toHaveTextContent('1 520 / 1 520 correcte(s)')

    await user.selectOptions(screen.getByLabelText('Statut'), 'INVALID')
    await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).includes('filter%5Bstatus%5D=INVALID'))).toBe(true))
    expect(fetchMock.mock.calls.some(([input]) => String(input).includes('sort=-created_at'))).toBe(true)
  })
})
