import { api } from '../api/client'
import type { CurrentUser } from '../api/types'
import { isIos } from '../pwa/install'
import { readJson, writeJson } from '../storage'
import { base64UrlToBytes, sameKey, subscriptionInput } from './encoding'

/**
 * Abonnement Web Push de cet appareil (lot 8, contrat §2.2 et §8) : prise en
 * charge par le navigateur, permission, abonnement, désabonnement et renvoi
 * de l'abonnement à l'ouverture de l'application.
 *
 * Appareil partagé (I-103) : l'appareil retient le compte qui a activé les
 * notifications. Un autre compte qui s'y connecte ne reprend jamais cet
 * abonnement en silence : il est retiré du navigateur, et ce compte active
 * les notifications lui-même s'il le souhaite.
 */

export type PushStatus =
  /** Lecture de l'abonnement du navigateur en cours. */
  | 'checking'
  /** Push désactivé sur le serveur (clés VAPID absentes ou `notifications.push_enabled` faux). */
  | 'unavailable'
  /** iPhone ou iPad, application non installée (EF-NOT-05). */
  | 'ios-install'
  | 'unsupported'
  | 'denied'
  | 'off'
  | 'on'

export type PushErrorCode = 'notReady' | 'subscribe' | 'dismissed'

/** Échec propre au navigateur (les refus du serveur restent des `ApiError`). */
export class PushError extends Error {
  readonly code: PushErrorCode

  constructor(code: PushErrorCode) {
    super(code)
    this.name = 'PushError'
    this.code = code
  }
}

/** Compte qui a activé les notifications sur cet appareil (identifiant). */
export const PUSH_OWNER_KEY = 'ramymaak:push-owner'

function owner(): number | null {
  const value = readJson(PUSH_OWNER_KEY)
  return typeof value === 'number' ? value : null
}

function setOwner(userId: number | null): void {
  writeJson(PUSH_OWNER_KEY, userId)
}

/** Service worker, Push API et notifications présents. */
export function browserSupportsPush(): boolean {
  return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

/** Permission des notifications ; null si le navigateur ne les connaît pas. */
export function notificationPermission(): NotificationPermission | null {
  return typeof window !== 'undefined' && 'Notification' in window ? window.Notification.permission : null
}

/**
 * État qui se lit sans attendre ; null : il faut lire l'abonnement du
 * navigateur (« activées » ou « désactivées »). `standalone` : application
 * installée et ouverte depuis son icône.
 */
export function immediateStatus(user: CurrentUser, standalone: boolean): PushStatus | null {
  if (user.push === null) return 'unavailable'
  // Sur iPhone et iPad, le push n'existe que dans l'application installée (iOS 16.4 ou plus récent).
  if (isIos() && !standalone) return 'ios-install'
  if (!browserSupportsPush()) return 'unsupported'
  if (notificationPermission() === 'denied') return 'denied'
  return null
}

async function currentRegistration(): Promise<ServiceWorkerRegistration | null> {
  try {
    return (await navigator.serviceWorker.getRegistration()) ?? null
  } catch {
    return null
  }
}

/** Délai d'attente du service worker actif : au-delà, il n'est pas installé (développement, erreur). */
export const READY_TIMEOUT_MS = 10_000

function readyRegistration(): Promise<ServiceWorkerRegistration> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new PushError('notReady')), READY_TIMEOUT_MS)
    navigator.serviceWorker.ready.then(
      (registration) => {
        clearTimeout(timer)
        resolve(registration)
      },
      () => {
        clearTimeout(timer)
        reject(new PushError('notReady'))
      },
    )
  })
}

async function dropSubscription(subscription: PushSubscription): Promise<void> {
  try {
    await subscription.unsubscribe()
  } catch {
    // Déjà retiré : rien à faire.
  }
}

function supportedEncodings(): readonly string[] | undefined {
  return (window.PushManager as unknown as { supportedContentEncodings?: readonly string[] }).supportedContentEncodings
}

