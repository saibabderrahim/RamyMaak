import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { vi } from 'vitest'
import { AppRoutes } from '../App'
import { createQueryClient } from '../shared/api/queryClient'
import { LocationProbe } from './LocationProbe'
import type { CurrentUser, Role } from '../shared/api/types'

type Handler = (request: { method: string; url: URL; body: unknown }) => Response | Promise<Response>

export function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json' },
  })
}

export function problem(status: number, code: string, detail: string, extra: Record<string, unknown> = {}): Response {
  return json({ type: `http://localhost/errors/${code.toLowerCase()}`, title: code, status, code, detail, ...extra }, status)
}

/**
 * Remplace fetch par un routeur « MÉTHODE /chemin » → réponse.
 */
export function mockApi(routes: Record<string, Handler>) {
  const calls: Array<{ method: string; path: string; body: unknown; headers: Headers }> = []
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input), 'http://localhost')
    const method = (init?.method ?? 'GET').toUpperCase()
    const body = typeof init?.body === 'string' ? JSON.parse(init.body) : undefined
    calls.push({ method, path: url.pathname, body, headers: new Headers(init?.headers) })

    if (url.pathname === '/sanctum/csrf-cookie') {
      document.cookie = 'XSRF-TOKEN=token%3D1; path=/'
      return new Response(null, { status: 204 })
    }

    const handler = routes[`${method} ${url.pathname}`]
    if (!handler) return problem(404, 'NOT_FOUND', `Route non simulée : ${method} ${url.pathname}`)
    return handler({ method, url, body })
  })
  vi.stubGlobal('fetch', fetchMock)
  return { fetchMock, calls }
}

export function makeUser(role: Role, overrides: Partial<CurrentUser> = {}): CurrentUser {
  return {
    id: 1,
    username: role === 'client' ? 'c0012' : role,
    name: `Utilisateur ${role}`,
    email: null,
    phone: null,
    role,
    role_label: role,
    permissions: [],
    client_id: null,
    must_change_password: false,
    password_min_length: role === 'admin' || role === 'adv' ? 12 : 8,
    perimeter: { all_regions: role === 'admin', region_ids: [] },
    last_login_at: null,
    realtime: null,
    push: null,
    preferences: { reminders: true },
    ...overrides,
  }
}

export function renderApp(path: string) {
  const queryClient = createQueryClient()
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <AppRoutes />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
