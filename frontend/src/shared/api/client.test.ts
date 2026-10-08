import { describe, expect, it } from 'vitest'
import { mockApi, problem } from '../../test/utils'
import { api, ApiError, NetworkError } from './client'

describe('api client', () => {
  it('fetches the CSRF cookie before a write and sends the token header', async () => {
    const { calls } = mockApi({ 'POST /api/v1/auth/logout': () => new Response(null, { status: 204 }) })

    await expect(api.post('/auth/logout')).resolves.toBeUndefined()

    expect(calls.map((c) => `${c.method} ${c.path}`)).toEqual(['GET /sanctum/csrf-cookie', 'POST /api/v1/auth/logout'])
    expect(calls[1]?.headers.get('X-XSRF-TOKEN')).toBe('token=1')
  })

  it('renews an expired CSRF token once and replays the request (419)', async () => {
    let attempts = 0
    const { calls } = mockApi({
      'PUT /api/v1/admin/settings': () => (++attempts === 1 ? new Response(null, { status: 419 }) : Response.json({ ok: true })),
    })
    document.cookie = 'XSRF-TOKEN=stale; path=/'

    await expect(api.put('/admin/settings', {})).resolves.toEqual({ ok: true })
    expect(calls.filter((c) => c.path === '/sanctum/csrf-cookie')).toHaveLength(1)
  })

  it('turns problem documents into ApiError with the business code and field errors', async () => {
    mockApi({
      'POST /api/v1/admin/users': () =>
        problem(422, 'VALIDATION_FAILED', 'Le champ identifiant est obligatoire.', {
          errors: { username: ['Le champ identifiant est obligatoire.'] },
        }),
    })

    const error = await api.post('/admin/users', {}).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 422, code: 'VALIDATION_FAILED', message: 'Le champ identifiant est obligatoire.' })
    expect((error as ApiError).fieldErrors).toEqual({ username: ['Le champ identifiant est obligatoire.'] })
  })

  it('builds filter query strings and skips empty values', async () => {
    const { calls } = mockApi({ 'GET /api/v1/admin/users': ({ url }) => Response.json({ query: url.search }) })

    const result = await api.get<{ query: string }>('/admin/users', { 'filter[role]': 'client', 'filter[search]': '', page: 2 })

    expect(decodeURIComponent(result.query)).toBe('?filter[role]=client&page=2')
    expect(calls).toHaveLength(1)
  })

  it('reports a network failure distinctly', async () => {
    mockApi({ 'GET /api/v1/auth/me': () => Promise.reject(new TypeError('Failed to fetch')) })

    await expect(api.get('/auth/me')).rejects.toBeInstanceOf(NetworkError)
  })
})
