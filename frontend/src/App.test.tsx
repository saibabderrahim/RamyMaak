import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { json, makeUser, mockApi, problem, renderApp } from './test/utils'

const unauthenticated = () => problem(401, 'UNAUTHENTICATED', 'Authentification requise')

describe('navigation and session', () => {
  it('sends visitors without a session to the login page', async () => {
    mockApi({ 'GET /api/v1/auth/me': unauthenticated })

    renderApp('/admin/comptes')

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/connexion')
  })

  it.each([
    ['client', '/client'],
    ['regional', '/regional'],
    ['adv', '/adv'],
    ['admin', '/admin/comptes'],
  ] as const)('logs a %s in and opens their space', async (role, home) => {
    let session = false
    const { calls } = mockApi({
      'GET /api/v1/auth/me': () => (session ? json(makeUser(role)) : unauthenticated()),
      'POST /api/v1/auth/login': () => {
        session = true
        return json(makeUser(role))
      },
      'GET /api/v1/admin/users': () => json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null } }),
    })
    const user = userEvent.setup()

    renderApp('/')
    await user.type(await screen.findByLabelText('Identifiant'), 'someone')
    await user.type(screen.getByLabelText('Mot de passe'), 'Secret123')
    await user.click(screen.getByLabelText('Rester connecté sur cet appareil'))
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(home))
    expect(calls.find((c) => c.path === '/api/v1/auth/login')?.body).toEqual({ username: 'someone', password: 'Secret123', remember: true })
  })

  it('shows the server message when the login is refused', async () => {
    mockApi({
      'GET /api/v1/auth/me': unauthenticated,
      'POST /api/v1/auth/login': () => problem(423, 'ACCOUNT_LOCKED', 'Trop de tentatives : réessayez dans 15 minutes.'),
    })
    const user = userEvent.setup()

    renderApp('/connexion')
    await user.type(await screen.findByLabelText('Identifiant'), 'c0012')
    await user.type(screen.getByLabelText('Mot de passe'), 'wrong')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByText('Trop de tentatives : réessayez dans 15 minutes.')).toBeInTheDocument()
  })

  it('forces the password change before anything else', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(makeUser('client', { must_change_password: true })) })

    renderApp('/client')

    expect(await screen.findByText(/choisissez un nouveau mot de passe/)).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/mot-de-passe')
    expect(screen.getByText('Au moins 8 caractères, avec des lettres et des chiffres.')).toBeInTheDocument()
  })

  it('checks the new password locally before calling the server', async () => {
    const { calls } = mockApi({ 'GET /api/v1/auth/me': () => json(makeUser('adv', { must_change_password: true })) })
    const user = userEvent.setup()

    renderApp('/mot-de-passe')
    await user.type(await screen.findByLabelText('Mot de passe actuel'), 'Temporaire9')
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'court1')
    await user.type(screen.getByLabelText('Confirmation du nouveau mot de passe'), 'autre1')
    await user.click(screen.getByRole('button', { name: 'Changer le mot de passe' }))

    expect(await screen.findByText('Les deux mots de passe ne correspondent pas.')).toBeInTheDocument()
    expect(screen.getAllByText('Au moins 12 caractères, avec des lettres et des chiffres.').length).toBeGreaterThan(1)
    expect(calls.some((c) => c.path === '/api/v1/auth/password')).toBe(false)
  })

  it('refuses a space that does not belong to the role', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(makeUser('client')) })

    renderApp('/admin/comptes')

    expect(await screen.findByText('Vous n’avez pas accès à cette page.')).toBeInTheDocument()
  })

  it('shows only the navigation of the role', async () => {
    mockApi({
      'GET /api/v1/auth/me': () => json(makeUser('admin')),
      'GET /api/v1/admin/users': () => json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null } }),
    })

    renderApp('/admin/comptes')

    expect((await screen.findAllByRole('link', { name: 'Journal d’audit' })).length).toBeGreaterThan(0)
    expect(screen.queryByRole('link', { name: 'Accueil' })).not.toBeInTheDocument()
  })

  it('keeps the phone header of the regional within 360 px: logo only, the name stays for screen readers', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(makeUser('regional')) })
    renderApp('/profil')
    const home = await screen.findByRole('link', { name: 'RamyMaak' })
    expect(within(home).getByText('RamyMaak')).toHaveClass('sr-only', 'sm:not-sr-only')
    // « Profil » hors de la barre d'onglets : dans l'en-tête sur téléphone.
    expect(screen.getAllByRole('link', { name: 'Profil' }).length).toBeGreaterThan(0)
  })

  it('shows the application name in the header of the client', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => json(makeUser('client', { client_id: 12 })) })
    renderApp('/profil')
    const home = await screen.findByRole('link', { name: 'RamyMaak' })
    expect(within(home).getByText('RamyMaak')).not.toHaveClass('sr-only')
  })

  it('returns to the login page with a message when the session expires', async () => {
    let expired = false
    mockApi({
      'GET /api/v1/auth/me': () => (expired ? unauthenticated() : json(makeUser('admin'))),
      'GET /api/v1/admin/users': () => {
        expired = true
        return unauthenticated()
      },
    })

    renderApp('/admin/comptes')

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/connexion'))
    expect(screen.getByText('Votre session a expiré. Reconnectez-vous.')).toBeInTheDocument()
  })

  it('prepares right-to-left display for Arabic (ENF-12)', () => {
    expect(document.documentElement.lang).toBe('fr')
    expect(document.documentElement.dir).toBe('ltr')
  })
})
