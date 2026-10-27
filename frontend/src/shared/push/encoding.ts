import type { PushSubscriptionInput } from '../api/types'

/**
 * Clés et abonnements Web Push (lot 8, contrat §2.2) : conversions pures,
 * sans DOM ni React.
 */

/** Clé publique VAPID (base64url, sans remplissage) → octets pour `pushManager.subscribe`. */
export function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4)
  const binary = atob(padded)
  const bytes = new Uint8Array(new ArrayBuffer(binary.length))
  for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index)
  return bytes
}

/** Même clé publique : un abonnement fait avec une ancienne clé VAPID est à refaire. */
export function sameKey(current: ArrayBuffer | null | undefined, expected: Uint8Array): boolean {
  if (!current) return false
  const bytes = new Uint8Array(current)
  return bytes.length === expected.length && bytes.every((byte, index) => byte === expected[index])
}

/**
 * Codage du contenu : `aes128gcm` (RFC 8291, défaut), sauf un navigateur qui
 * ne connaît que l'ancien `aesgcm`.
 */
export function contentEncoding(supported: readonly string[] | undefined): PushSubscriptionInput['content_encoding'] {
  return supported && supported.length > 0 && !supported.includes('aes128gcm') && supported.includes('aesgcm') ? 'aesgcm' : 'aes128gcm'
}

/** Forme de `PushSubscription.toJSON()`. */
export type SubscriptionJson = { endpoint?: string; keys?: Record<string, string> }

/** Corps de `POST /push-subscriptions` ; null si l'abonnement est incomplet. */
export function subscriptionInput(json: SubscriptionJson, supported: readonly string[] | undefined): PushSubscriptionInput | null {
  const p256dh = json.keys?.p256dh
  const auth = json.keys?.auth
  if (!json.endpoint || !p256dh || !auth) return null
  return { endpoint: json.endpoint, keys: { p256dh, auth }, content_encoding: contentEncoding(supported) }
}
