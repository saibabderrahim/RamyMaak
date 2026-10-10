import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { User } from '../../shared/api/types'
import { json, makeUser, mockApi, problem, renderApp } from '../../test/utils'

const account: User = {
  id: 7,
  username: 'c0012',
  name: 'Superette El Baraka',
  email: null,
  phone: null,
  role: 'client',
  client_id: 12,
  client: { id: 12, code: 'C0012', name: 'Superette El Baraka' },
  is_active: true,
  is_locked: false,
  locked_until: null,
  must_change_password: true,
  last_login_at: null,
  created_at: '2026-10-08T08:00:00+00:00',
  updated_at: '2026-10-08T08:00:00+00:00',
}

const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } })

describe('admin — accounts', () => {
  it('lists accounts with their status', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('admin')),
      'GET /api/v1/admin/users': () => json(page([{ ...account, is_locked: true }])),
    })

    renderApp('/admin/comptes')

    const row = (await screen.findByRole('link', { name: 'c0012' })).closest('tr')!
    expect(within(row).getByText('Superette El Baraka')).toBeInTheDocument()
    expect(within(row).getByText('Verrouillé')).toBeInTheDocument()
    expect(within(row).getByText('Mot de passe à changer')).toBeInTheDocument()
  })

  it('creates an account and shows the temporary password once', async () => {
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('admin')),
      'GET /api/v1/clients': ({ url }) =>
        json(
          page(
            url.searchParams.get('filter[search]') === 'baraka'
              ? [
                  { id: 12, code: 'C0012', name: 'Superette El Baraka', status: 'ACTIVE', region: { id: 3, code: 'CENTRE', name: 'Centre' } },
                  { id: 13, code: 'C0013', name: 'Baraka Fermée', status: 'INACTIVE', region: { id: 3, code: 'CENTRE', name: 'Centre' } },
                ]
              : [],
          ),
        ),
      'POST /api/v1/admin/users': () => json({ user: account, temporary_password: 'Kp7mX3qaRt' }, 201),
    })
    const user = userEvent.setup()

    renderApp('/admin/comptes/nouveau')
    await user.type(await screen.findByLabelText('Identifiant'), 'C0012')
    await user.type(screen.getByLabelText('Nom'), 'Superette El Baraka')
    // Rattachement au client par recherche (code ou raison sociale) ; un client inactif n'est pas proposé.
    await user.type(screen.getByLabelText('Client'), 'baraka')
    expect(await screen.findByRole('button', { name: 'C0013 — Baraka Fermée (inactif)' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'C0012 — Superette El Baraka · Centre' }))
    expect(screen.getByRole('link', { name: 'C0012 — Superette El Baraka' })).toHaveAttribute('href', '/admin/clients/12')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByTestId('temporary-password')).toHaveTextContent('Kp7mX3qaRt')
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({
      username: 'c0012',
      name: 'Superette El Baraka',
      email: null,
      phone: null,
      role: 'client',
      client_id: 12,
    })
  })

  it('shows server validation errors on the fields', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('admin')),
      'POST /api/v1/admin/users': () =>
        problem(422, 'VALIDATION_FAILED', 'La valeur du champ identifiant est déjà utilisée.', {
          errors: { username: ['La valeur du champ identifiant est déjà utilisée.'] },
        }),
    })
    const user = userEvent.setup()

    renderApp('/admin/comptes/nouveau')
    await user.type(await screen.findByLabelText('Identifiant'), 'c0012')
    await user.type(screen.getByLabelText('Nom'), 'Doublon')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    const field = screen.getByLabelText('Identifiant')
    await waitFor(() => expect(field).toHaveAttribute('aria-invalid', 'true'))
    expect(screen.getByText('La valeur du champ identifiant est déjà utilisée.')).toBeInTheDocument()
  })

  it('resets a password after confirmation and displays the new temporary password', async () => {
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('admin')),
      'GET /api/v1/admin/users/7': () => json({ ...account, must_change_password: false }),
      'POST /api/v1/admin/users/7/reset-password': () => json({ user: account, temporary_password: 'Zz9yXw8vUt' }),
    })
    window.confirm = () => true
    const user = userEvent.setup()

    renderApp('/admin/comptes/7')
    await user.click(await screen.findByRole('button', { name: 'Réinitialiser le mot de passe' }))

    expect(await screen.findByTestId('temporary-password')).toHaveTextContent('Zz9yXw8vUt')
    expect(calls.some((c) => c.path === '/api/v1/admin/users/7/reset-password')).toBe(true)
  })
})

describe('admin — settings', () => {
  const settings = {
    cutoffs: { distribution: '10:00', ordering: '13:00', validation: '15:00', closing: '23:00', validation_blocking: false },
    calendar: { working_days: [0, 1, 2, 3, 4] },
    limits: { no_limit_policy: 'FREE' },
    orders: { max_per_day: 1, min_amount: null },
    sessions: { remember_days: 30, staff_absolute_hours: 12, staff_idle_minutes: 120 },
    passwords: { min_length: 8, min_length_staff: 12 },
    auth: { max_failed_logins: 5, lockout_minutes: 15 },
    notifications: { email_enabled: false, quiet_start: '21:00', quiet_end: '06:00' },
  }

  it('saves the settings and confirms once the form shows the stored values', async () => {
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('admin')),
      'GET /api/v1/admin/settings': () => json(settings),
      'PUT /api/v1/admin/settings': ({ body }) => json(body),
    })
    const user = userEvent.setup()

    renderApp('/admin/parametres')
    const ordering = await screen.findByLabelText('Commande (client)')
    await user.clear(ordering)
    await user.type(ordering, '13:30')
    await user.click(screen.getByLabelText('Samedi'))
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Paramètres enregistrés.')).toBeInTheDocument()
    const sent = calls.find((c) => c.method === 'PUT')?.body as typeof settings
    expect(sent.cutoffs.ordering).toBe('13:30')
    expect(sent.calendar.working_days).toEqual([0, 1, 2, 3, 4, 6])
    expect(sent.orders.min_amount).toBeNull()
    expect(screen.getByLabelText('Commande (client)')).toHaveValue('13:30')
  })

  it('shows server errors next to the field', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('admin')),
      'GET /api/v1/admin/settings': () => json(settings),
      'PUT /api/v1/admin/settings': () =>
        problem(422, 'VALIDATION_FAILED', 'Les heures doivent se succéder.', {
          errors: { 'cutoffs.ordering': ['Les heures doivent se succéder.'] },
        }),
    })
    const user = userEvent.setup()

    renderApp('/admin/parametres')
    await user.click(await screen.findByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findAllByText('Les heures doivent se succéder.')).toHaveLength(2)
    expect(screen.getByLabelText('Commande (client)')).toHaveAttribute('aria-invalid', 'true')
  })
})
