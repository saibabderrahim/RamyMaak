import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import type { CurrentUser, Day, Import } from '../../../shared/api/types'
import { businessToday } from '../../../shared/format'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'
import { addDays } from '../shared/dates'
import { mockApiWithUploads } from './testUtils'

const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
const slow = { timeout: 5000 }

const ADV_PERMISSIONS = ['region.view', 'client.view', 'product.view', 'quota.view', 'quota.edit', 'quota.publish', 'day.manage', 'day.extend-cutoff']
const adv = (extra: string[] = []) => makeUser('adv', { permissions: [...ADV_PERMISSIONS, ...extra] })

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } })

const day = (date: string, status: Day['status'] = 'OPEN'): Day => ({
  day: date,
  weekday: new Date(`${date}T00:00:00Z`).getUTCDay(),
  is_working_day: true,
  status,
  status_label: status === 'OPEN' ? 'Ouverte' : 'Clôturée',
  delivery_date: addDays(date, 1),
  cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00' },
  cutoffs_at: { distribution: '', ordering: '', validation: '', closing: '' },
  closed_at: null,
  regions: [],
  created_by: null,
  created_at: '2026-10-08T07:00:00+00:00',
})

const quotaImport: Import = {
  id: 12,
  type: 'QUOTAS',
  type_label: 'Quotas',
  status: 'READY',
  business_day: '2026-10-12',
  mode: 'REPLACE',
  original_name: 'quotas-12-10.xlsx',
  rows_total: 2,
  rows_processed: 2,
  rows_ok: 2,
  rows_error: 0,
  summary: { create: 0, update: 2, unchanged: 0 },
  errors: [],
  errors_truncated: false,
  preview: [
    { row: 2, key: 'P0042', action: 'update', changes: { CENTRE: { old: 500, new: 450 }, EST: { old: 0, new: 1200 } } },
    // Produit absent du fichier, remis à 0 par le mode Remplacer : pas de numéro de ligne.
    { row: null as unknown as number, key: 'P0057', action: 'update', changes: { CENTRE: { old: 300, new: 0 } } },
  ],
  preview_truncated: false,
  has_error_report: false,
  user: { id: 4, username: 'a.adv', name: 'Amel ADV' },
  created_at: '2026-10-09T08:00:00+00:00',
  analyzed_at: '2026-10-09T08:01:00+00:00',
  applied_at: null,
  failure_message: null,
}

type Routes = Parameters<typeof mockApi>[0]

function routes(user: CurrentUser, extra: Routes = {}): Routes {
  return {
    'GET /api/v1/auth/me': () => json(user),
    'GET /api/v1/notifications': () =>
      json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, unread_count: 0 } }),
    'GET /api/v1/imports': () => json(page([])),
    'GET /api/v1/adv/days': ({ url }) =>
      json({
        data: [day('2026-10-11', 'CLOSED'), day('2026-10-12'), day('2026-10-13')],
        meta: { from: url.searchParams.get('from'), to: url.searchParams.get('to'), today: businessToday(), working_days: [0, 1, 2, 3, 4] },
      }),
    ...extra,
  }
}

// Module ADV chargé à la demande : compilé une fois avant les tests.
beforeAll(async () => {
  await import('../../adv')
}, 30_000)

afterEach(() => {
  vi.restoreAllMocks()
})

