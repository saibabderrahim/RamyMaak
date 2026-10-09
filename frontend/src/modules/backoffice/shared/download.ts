import { API_BASE, ApiError, NetworkError } from '../../../shared/api/client'

/**
 * Téléchargement de fichiers produits par l'API (PDF, classeurs…) :
 * shared/api/client.ts ne lit que du JSON. Même session Sanctum : cookie
 * envoyé, en-tête X-XSRF-TOKEN repris du cookie XSRF-TOKEN ; une erreur
 * reste un « Problem Details » levé en ApiError.
 */

export type DownloadedFile = { blob: Blob; filename: string | null; headers: Headers }

type FileRequest = { method?: 'GET' | 'POST'; body?: unknown }

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'include', headers: { Accept: 'application/json' } })
}

async function send(path: string, { method = 'GET', body }: FileRequest): Promise<Response> {
  const headers: Record<string, string> = { Accept: '*/*' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf

  try {
    return await fetch(`${API_BASE}${path}`, {
      method,
      credentials: 'include',
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new NetworkError()
  }
}

/** Nom de fichier annoncé par `Content-Disposition` (forme RFC 5987 comprise). */
export function filenameFromDisposition(disposition: string | null): string | null {
  if (!disposition) return null
  const encoded = /filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)/.exec(disposition)
  if (encoded?.[1]) {
    try {
      return decodeURIComponent(encoded[1].trim())
    } catch {
      // Valeur mal encodée : on se rabat sur la forme simple.
    }
  }
  const plain = /filename\s*=\s*"?([^";]+)"?/.exec(disposition)
  return plain?.[1]?.trim() || null
}

export async function fetchFile(path: string, options: FileRequest = {}): Promise<DownloadedFile> {
  const method = options.method ?? 'GET'
  if (method !== 'GET') await ensureCsrfCookie()

  let response = await send(path, options)

  // Jeton anti-CSRF expiré (419) : renouvelé, la requête est rejouée une fois.
  if (response.status === 419 && method !== 'GET') {
    await ensureCsrfCookie(true)
    response = await send(path, options)
  }

  if (!response.ok) {
    const problem: unknown = await response.json().catch(() => null)
    throw ApiError.fromResponse(response.status, problem)
  }

  return {
    blob: await response.blob(),
    filename: filenameFromDisposition(response.headers.get('Content-Disposition')),
    headers: response.headers,
  }
}

/** POST JSON dont la réponse est un fichier (ex. fiches d'accès PDF, EF-ADM-02). */
export function postForFile(path: string, body: unknown): Promise<DownloadedFile> {
  return fetchFile(path, { method: 'POST', body })
}

/** Propose le fichier à l'enregistrement (lien temporaire vers le blob). */
export function saveFile(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.rel = 'noopener'
  link.style.display = 'none'
  document.body.append(link)
  link.click()
  link.remove()
  // Révocation différée : certains navigateurs lisent l'adresse après le clic.
  window.setTimeout(() => URL.revokeObjectURL(url), 1_000)
}
