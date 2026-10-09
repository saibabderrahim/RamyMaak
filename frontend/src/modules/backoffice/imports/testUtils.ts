import { vi } from 'vitest'
import { mockApi } from '../../../test/utils'

export type RecordedUpload = { method: string; path: string; form: FormData; headers: Headers }

/**
 * mockApi() complété pour les envois multipart (tests seulement) : chaque
 * corps FormData est conservé tel quel avant de passer au routeur simulé,
 * qui ne lit que les corps JSON.
 */
export function mockApiWithUploads(routes: Parameters<typeof mockApi>[0]) {
  const mocked = mockApi(routes)
  const uploads: RecordedUpload[] = []
  const inner = mocked.fetchMock

  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (init?.body instanceof FormData) {
        uploads.push({
          method: (init.method ?? 'GET').toUpperCase(),
          path: new URL(String(input), 'http://localhost').pathname,
          form: init.body,
          headers: new Headers(init.headers),
        })
      }
      return inner(input, init)
    }),
  )

  return { ...mocked, uploads }
}
