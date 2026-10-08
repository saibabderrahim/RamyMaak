/**
 * Accès à l'API REST `/api/v1` (§11) : cookie de session Sanctum, jeton
 * anti-CSRF, erreurs au format « Problem Details » (RFC 9457).
 */

export const API_BASE = '/api/v1'

export type ProblemDocument = {
  type?: string
  title?: string
  status: number
  code: string
  detail?: string
  errors?: Record<string, string[]>
  [extra: string]: unknown
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly problem: ProblemDocument

  constructor(problem: ProblemDocument) {
    super(problem.detail ?? problem.title ?? problem.code)
    this.name = 'ApiError'
    this.status = problem.status
    this.code = problem.code
    this.problem = problem
  }

  /** Erreurs de validation par champ (`VALIDATION_FAILED`). */
  get fieldErrors(): Record<string, string[]> {
    return this.problem.errors ?? {}
  }

  static fromResponse(status: number, body: unknown): ApiError {
    if (body !== null && typeof body === 'object' && 'code' in body) {
      return new ApiError({ ...(body as ProblemDocument), status })
    }
    return new ApiError({ status, code: status >= 500 ? 'SERVER_ERROR' : 'HTTP_ERROR' })
  }
}

/** Erreur réseau : pas de réponse du serveur (hors ligne, coupure). */
export class NetworkError extends Error {
  constructor() {
    super('network')
    this.name = 'NetworkError'
  }
}

type Query = Record<string, string | number | boolean | null | undefined>

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  query?: Query
  signal?: AbortSignal
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'include', headers: { Accept: 'application/json' } })
}

export function buildUrl(path: string, query?: Query): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  const qs = params.toString()
  return `${API_BASE}${path}${qs ? `?${qs}` : ''}`
}

async function send(path: string, options: RequestOptions): Promise<Response> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf

  try {
    return await fetch(buildUrl(path, options.query), {
      method,
      credentials: 'include',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: options.signal,
    })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new NetworkError()
  }
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET'
  if (method !== 'GET') await ensureCsrfCookie()

  let response = await send(path, options)

  // Jeton anti-CSRF expiré (419) : on le renouvelle et on rejoue une fois.
  if (response.status === 419 && method !== 'GET') {
    await ensureCsrfCookie(true)
    response = await send(path, options)
  }

  if (response.status === 204) return undefined as T

  const body: unknown = await response.json().catch(() => null)
  if (!response.ok) throw ApiError.fromResponse(response.status, body)

  return body as T
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) => request<T>(path, { query, signal }),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
}
