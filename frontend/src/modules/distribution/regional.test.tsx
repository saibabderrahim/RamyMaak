import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { businessToday } from '../../shared/format'
import { json, mockApi, problem, renderApp } from '../../test/utils'
import { nextDay } from './model'
import {
  CENTRE,
  cellOf,
  centreEntry,
  DAY,
  estEntry,
  findGrid,
  GRID_URL,
  makeGrid,
  notOpened,
  opened,
  regional,
  REGIONS_URL,
  renderDistribution,
} from './testing'

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const EST_GRID = makeGrid({
  region: { ...makeGrid().region, id: 4, code: 'EST', name: 'Est', status: 'QUOTA_PUBLISHED', distribution_version: 0, distribution_published_at: null },
  publication: { first: true, drafts: 0, notified_clients: 3, overallocated: [] },
})

const where = () => screen.getByTestId('where').textContent

describe('regional distribution page — day and region', () => {
  it('selects the only region of the perimeter and loads its grid', async () => {
    const { queries } = renderDistribution()
    await findGrid()
    expect(queries(REGIONS_URL)).toEqual([{ day: DAY }])
    expect(queries(GRID_URL)).toEqual([{ region: '3' }])
    // Une seule région : pas de liste.
    expect(screen.queryByRole('combobox', { name: 'Région' })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Répartition' })).toBeInTheDocument()
    expect(screen.getByText('Journée du jeudi 08/10/2026', { selector: 'p.font-semibold' })).toBeInTheDocument()
  })

  it('lists the regions, honours the region of the address and falls back to the first one', async () => {
    const routes = {
      [REGIONS_URL]: () => json(opened(DAY, [centreEntry(), estEntry()])),
      [GRID_URL]: ({ url }: { url: URL }) => json(url.searchParams.get('region') === '4' ? EST_GRID : makeGrid()),
    }
    const first = renderDistribution({ path: `/regional/repartition?day=${DAY}&region=99`, routes })
    await findGrid()
    const select = screen.getByRole('combobox', { name: 'Région' })
    expect(select).toHaveValue('3')
    expect(within(select).getAllByRole('option').map((option) => option.textContent)).toEqual([
      'CENTRE — Centre (Répartition envoyée (v1))',
      'EST — Est (Quotas publiés)',
    ])

    const user = userEvent.setup()
    await user.selectOptions(select, '4')
    expect(where()).toBe(`/regional/repartition?day=${DAY}&region=4`)
    expect(await screen.findByRole('heading', { name: 'Est (EST)' })).toBeInTheDocument()
    expect(first.queries(GRID_URL).map((query) => query.region)).toEqual(['3', '4'])
    first.unmount()

    renderDistribution({ path: `/regional/repartition?day=${DAY}&region=4`, routes })
    expect(await screen.findByRole('heading', { name: 'Est (EST)' })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Région' })).toHaveValue('4')
  })

  it('shows today by default and switches to tomorrow', async () => {
    const today = businessToday()
    const tomorrow = nextDay(today)
    const { queries } = renderDistribution({
      path: '/regional/repartition',
      routes: {
        [REGIONS_URL]: ({ url }) => json(url.searchParams.get('day') === today ? opened(today) : notOpened(tomorrow)),
        [`GET /api/v1/regional/days/${today}/grid`]: () => json(makeGrid({ day: { ...makeGrid().day, day: today } })),
      },
    })
    expect(await screen.findByRole('grid')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Aujourd’hui/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Demain/ })).toHaveAttribute('aria-pressed', 'false')

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Demain/ }))
    expect(where()).toBe(`/regional/repartition?day=${tomorrow}`)
    expect(await screen.findByText('Cette journée n’est pas encore ouverte.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Demain/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
    expect(queries(REGIONS_URL)).toEqual([{ day: today }, { day: tomorrow }])
  })

  it('ignores a malformed day in the address', async () => {
    const today = businessToday()
    const { queries } = renderDistribution({ path: '/regional/repartition?day=2026-02-31', routes: { [REGIONS_URL]: () => json(notOpened(today)) } })
    expect(await screen.findByText('Cette journée n’est pas encore ouverte.')).toBeInTheDocument()
    expect(queries(REGIONS_URL)).toEqual([{ day: today }])
  })

  it('explains when no region is assigned that day', async () => {
    renderDistribution({ routes: { [REGIONS_URL]: () => json(opened(DAY, [])) } })
    expect(await screen.findByText('Aucune région ne vous est affectée ce jour-là.')).toBeInTheDocument()
  })

  it('waits for the quotas of the ADV', async () => {
    renderDistribution({
      routes: {
        [REGIONS_URL]: () => json(opened(DAY, [centreEntry({ status: 'QUOTA_DRAFT', distribution_version: 0 })])),
        [GRID_URL]: () =>
          json(
            makeGrid({
              region: { ...makeGrid().region, status: 'QUOTA_DRAFT', quota_version: 0, distribution_version: 0, distribution_published_at: null },
              products: [],
              cells: {},
              can_edit: false,
              can_publish: false,
              publication: { first: true, drafts: 0, notified_clients: 3, overallocated: [] },
            }),
          ),
      },
    })
    expect(await screen.findByText('En attente des quotas de l’ADV')).toBeInTheDocument()
    expect(screen.getByText('La grille s’affichera dès la publication des quotas de la région.')).toBeInTheDocument()
    expect(screen.queryByRole('grid')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Valider et envoyer aux clients' })).not.toBeInTheDocument()
    expect(screen.queryByText(/Lecture seule/)).not.toBeInTheDocument()
  })

  it('explains a grid answered 404 (region removed from the perimeter)', async () => {
    renderDistribution({ routes: { [GRID_URL]: () => problem(404, 'NOT_FOUND', 'Ressource introuvable.') } })
    expect(await screen.findByText(/Grille introuvable/)).toBeInTheDocument()
  })

  it('offers to retry when the grid cannot be loaded', async () => {
    let fail = true
    renderDistribution({ routes: { [GRID_URL]: () => (fail ? problem(500, 'SERVER_ERROR', 'Service indisponible.') : json(makeGrid())) } })
    expect(await screen.findByText('Service indisponible.')).toBeInTheDocument()
    fail = false
    await userEvent.setup().click(screen.getByRole('button', { name: 'Réessayer' }))
    expect(await findGrid()).toBeInTheDocument()
  })

  it('is wired to the regional route', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(regional()),
      [REGIONS_URL]: () => json(opened(DAY)),
      [GRID_URL]: () => json(makeGrid()),
    })
    renderApp(`/regional/repartition?day=${DAY}&region=${CENTRE.id}`)
    expect(await screen.findByRole('grid', {}, { timeout: 10_000 })).toBeInTheDocument()
  }, 20_000)
})