async function postSubscription(subscription: PushSubscription): Promise<void> {
  const input = subscriptionInput(subscription.toJSON(), supportedEncodings())
  if (input === null) throw new PushError('subscribe')
  await api.post('/push-subscriptions', input)
}

async function subscribe(registration: ServiceWorkerRegistration, key: Uint8Array<ArrayBuffer>): Promise<PushSubscription> {
  try {
    return await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })
  } catch {
    throw new PushError('subscribe')
  }
}

/**
 * Abonnement du navigateur s'il appartient à ce compte ; celui d'un autre
 * compte (appareil partagé) est retiré du navigateur.
 */
export async function ownSubscription(userId: number): Promise<PushSubscription | null> {
  const registration = await currentRegistration()
  const subscription = (await registration?.pushManager.getSubscription()) ?? null
  if (subscription === null) return null
  if (owner() !== userId) {
    await dropSubscription(subscription)
    if (owner() !== null) setOwner(null)
    return null
  }
  return subscription
}

/**
 * « Activer les notifications sur cet appareil » : permission (demandée
 * d'abord, pendant le geste de l'utilisateur), abonnement avec la clé VAPID,
 * puis `POST /push-subscriptions`. Un refus du serveur retire l'abonnement
 * du navigateur : l'appareil reste « désactivé ».
 */
export async function enablePush(userId: number, publicKey: string): Promise<NotificationPermission> {
  const permission = await window.Notification.requestPermission()
  if (permission !== 'granted') return permission

  const registration = await readyRegistration()
  const key = base64UrlToBytes(publicKey)
  let subscription = await registration.pushManager.getSubscription()
  if (subscription !== null && (owner() !== userId || !sameKey(subscription.options.applicationServerKey, key))) {
    await dropSubscription(subscription)
    subscription = null
  }
  subscription ??= await subscribe(registration, key)

  try {
    await postSubscription(subscription)
  } catch (error) {
    await dropSubscription(subscription)
    setOwner(null)
    throw error
  }
  setOwner(userId)
  return permission
}

/** « Désactiver sur cet appareil » : `DELETE /push-subscriptions`, puis désabonnement du navigateur. */
export async function disablePush(): Promise<void> {
  const registration = await currentRegistration()
  const subscription = (await registration?.pushManager.getSubscription()) ?? null
  if (subscription !== null) {
    await api.delete('/push-subscriptions', { endpoint: subscription.endpoint })
    await dropSubscription(subscription)
  }
  setOwner(null)
}

/**
 * Renvoi de l'abonnement à l'ouverture de l'application (§2.2) : met à jour
 * la session et `last_used_at` côté serveur, et le recrée s'il a été
 * supprimé (déconnexion, purge). Abonnement perdu (renouvelé par le service
 * push) ou fait avec une ancienne clé : réabonnement sans question, la
 * permission étant accordée. Rien pour un compte qui n'a pas activé les
 * notifications sur cet appareil. Renvoie vrai si l'état de l'appareil a changé.
 */
export async function syncPush(userId: number, publicKey: string): Promise<boolean> {
  if (!browserSupportsPush() || notificationPermission() !== 'granted') return false
  const registration = await currentRegistration()
  if (registration === null) return false

  let subscription = await registration.pushManager.getSubscription()
  if (owner() !== userId) {
    if (subscription === null) return false
    // Abonnement d'un autre compte (appareil partagé) : jamais repris en silence.
    await dropSubscription(subscription)
    setOwner(null)
    return true
  }

  let changed = false
  const key = base64UrlToBytes(publicKey)
  if (subscription !== null && !sameKey(subscription.options.applicationServerKey, key)) {
    await dropSubscription(subscription)
    subscription = null
    changed = true
  }
  if (subscription === null) {
    subscription = await subscribe(registration, key)
    changed = true
  }
  await postSubscription(subscription)
  return changed
}
