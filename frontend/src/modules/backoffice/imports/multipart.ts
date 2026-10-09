import { API_BASE, ApiError, NetworkError } from '../../../shared/api/client'

/**
 * Envoi de fichiers (photos produits, classeurs Excel) en multipart :
 * shared/api/client.ts n'envoie que du JSON. Même session Sanctum : cookie
 * envoyé, en-tête X-XSRF-TOKEN repris du cookie XSRF-TOKEN (obtenu d'abord
 * s'il manque) ; une erreur reste un « Problem Details » levé en ApiError.
 * Le Content-Type n'est pas fixé : le navigateur y ajoute la frontière.
 */

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'include', headers: { Accept: 'application/json' } })
}

async function send(path: string, form: FormData): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf

  try {
    return await fetch(`${API_BASE}${path}`, { method: 'POST', credentials: 'include', headers, body: form })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new NetworkError()
  }
}

/** POST multipart ; réponse JSON (ou rien pour 204). */
export async function postMultipart<T>(path: string, form: FormData): Promise<T> {
  await ensureCsrfCookie()
  let response = await send(path, form)

  // Jeton anti-CSRF expiré (419) : renouvelé, l'envoi est rejoué une fois.
  if (response.status === 419) {
    await ensureCsrfCookie(true)
    response = await send(path, form)
  }

  if (response.status === 204) return undefined as T

  const body: unknown = await response.json().catch(() => null)
  if (!response.ok) throw ApiError.fromResponse(response.status, body)

  return body as T
}

/** Formulaire multipart à partir de champs simples (les booléens en 1 / 0). */
export function formData(fields: Record<string, string | boolean | Blob | undefined>): FormData {
  const form = new FormData()
  for (const [name, value] of Object.entries(fields)) {
    if (value === undefined) continue
    if (typeof value === 'boolean') form.append(name, value ? '1' : '0')
    else form.append(name, value)
  }
  return form
}