describe('ADV — quota import form (§8.1)', () => {
  it('requires an opened day and a mode, then uploads them with the workbook', async () => {
    let attempts = 0
    const daysRequests: URLSearchParams[] = []
    const { uploads } = mockApiWithUploads(
      routes(adv(), {
        'GET /api/v1/adv/days': ({ url }) => {
          daysRequests.push(url.searchParams)
          return json({ data: [day('2026-10-11', 'CLOSED'), day('2026-10-12'), day('2026-10-13')], meta: { from: '', to: '', today: businessToday(), working_days: [] } })
        },
        'POST /api/v1/imports': () =>
          ++attempts === 1
            ? problem(422, 'VALIDATION_FAILED', 'La journée du 12/10/2026 est clôturée : ses quotas ne sont plus modifiables.', {
                errors: { business_day: ['La journée du 12/10/2026 est clôturée : ses quotas ne sont plus modifiables.'] },
              })
            : json({ ...quotaImport, status: 'PENDING', preview: [], summary: null }, 202),
        'GET /api/v1/imports/12': () => json({ ...quotaImport, status: 'PENDING', preview: [], summary: null }),
      }),
    )
    const user = userEvent.setup()

    renderApp('/adv/imports')

    const radios = await screen.findAllByRole('radio', { name: /^(Quotas|Clients|Produits|Prix)/ }, slow)
    expect(radios.map((radio) => radio.getAttribute('value'))).toEqual(['QUOTAS'])
    expect(radios[0]).toBeChecked()

    // Gabarit indisponible tant que la journée n'est pas choisie.
    expect(screen.getByRole('button', { name: 'Télécharger le gabarit « Quotas »' })).toBeDisabled()
    expect(screen.getByText('Choisissez d’abord la journée.')).toBeInTheDocument()

    // Journées ouvertes (non clôturées) d'aujourd'hui à J+30.
    const select = await screen.findByLabelText('Journée')
    expect(within(select).getAllByRole('option').map((option) => option.getAttribute('value'))).toEqual(['', '2026-10-12', '2026-10-13'])
    expect(within(select).getByRole('option', { name: 'lundi 12/10/2026' })).toBeInTheDocument()
    expect(daysRequests[0]!.get('from')).toBe(businessToday())
    expect(daysRequests[0]!.get('to')).toBe(addDays(businessToday(), 30))

    // Journée et mode obligatoires.
    const file = screen.getByLabelText('Fichier Excel (.xlsx)')
    await user.upload(file, new File(['xlsx'], 'quotas-12-10.xlsx', { type: XLSX }))
    await user.click(screen.getByRole('button', { name: 'Déposer et analyser' }))
    expect(await screen.findByText('Choisissez une journée ouverte.')).toBeInTheDocument()
    expect(screen.getByText('Choisissez le mode d’import.')).toBeInTheDocument()
    expect(uploads).toHaveLength(0)

    await user.selectOptions(select, '2026-10-12')
    expect(screen.getByRole('link', { name: 'Télécharger le gabarit « Quotas »' })).toHaveAttribute('href', '/api/v1/imports/templates/quotas?day=2026-10-12')
    expect(screen.getByText('La journée prend exactement les valeurs du fichier pour chaque région présente : une cellule vide ou un produit absent vaut 0.')).toBeInTheDocument()
    await user.click(screen.getByRole('radio', { name: /^Compléter/ }))

    // Refus du serveur sur la journée, puis envoi accepté.
    await user.click(screen.getByRole('button', { name: 'Déposer et analyser' }))
    expect(await screen.findByText('La journée du 12/10/2026 est clôturée : ses quotas ne sont plus modifiables.')).toBeInTheDocument()
    expect(select).toHaveAttribute('aria-invalid', 'true')

    await user.click(screen.getByRole('button', { name: 'Déposer et analyser' }))
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/adv/imports/12'))
    expect(uploads).toHaveLength(2)
    const form = uploads[1]!.form
    expect(form.get('type')).toBe('QUOTAS')
    expect(form.get('business_day')).toBe('2026-10-12')
    expect(form.get('mode')).toBe('COMPLETE')
    expect((form.get('file') as File).name).toBe('quotas-12-10.xlsx')
  })

  it('preselects the type and the day from the address (« Importer » of the matrix)', async () => {
    mockApi(routes(adv(['product.manage'])))

    renderApp('/adv/imports?type=QUOTAS&day=2026-10-13')

    const radios = await screen.findAllByRole('radio', { name: /^(Quotas|Clients|Produits|Prix)/ }, slow)
    expect(radios.map((radio) => radio.getAttribute('value'))).toEqual(['QUOTAS', 'PRODUCTS', 'PRICES'])
    expect(screen.getByRole('radio', { name: /^Quotas/ })).toBeChecked()
    expect(await screen.findByLabelText('Journée')).toHaveValue('2026-10-13')
    expect(screen.getByRole('link', { name: 'Télécharger le gabarit « Quotas »' })).toHaveAttribute('href', '/api/v1/imports/templates/quotas?day=2026-10-13')
    expect(screen.getByRole('radio', { name: /^Remplacer/ })).not.toBeChecked()
    expect(screen.getByRole('radio', { name: /^Compléter/ })).not.toBeChecked()

    // Les autres types gardent leur gabarit, sans journée.
    await userEvent.setup().click(screen.getByRole('radio', { name: /^Produits/ }))
    expect(screen.getByRole('link', { name: 'Télécharger le gabarit « Produits »' })).toHaveAttribute('href', '/api/v1/imports/templates/products')
    expect(screen.queryByLabelText('Journée')).not.toBeInTheDocument()
  })

  it('keeps a requested day missing from the opened days, and ignores a type without the right', async () => {
    mockApi(routes(adv()))

    renderApp('/adv/imports?type=CLIENTS&day=2026-11-30')

    expect(await screen.findByRole('radio', { name: /^Quotas/ }, slow)).toBeChecked()
    expect(await screen.findByLabelText('Journée')).toHaveValue('2026-11-30')
  })

  it('tells when no day is opened, with a link to the calendar', async () => {
    mockApi(
      routes(adv(), {
        'GET /api/v1/adv/days': () => json({ data: [], meta: { from: businessToday(), to: businessToday(), today: businessToday(), working_days: [0, 1, 2, 3, 4] } }),
      }),
    )

    renderApp('/adv/imports')

    expect(await screen.findByText(/Aucune journée ouverte d’ici 30 jours/, undefined, slow)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Calendrier des journées' })).toHaveAttribute('href', '/adv/journees')
  })
})

describe('ADV — quota import follow-up', () => {
  it('shows the day, the mode and the preview by region code, then the drafts notice after applying', async () => {
    const { calls } = mockApi(
      routes(adv(), {
        'GET /api/v1/imports/12': () => json(quotaImport),
        'POST /api/v1/imports/12/apply': () => json({ ...quotaImport, status: 'APPLIED', applied_at: '2026-10-09T08:05:00+00:00' }),
      }),
    )
    const user = userEvent.setup()

    renderApp('/adv/imports/12')

    expect(await screen.findByRole('heading', { name: 'Import n° 12 — Quotas' }, slow)).toBeInTheDocument()
    expect(screen.getByText('Journée').nextElementSibling).toHaveTextContent('lundi 12/10/2026')
    expect(screen.getByText('Mode').nextElementSibling).toHaveTextContent('Remplacer')
    expect(screen.getByText(/Les valeurs importées sont enregistrées en brouillon/)).toBeInTheDocument()

    const rows = within(screen.getByTestId('import-preview')).getAllByRole('row')
    expect(rows).toHaveLength(2)
    expect(rows[0]).toHaveTextContent('P0042')
    expect(rows[0]).toHaveTextContent('CENTRE : 500 → 450')
    expect(rows[0]).toHaveTextContent('EST : 0 → 1 200')
    expect(rows[1]).toHaveTextContent('absent')
    expect(rows[1]).toHaveTextContent('CENTRE : 300 → 0')

    await user.click(screen.getByRole('button', { name: 'Appliquer' }))
    const dialog = screen.getByRole('dialog', { name: 'Appliquer l’import ?' })
    expect(dialog).toHaveTextContent('2 produit(s) modifié(s) : les valeurs seront enregistrées en brouillon pour la journée du lundi 12/10/2026')
    await user.click(within(dialog).getByRole('button', { name: 'Appliquer' }))

    expect(await screen.findByText('Valeurs enregistrées en brouillon : publiez-les depuis la matrice.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ouvrir la matrice des quotas du lundi 12/10/2026' })).toHaveAttribute('href', '/adv/journees/2026-10-12/quotas')
    expect(calls.filter((call) => call.method === 'POST' && call.path === '/api/v1/imports/12/apply')).toHaveLength(1)
  })

  it('lists quota imports with their day and mode in the history', async () => {
    mockApi(
      routes(adv(), {
        'GET /api/v1/imports': () => json(page([{ ...quotaImport, status: 'APPLIED' }, { ...quotaImport, id: 13, original_name: 'complement.xlsx', mode: 'COMPLETE', status: 'INVALID', rows_error: 1 }])),
      }),
    )

    renderApp('/adv/imports')

    const first = (await screen.findByRole('link', { name: 'quotas-12-10.xlsx' }, slow)).closest('tr')!
    expect(first).toHaveTextContent('Quotas12/10/2026 · Remplacer')
    expect(screen.getByRole('link', { name: 'quotas-12-10.xlsx' })).toHaveAttribute('href', '/adv/imports/12')
    const second = screen.getByRole('link', { name: 'complement.xlsx' }).closest('tr')!
    expect(second).toHaveTextContent('12/10/2026 · Compléter')
  })
})
