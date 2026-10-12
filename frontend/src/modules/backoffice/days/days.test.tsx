import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import type { CurrentUser, Day, RegionDay } from '../../../shared/api/types'
import { businessToday } from '../../../shared/format'
import { disconnectRealtime } from '../../../shared/realtime'
import { json, makeUser, mockApi, problem, renderApp } from '../../../test/utils'
import { addDays } from '../shared/dates'

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

// Premier rendu : le module du rôle est chargé à la demande.
const slow = { timeout: 5000 }

const ADV_PERMISSIONS = ['region.view', 'client.view', 'product.view', 'quota.view', 'quota.edit', 'quota.publish', 'day.manage', 'day.extend-cutoff']
const ADMIN_PERMISSIONS = ['user.manage', 'region.view', 'region.manage', 'client.view', 'client.manage', 'product.view', 'product.manage', 'quota.view', 'day.extend-cutoff']
const adv = (overrides: Partial<CurrentUser> = {}) => makeUser('adv', { permissions: ADV_PERMISSIONS, ...overrides })
const admin = () => makeUser('admin', { permissions: ADMIN_PERMISSIONS })

const centre: RegionDay = {
  region: { id: 3, code: 'CENTRE', name: 'Centre' },
  status: 'QUOTA_PUBLISHED',
  status_label: 'Quotas publiés',
  quota_version: 2,
  quota_published_at: '2026-10-08T06:55:00+00:00',
  quota_last_published_at: '2026-10-08T08:10:00+00:00',
  distribution_version: 0,
  distribution_published_at: null,
  pending_drafts: 3,
  cutoffs: { distribution: '11:15', ordering: '13:00' },
  extended: { distribution: true, ordering: false },
}

const est: RegionDay = {
  region: { id: 4, code: 'EST', name: 'Est' },
  status: 'QUOTA_DRAFT',
  status_label: 'Quotas en brouillon',
  quota_version: 0,
  quota_published_at: null,
  quota_last_published_at: null,
  distribution_version: 0,
  distribution_published_at: null,
  pending_drafts: 0,
  cutoffs: { distribution: '10:00', ordering: '13:00' },
  extended: { distribution: false, ordering: false },
}

const openDay: Day = {
  day: '2026-10-08',
  weekday: 4,
  is_working_day: true,
  status: 'OPEN',
  status_label: 'Ouverte',
  delivery_date: '2026-10-11',
  cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00' },
  cutoffs_at: {
    distribution: '2026-10-08T09:00:00+00:00',
    ordering: '2026-10-08T12:00:00+00:00',
    validation: '2026-10-08T14:00:00+00:00',
    closing: '2026-10-08T22:00:00+00:00',
  },
  closed_at: null,
  regions: [centre, est],
  created_by: { id: 4, username: 'a.adv', name: 'Amel ADV' },
  created_at: '2026-10-07T15:00:00+00:00',
}

const closedDay: Day = {
  ...openDay,
  day: '2026-10-07',
  weekday: 3,
  status: 'CLOSED',
  status_label: 'Clôturée',
  delivery_date: '2026-10-08',
  closed_at: '2026-10-07T22:00:00+00:00',
  regions: [
    { ...centre, status: 'CLOSED', status_label: 'Clôturée', pending_drafts: 0 },
    { ...est, status: 'CLOSED', status_label: 'Clôturée' },
  ],
}

const meta = { from: '2026-10-01', to: '2026-10-22', today: '2026-10-08', working_days: [0, 1, 2, 3, 4] }

const opened = (day: string, weekday: number): Day => ({
  ...openDay,
  day,
  weekday,
  is_working_day: weekday <= 4,
  delivery_date: addDays(day, 1),
  regions: [{ ...est, pending_drafts: 0 }],
})

type Routes = Parameters<typeof mockApi>[0]

/** Routes communes : profil, cloche des notifications (en-tête). */
function baseRoutes(user: CurrentUser): Routes {
  return {
    'GET /api/v1/auth/me': () => json(user),
    'GET /api/v1/notifications': () =>
      json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, unread_count: 0 } }),
  }
}

