import { describe, expect, it, vi } from 'vitest'
import { ApiError, NetworkError } from '../../../shared/api/client'
import { json, mockApi, problem } from '../../../test/utils'
import { addDays } from './dates'
import { fetchFile, filenameFromDisposition, postForFile } from './download'

const pdf = () =>
  new Response('%PDF-1.7', {
    status: 200,
    headers: { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="fiches-acces-20261009.pdf"', 'X-Accounts-Created': '3' },
  })

describe('backoffice/shared/download', () => {
  it('reads the file name from Content-Disposition', () => {
    expect(filenameFromDisposition('attachment; filename="fiches-acces-20261009.pdf"')).toBe('fiches-acces-20261009.pdf')
    expect(filenameFromDisposition('attachment; filename=modele.xlsx')).toBe('modele.xlsx')
    expect(filenameFromDisposition("attachment; filename=\"a.pdf\"; filename*=UTF-8''fiches%20d%E2%80%99acc%C3%A8s.pdf")).toBe('fiches d’accès.pdf')
    expect(filenameFromDisposition(null)).toBeNull()
    expect(filenameFromDisposition('inline')).toBeNull()
  })

  it('posts JSON with the session cookie and the anti-CSRF header, then returns the blob', async () => {
    const { calls, fetchMock } = mockApi({ 'POST /api/v1/admin/client-accounts': () => pdf() })

    const file = await postForFile('/admin/client-accounts', { client_ids: [1, 2, 3] })

    // Cookie XSRF-TOKEN obtenu d'abord, puis repris dans l'en-tête.
    expect(calls.map((call) => call.path)).toEqual(['/sanctum/csrf-cookie', '/api/v1/admin/client-accounts'])
    expect(calls[1]?.body).toEqual({ client_ids: [1, 2, 3] })
    expect(calls[1]?.headers.get('X-XSRF-TOKEN')).toBe('token=1')
    expect(calls[1]?.headers.get('Content-Type')).toBe('application/json')
    expect(fetchMock.mock.calls[1]?.[1]?.credentials).toBe('include')
    expect(file.filename).toBe('fiches-acces-20261009.pdf')
    expect(file.headers.get('X-Accounts-Created')).toBe('3')
    expect(await file.blob.text()).toBe('%PDF-1.7')
  })

  it('renews an expired anti-CSRF token once (419)', async () => {
    let attempts = 0
    const { calls } = mockApi({
      'POST /api/v1/admin/client-accounts': () => (++attempts === 1 ? problem(419, 'UNAUTHENTICATED', 'Jeton expiré.') : pdf()),
    })
    document.cookie = 'XSRF-TOKEN=old; path=/'

    const file = await postForFile('/admin/client-accounts', { client_ids: [1] })

    expect(file.filename).toBe('fiches-acces-20261009.pdf')
    expect(calls.map((call) => call.path)).toEqual(['/api/v1/admin/client-accounts', '/sanctum/csrf-cookie', '/api/v1/admin/client-accounts'])
    expect(calls[0]?.headers.get('X-XSRF-TOKEN')).toBe('old')
    expect(calls[2]?.headers.get('X-XSRF-TOKEN')).toBe('token=1')
  })

  it('turns a Problem Details response into an ApiError', async () => {
    mockApi({
      'POST /api/v1/admin/client-accounts': () =>
        problem(422, 'VALIDATION_FAILED', 'Aucun compte créé.', { errors: { client_ids: ['Aucun compte créé.'] } }),
      'GET /api/v1/imports/9/error-report': () => json({ code: 'NOT_FOUND', detail: 'Ressource introuvable.' }, 404),
    })

    const error = await postForFile('/admin/client-accounts', { client_ids: [1] }).catch((caught: unknown) => caught)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).code).toBe('VALIDATION_FAILED')
    expect((error as ApiError).fieldErrors).toEqual({ client_ids: ['Aucun compte créé.'] })

    await expect(fetchFile('/imports/9/error-report')).rejects.toMatchObject({ status: 404, code: 'NOT_FOUND' })
  })

  it('sends the query of a GET download, skipping empty values (lot 6 documents and exports)', async () => {
    const { fetchMock } = mockApi({ 'GET /api/v1/adv/days/2026-10-08/picking-list': () => pdf() })

    await fetchFile('/adv/days/2026-10-08/picking-list', { query: { format: 'pdf', group: 'depot', region: undefined, 'filter[status]': 'VALIDATED,PREPARED' } })

    const url = new URL(String(fetchMock.mock.calls[0]?.[0]), 'http://localhost')
    expect(url.pathname).toBe('/api/v1/adv/days/2026-10-08/picking-list')
    expect(Object.fromEntries(url.searchParams)).toEqual({ format: 'pdf', group: 'depot', 'filter[status]': 'VALIDATED,PREPARED' })
  })

  it('reports a network failure', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))))
    await expect(fetchFile('/imports/templates/clients')).rejects.toBeInstanceOf(NetworkError)
  })

  it('shifts calendar days across months and years', () => {
    expect(addDays('2026-10-31', 1)).toBe('2026-11-01')
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
    expect(addDays('2026-03-01', -1)).toBe('2026-02-28')
  })
})