describe('regional distribution page — read-only', () => {
  it('has no entry nor actions without can_edit and can_publish', async () => {
    renderDistribution({ routes: { [GRID_URL]: () => json(makeGrid({ can_edit: false, can_publish: false })) } })
    await findGrid()
    expect(screen.getByText('Lecture seule : vous ne pouvez pas modifier ces bornes.')).toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'Actions sur la répartition' })).not.toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'Actions de masse' })).not.toBeInTheDocument()
    expect(screen.getByRole('grid')).toHaveAttribute('aria-readonly', 'true')

    const user = userEvent.setup()
    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('4{Delete}')
    expect(screen.queryByRole('textbox', { name: /^Borne/ })).not.toBeInTheDocument()
    expect(cellOf('C0051', 'Lben 1 L')).toHaveAccessibleName(/pas de borne, lecture seule$/)
  })

  it('shows the closed day', async () => {
    const grid = makeGrid()
    renderDistribution({
      routes: {
        [GRID_URL]: () =>
          json(makeGrid({ day: { ...grid.day, status: 'CLOSED' }, region: { ...grid.region, status: 'CLOSED' }, can_edit: false, can_publish: false })),
      },
    })
    await findGrid()
    expect(screen.getByText('Journée clôturée : la répartition n’est plus modifiable.')).toBeInTheDocument()
    expect(screen.getByText('Clôturée')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reprendre J-1' })).not.toBeInTheDocument()
  })

  it('is read-only offline, with the time of the data on screen', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    // 08:42 UTC = 09:42 à Alger.
    vi.setSystemTime(new Date('2026-10-08T08:42:00Z'))
    let online = true
    vi.spyOn(navigator, 'onLine', 'get').mockImplementation(() => online)
    renderDistribution()
    await findGrid()
    expect(screen.getByRole('button', { name: 'Valider et envoyer aux clients' })).toBeInTheDocument()

    online = false
    window.dispatchEvent(new Event('offline'))
    expect(await screen.findByText('Hors ligne — données de 09:42')).toBeInTheDocument()
    expect(screen.getByText('La saisie des bornes et l’envoi aux clients reprendront au retour du réseau.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Valider et envoyer aux clients' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reprendre J-1' })).not.toBeInTheDocument()
    expect(screen.getByRole('grid')).toHaveAttribute('aria-readonly', 'true')

    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    await user.click(cellOf('C0051', 'Lben 1 L'))
    await user.keyboard('4')
    expect(screen.queryByRole('textbox', { name: /^Borne/ })).not.toBeInTheDocument()

    online = true
    window.dispatchEvent(new Event('online'))
    await waitFor(() => expect(screen.queryByText(/Hors ligne/)).not.toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Valider et envoyer aux clients' })).toBeInTheDocument()
  })
})