function calendarApi(user: CurrentUser, extra: Routes = {}) {
  const requests: URLSearchParams[] = []
  const mocked = mockApi({
    ...baseRoutes(user),
    'GET /api/v1/adv/days': ({ url }) => {
      requests.push(url.searchParams)
      const from = url.searchParams.get('from')
      const to = url.searchParams.get('to')
      return json({ data: from ? [] : [closedDay, openDay], meta: from && to ? { ...meta, from, to } : meta })
    },
    ...extra,
  })
  return { ...mocked, requests }
}

function rowOf(day: string): HTMLElement {
  const row = document.querySelector<HTMLElement>(`tr[data-day="${day}"]`)
  if (!row) throw new Error(`Ligne absente : ${day}`)
  return row
}

// Modules Admin et ADV chargés à la demande : compilés une fois avant les tests.
beforeAll(async () => {
  await Promise.all([import('../../admin'), import('../../adv')])
}, 30_000)

afterEach(() => {
  disconnectRealtime()
  fake.stateChange = null
  fake.listeners.clear()
  vi.restoreAllMocks()
})

describe('ADV — calendar of business days (EF-ADV-01)', () => {
  it('renders every date of the interval, opened or not, with status, delivery date and region pills', async () => {
    const { requests } = calendarApi(adv())
    const user = userEvent.setup()

    renderApp('/adv/journees')

    const table = await screen.findByRole('table', { name: 'Calendrier des journées' }, slow)
    expect(within(table).getAllByRole('row')).toHaveLength(1 + 22)
    expect(screen.getByText('Du 01/10/2026 au 22/10/2026')).toBeInTheDocument()

    // Aujourd'hui : mis en évidence, ouvert, pastilles par région.
    const today = rowOf('2026-10-08')
    expect(today).toHaveAttribute('aria-current', 'date')
    expect(within(today).getByText('Aujourd’hui')).toBeInTheDocument()
    expect(within(today).getByRole('link', { name: 'jeudi 08/10/2026' })).toHaveAttribute('href', '/adv/journees/2026-10-08')
    expect(within(today).getByText('Ouverte')).toBeInTheDocument()
    expect(within(today).getByText('11/10/2026')).toBeInTheDocument()
    const pills = within(within(today).getByRole('list', { name: 'Régions' })).getAllByRole('listitem')
    expect(pills).toHaveLength(2)
    expect(pills[0]).toHaveAttribute('data-status', 'QUOTA_PUBLISHED')
    expect(pills[0]).toHaveTextContent('CENTRE — Centre : Quotas publiés, 3 brouillons non publiés')
    expect(pills[0]).toHaveAttribute('title', 'CENTRE — Centre : Quotas publiés — 3 brouillons non publiés')
    expect(pills[1]).toHaveAttribute('data-status', 'QUOTA_DRAFT')
    expect(pills[1]).toHaveTextContent('EST — Est : Quotas en brouillon')
    expect(pills[1]).not.toHaveTextContent('brouillons non publiés')
    expect(within(today).getByRole('link', { name: 'Saisir les quotas du jeudi 08/10/2026' })).toHaveAttribute(
      'href',
      '/adv/journees/2026-10-08/quotas',
    )
    expect(within(today).queryByRole('button')).not.toBeInTheDocument()

    // Journée clôturée : consultation des quotas.
    const closed = rowOf('2026-10-07')
    expect(closed).not.toHaveAttribute('aria-current')
    expect(within(closed).getByText('Clôturée', { selector: 'span.rounded-full' })).toBeInTheDocument()
    expect(within(closed).getByRole('link', { name: 'Voir les quotas du mercredi 07/10/2026' })).toHaveAttribute(
      'href',
      '/adv/journees/2026-10-07/quotas',
    )

    // Dates non ouvertes : jour non ouvré signalé, ouverture d'aujourd'hui à J+30 seulement.
    const saturday = rowOf('2026-10-10')
    expect(within(saturday).getByText('Non ouvré')).toBeInTheDocument()
    expect(within(saturday).getByText('Non ouverte')).toBeInTheDocument()
    expect(within(saturday).getByRole('button', { name: 'Ouvrir la journée du samedi 10/10/2026' })).toBeInTheDocument()
    expect(within(rowOf('2026-10-09')).getByText('Demain')).toBeInTheDocument()
    expect(within(rowOf('2026-10-11')).queryByText('Non ouvré')).not.toBeInTheDocument()
    expect(within(rowOf('2026-10-02')).queryByRole('button')).not.toBeInTheDocument()
    expect(within(rowOf('2026-10-02')).queryByRole('link')).not.toBeInTheDocument()

    // Accès direct à J et J+1.
    expect(screen.getByRole('link', { name: 'Aujourd’hui (J)' })).toHaveAttribute('href', '/adv/journees/2026-10-08')
    expect(screen.getByRole('link', { name: 'Demain (J+1)' })).toHaveAttribute('href', '/adv/journees/2026-10-09')
    expect(screen.getByText('Répartition publiée')).toBeInTheDocument()

    // Période suivante, de même longueur.
    expect(requests[0]!.get('from')).toBeNull()
    await user.click(screen.getByRole('button', { name: /Période suivante/ }))
    await waitFor(() => expect(requests.some((params) => params.get('from') === '2026-10-23' && params.get('to') === '2026-11-13')).toBe(true))
    expect(await screen.findByText('Du 23/10/2026 au 13/11/2026')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Revenir à la période actuelle' }))
    expect(await screen.findByText('Du 01/10/2026 au 22/10/2026')).toBeInTheDocument()
  })

  it('opens a working day directly and shows its quota matrix', async () => {
    const { calls } = calendarApi(adv(), {
      'POST /api/v1/adv/days': ({ body }) => json(opened((body as { day: string }).day, 0), 201),
    })
    const user = userEvent.setup()

    renderApp('/adv/journees')
    await user.click(await screen.findByRole('button', { name: 'Ouvrir la journée du dimanche 11/10/2026' }, slow))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/adv/journees/2026-10-11/quotas'))
    expect(calls.filter((call) => call.method === 'POST' && call.path === '/api/v1/adv/days').map((call) => call.body)).toEqual([{ day: '2026-10-11' }])
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('asks for a confirmation before opening a non-working day, and shows a refusal', async () => {
    let attempts = 0
    const { calls, requests } = calendarApi(adv(), {
      'POST /api/v1/adv/days': ({ body }) =>
        ++attempts === 1
          ? problem(409, 'INVALID_TRANSITION', 'La journée du 09/10/2026 est déjà ouverte.')
          : json(opened((body as { day: string }).day, 5), 201),
    })
    const user = userEvent.setup()
    const posts = () => calls.filter((call) => call.method === 'POST' && call.path === '/api/v1/adv/days')

    renderApp('/adv/journees')
    const open = await screen.findByRole('button', { name: 'Ouvrir la journée du vendredi 09/10/2026' }, slow)

    await user.click(open)
    let dialog = screen.getByRole('dialog', { name: 'Ouvrir un jour non ouvré ?' })
    expect(dialog).toHaveTextContent('Le vendredi 09/10/2026 n’est pas un jour ouvré. L’ouvrir quand même ?')
    await user.click(within(dialog).getByRole('button', { name: 'Annuler' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(posts()).toHaveLength(0)

    // Refus du serveur affiché dans la boîte ; le calendrier est relu.
    const before = requests.length
    await user.click(open)
    dialog = screen.getByRole('dialog', { name: 'Ouvrir un jour non ouvré ?' })
    await user.click(within(dialog).getByRole('button', { name: 'Ouvrir la journée' }))
    expect(await within(dialog).findByText('La journée du 09/10/2026 est déjà ouverte.')).toBeInTheDocument()
    await waitFor(() => expect(requests.length).toBeGreaterThan(before))
    expect(screen.getByTestId('location')).toHaveTextContent('/adv/journees')

    await user.click(within(dialog).getByRole('button', { name: 'Ouvrir la journée' }))
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/adv/journees/2026-10-09/quotas'))
    expect(posts().map((call) => call.body)).toEqual([{ day: '2026-10-09' }, { day: '2026-10-09' }])
  })

  it('shows the same calendar read-only to the Admin', async () => {
    calendarApi(admin())

    renderApp('/admin/journees')

    const today = (await screen.findByRole('table', { name: 'Calendrier des journées' }, slow)) && rowOf('2026-10-08')
    expect(within(today).getByRole('link', { name: 'jeudi 08/10/2026' })).toHaveAttribute('href', '/admin/journees/2026-10-08')
    expect(within(today).getByRole('link', { name: 'Voir les quotas du jeudi 08/10/2026' })).toHaveAttribute(
      'href',
      '/admin/journees/2026-10-08/quotas',
    )
    expect(screen.queryAllByRole('button', { name: /Ouvrir la journée/ })).toHaveLength(0)
    expect(screen.getByRole('link', { name: 'Demain (J+1)' })).toHaveAttribute('href', '/admin/journees/2026-10-09')
  })

  it('refreshes the calendar on the realtime events of the day', async () => {
    const { requests } = calendarApi(adv({ realtime: { key: 'app-key' } }))
    const channel = `adv.${businessToday()}`

    renderApp('/adv/journees')
    await screen.findByRole('table', { name: 'Calendrier des journées' }, slow)
    await waitFor(() => expect(fake.listeners.get(`${channel}:.DraftsUpdated`)?.size).toBe(1))
    expect(fake.listeners.get(`${channel}:.QuotaPublished`)?.size).toBe(1)
    expect(fake.listeners.get(`${channel}:.DayClosed`)?.size).toBe(1)
    expect(fake.listeners.get(`adv.${addDays(businessToday(), 1)}:.DraftsUpdated`)?.size).toBe(1)

    const before = requests.length
    act(() => fake.listeners.get(`${channel}:.DraftsUpdated`)?.forEach((listener) => listener({ region_ids: [3], day: businessToday() })))
    await waitFor(() => expect(requests.length).toBe(before + 1))
  })
})

describe('ADV — business day page', () => {
  function dayApi(user: CurrentUser, day: Day = openDay, extra: Routes = {}) {
    return mockApi({
      ...baseRoutes(user),
      [`GET /api/v1/adv/days/${day.day}`]: () => json(day),
      ...extra,
    })
  }

  it('shows the day and updates its cutoffs, with the server refusals on the fields', async () => {
    let patches = 0
    const { calls } = dayApi(adv(), openDay, {
      'PATCH /api/v1/adv/days/2026-10-08': ({ body }) => {
        if (++patches === 1) {
          return problem(422, 'VALIDATION_FAILED', 'L’heure de clôture doit être à venir.', {
            errors: { 'cutoffs.closing': ['L’heure de clôture doit être à venir.'] },
          })
        }
        const input = body as { cutoffs?: Partial<Day['cutoffs']> }
        return json({ ...openDay, cutoffs: { ...openDay.cutoffs, ...input.cutoffs } })
      },
    })
    const user = userEvent.setup()

    renderApp('/adv/journees/2026-10-08')

    expect(await screen.findByRole('heading', { name: 'Journée du jeudi 08/10/2026' }, slow)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Saisir les quotas du jeudi 08/10/2026' })).toHaveAttribute('href', '/adv/journees/2026-10-08/quotas')
    expect(screen.getByRole('link', { name: 'Calendrier' })).toHaveAttribute('href', '/adv/journees')
    expect(screen.getByText('Amel ADV')).toBeInTheDocument()

    const closing = screen.getByLabelText('Clôture de la journée')
    expect(screen.getByLabelText('Répartition (régional)')).toHaveValue('10:00')
    expect(screen.getByLabelText('Commande (client)')).toHaveValue('13:00')
    expect(screen.getByLabelText('Validation (régional)')).toHaveValue('15:00')
    expect(closing).toHaveValue('23:00')
    expect(screen.getByLabelText('Date de livraison')).toHaveValue('2026-10-11')

    // Rien de changé : aucun envoi.
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Aucune modification à enregistrer.')).toBeInTheDocument()

    // Ordre contrôlé avant l'envoi.
    const ordering = screen.getByLabelText('Commande (client)')
    await user.clear(ordering)
    await user.type(ordering, '09:30')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Les heures limites doivent se suivre : répartition, commande, validation, puis clôture.')).toBeInTheDocument()
    expect(ordering).toHaveAttribute('aria-invalid', 'true')
    expect(calls.some((call) => call.method === 'PATCH')).toBe(false)

    // Refus du serveur (clôture passée) sur le champ ; seules les valeurs changées partent.
    await user.clear(ordering)
    await user.type(ordering, '13:30')
    await user.clear(closing)
    await user.type(closing, '21:00')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('L’heure de clôture doit être à venir.')).toBeInTheDocument()
    expect(closing).toHaveAttribute('aria-invalid', 'true')
    expect(calls.find((call) => call.method === 'PATCH')?.body).toEqual({ cutoffs: { ordering: '13:30', closing: '21:00' } })

    await user.clear(closing)
    await user.type(closing, '23:00')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Heures limites et date de livraison enregistrées.')).toBeInTheDocument()
    expect(calls.filter((call) => call.method === 'PATCH').at(-1)?.body).toEqual({ cutoffs: { ordering: '13:30' } })
    expect(screen.getByLabelText('Commande (client)')).toHaveValue('13:30')
  })

  it('lists the regions and extends a cutoff for one of them', async () => {
    let attempts = 0
    let current = openDay
    const { calls } = mockApi({
      ...baseRoutes(adv()),
      'GET /api/v1/adv/days/2026-10-08': () => json(current),
      'POST /api/v1/adv/days/2026-10-08/regions/4/extend-cutoff': ({ body }) => {
        if (++attempts === 1) {
          return problem(422, 'VALIDATION_FAILED', 'La prolongation doit être postérieure à l’heure limite actuelle (13:00).', {
            errors: { until: ['La prolongation doit être postérieure à l’heure limite actuelle (13:00).'] },
          })
        }
        const input = body as { until: string }
        const extended: RegionDay = { ...est, cutoffs: { ...est.cutoffs, ordering: input.until }, extended: { distribution: false, ordering: true } }
        current = { ...openDay, regions: [centre, extended] }
        return json(extended)
      },
    })
    const user = userEvent.setup()

    renderApp('/adv/journees/2026-10-08')

    const table = await screen.findByRole('table', { name: 'Régions' }, slow)
    const centreRow = within(table).getByRole('row', { name: /CENTRE/ })
    expect(within(centreRow).getByText('Quotas publiés')).toBeInTheDocument()
    expect(within(centreRow).getByText('v2')).toBeInTheDocument()
    expect(within(centreRow).getByText(/^08\/10\/2026 \d{2}:\d{2}$/)).toBeInTheDocument()
    expect(within(centreRow).getByText('11:15')).toBeInTheDocument()
    expect(within(centreRow).getByText('prolongée')).toBeInTheDocument()
    expect(within(centreRow).getByText('3 brouillons non publiés')).toBeInTheDocument()
    const estRow = within(table).getByRole('row', { name: /EST/ })
    expect(within(estRow).getByText('Non publiés')).toBeInTheDocument()
    expect(within(estRow).queryByText('prolongée')).not.toBeInTheDocument()

    await user.click(within(estRow).getByRole('button', { name: 'Prolonger une heure limite de la région Est' }))
    const dialog = screen.getByRole('dialog', { name: 'Prolonger une heure limite — Est' })
    expect(within(dialog).getByRole('radio', { name: /^Répartition/ })).toBeChecked()
    await user.click(within(dialog).getByRole('radio', { name: /^Commande/ }))

    // Heure obligatoire.
    await user.click(within(dialog).getByRole('button', { name: 'Prolonger' }))
    expect(await within(dialog).findByText('Heure attendue au format HH:MM (ex. 11:15).')).toBeInTheDocument()

    const until = within(dialog).getByLabelText('Nouvelle heure limite')
    await user.type(until, '12:30')
    await user.click(within(dialog).getByRole('button', { name: 'Prolonger' }))
    expect(await within(dialog).findByText('La prolongation doit être postérieure à l’heure limite actuelle (13:00).')).toBeInTheDocument()
    expect(until).toHaveAttribute('aria-invalid', 'true')

    await user.clear(until)
    await user.type(until, '14:00')
    await user.click(within(dialog).getByRole('button', { name: 'Prolonger' }))
    expect(await screen.findByText('Heure limite de commande de la région Est prolongée jusqu’à 14:00. Les régionaux sont prévenus.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(calls.filter((call) => call.method === 'POST').map((call) => call.body)).toEqual([
      { kind: 'ORDERING', until: '12:30' },
      { kind: 'ORDERING', until: '14:00' },
    ])
    const updated = within(screen.getByRole('table', { name: 'Régions' })).getByRole('row', { name: /EST/ })
    expect(within(updated).getByText('14:00')).toBeInTheDocument()
    expect(within(updated).getByText('prolongée')).toBeInTheDocument()
  })

  it('offers to open a day that is not opened yet, then shows its matrix', async () => {
    const calendar: URLSearchParams[] = []
    const { calls } = mockApi({
      ...baseRoutes(adv()),
      'GET /api/v1/adv/days/2026-10-12': () => problem(404, 'NOT_FOUND', 'Ressource introuvable.'),
      'GET /api/v1/adv/days': ({ url }) => {
        calendar.push(url.searchParams)
        return json({ data: [], meta: { ...meta, from: '2026-10-12', to: '2026-10-12' } })
      },
      'POST /api/v1/adv/days': () => json(opened('2026-10-12', 1), 201),
    })
    const user = userEvent.setup()

    renderApp('/adv/journees/2026-10-12')

    expect(await screen.findByText('Journée non ouverte', undefined, slow)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Journée du lundi 12/10/2026' })).toBeInTheDocument()
    expect(screen.getByText('La journée du lundi 12/10/2026 n’a pas encore été ouverte : aucun quota ne peut y être saisi.')).toBeInTheDocument()
    await user.click(await screen.findByRole('button', { name: 'Ouvrir la journée du lundi 12/10/2026' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/adv/journees/2026-10-12/quotas'))
    expect(calendar[0]!.get('from')).toBe('2026-10-12')
    expect(calendar[0]!.get('to')).toBe('2026-10-12')
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ day: '2026-10-12' })
  })

  it('does not offer to open a past day', async () => {
    mockApi({
      ...baseRoutes(adv()),
      'GET /api/v1/adv/days/2026-10-02': () => problem(404, 'NOT_FOUND', 'Ressource introuvable.'),
      'GET /api/v1/adv/days': () => json({ data: [], meta: { ...meta, from: '2026-10-02', to: '2026-10-02' } }),
    })

    renderApp('/adv/journees/2026-10-02')

    expect(await screen.findByText('Une journée passée ne peut plus être ouverte.', undefined, slow)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Ouvrir la journée/ })).not.toBeInTheDocument()
  })

  it('shows the day read-only to the Admin, who may still extend a cutoff', async () => {
    const { calls } = dayApi(admin())

    renderApp('/admin/journees/2026-10-08')

    expect(await screen.findByRole('heading', { name: 'Journée du jeudi 08/10/2026' }, slow)).toBeInTheDocument()
    expect(screen.queryByLabelText('Répartition (régional)')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enregistrer' })).not.toBeInTheDocument()
    expect(screen.getByText('Consultation seule : la modification des heures limites est réservée à l’ADV.')).toBeInTheDocument()
    expect(screen.getByText('23:00')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Voir les quotas du jeudi 08/10/2026' })).toHaveAttribute('href', '/admin/journees/2026-10-08/quotas')
    expect(screen.getAllByRole('button', { name: /^Prolonger une heure limite/ })).toHaveLength(2)
    expect(calls.some((call) => call.path === '/api/v1/adv/days')).toBe(false)
  })

  it('shows a closed day without any action', async () => {
    dayApi(adv(), closedDay)

    renderApp('/adv/journees/2026-10-07')

    expect(await screen.findByText('Journée clôturée : les heures limites et les quotas ne sont plus modifiables (§4.4).', undefined, slow)).toBeInTheDocument()
    expect(screen.queryByLabelText('Clôture de la journée')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Prolonger/ })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Voir les quotas du mercredi 07/10/2026' })).toBeInTheDocument()
  })

  it('refreshes the day on the realtime events of its channel', async () => {
    const user = adv({ realtime: { key: 'app-key' } })
    const { calls } = dayApi(user)

    renderApp('/adv/journees/2026-10-08')
    await screen.findByRole('heading', { name: 'Journée du jeudi 08/10/2026' }, slow)
    await waitFor(() => expect(fake.listeners.get('adv.2026-10-08:.QuotaPublished')?.size).toBe(1))

    const reads = () => calls.filter((call) => call.path === '/api/v1/adv/days/2026-10-08').length
    const before = reads()
    act(() => fake.listeners.get('adv.2026-10-08:.QuotaPublished')?.forEach((listener) => listener({ region_id: 3, day: '2026-10-08', version: 3, first: false })))
    await waitFor(() => expect(reads()).toBe(before + 1))
  })

  it('says so for a malformed date', async () => {
    mockApi(baseRoutes(adv()))

    renderApp('/adv/journees/demain')

    expect(await screen.findByText('Date de journée invalide : format attendu AAAA-MM-JJ.', undefined, slow)).toBeInTheDocument()
  })
})
