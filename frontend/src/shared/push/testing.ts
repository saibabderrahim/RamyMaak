import { vi } from 'vitest'

/**
 * Navigateur simulé pour les tests du push (lot 8) : permission des
 * notifications, service worker, Push API et abonnement de l'appareil.
 */

/** Clé VAPID de test (65 octets, base64url). */
export const PUBLIC_KEY = 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U'
export const OTHER_KEY = 'BAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4vMDEyMzQ1Njc4OTo7PD0-P0A'

export const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/appareil-1'

function keyBytes(key: string): ArrayBuffer {
  const base64 = key.replace(/-/g, '+').replace(/_/g, '/')
  const binary = atob(base64 + '='.repeat((4 - (base64.length % 4)) % 4))
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index)
  return bytes.buffer
}

export type FakeSubscription = {
  endpoint: string
  options: { applicationServerKey: ArrayBuffer | null; userVisibleOnly: boolean }
  toJSON: () => { endpoint: string; expirationTime: null; keys: { p256dh: string; auth: string } }
  unsubscribe: ReturnType<typeof vi.fn>
}

type Options = {
  permission?: NotificationPermission
  /** Réponse de l'utilisateur à la demande de permission. */
  answer?: NotificationPermission
  /** Abonnement existant du navigateur, avec sa clé publique. */
  subscribedWith?: string | null
  encodings?: string[]
  /** Pas de service worker actif (`ready` ne se résout jamais). */
  noWorker?: boolean
  /** `pushManager.subscribe` refusé par le navigateur. */
  subscribeFails?: boolean
}

export function installPush({
  permission = 'default',
  answer = 'granted',
  subscribedWith = null,
  encodings = ['aes128gcm', 'aesgcm'],
  noWorker = false,
  subscribeFails = false,
}: Options = {}) {
  let count = 0
  const makeSubscription = (key: ArrayBuffer | null): FakeSubscription => {
    count += 1
    const subscription: FakeSubscription = {
      endpoint: count === 1 ? ENDPOINT : `${ENDPOINT}-${count}`,
      options: { applicationServerKey: key, userVisibleOnly: true },
      toJSON: () => ({ endpoint: subscription.endpoint, expirationTime: null, keys: { p256dh: 'BN-p256dh', auth: 'k-auth' } }),
      unsubscribe: vi.fn(async () => {
        if (state.subscription === subscription) state.subscription = null
        return true
      }),
    }
    return subscription
  }

  const state = {
    permission,
    subscription: subscribedWith === null ? null : makeSubscription(keyBytes(subscribedWith)),
  }

  const pushManager = {
    getSubscription: vi.fn(async () => state.subscription),
    subscribe: vi.fn(async (options: { userVisibleOnly: boolean; applicationServerKey: Uint8Array }) => {
      if (subscribeFails) throw new DOMException('Échec', 'AbortError')
      const key = options.applicationServerKey
      state.subscription = makeSubscription(key.buffer.slice(key.byteOffset, key.byteOffset + key.byteLength) as ArrayBuffer)
      return state.subscription
    }),
  }
  const registration = { pushManager }

  const requestPermission = vi.fn(async () => {
    if (state.permission === 'default') state.permission = answer
    return state.permission
  })
  const NotificationMock = Object.assign(function Notification() {}, { requestPermission })
  Object.defineProperty(NotificationMock, 'permission', { get: () => state.permission })

  vi.stubGlobal('Notification', NotificationMock)
  vi.stubGlobal('PushManager', Object.assign(function PushManager() {}, { supportedContentEncodings: encodings }))
  // Conteneur de service worker : aussi cible d'événements (`message`), comme dans le navigateur.
  const container = Object.assign(new EventTarget(), {
    ready: noWorker ? new Promise(() => {}) : Promise.resolve(registration),
    getRegistration: vi.fn(async () => (noWorker ? undefined : registration)),
  })
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: container })

  return { state, pushManager, requestPermission }
}

/** Retire le navigateur simulé (les globales sont rendues par `vi.unstubAllGlobals`). */
export function uninstallPush(): void {
  Reflect.deleteProperty(navigator, 'serviceWorker')
}

/** iPhone (Safari), application non installée. */
export function asIphone(): () => void {
  const original = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(navigator), 'userAgent')
  Object.defineProperty(navigator, 'userAgent', {
    configurable: true,
    get: () => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
  })
  return () => {
    Reflect.deleteProperty(navigator, 'userAgent')
    if (original && !Object.getOwnPropertyDescriptor(Object.getPrototypeOf(navigator), 'userAgent')) {
      Object.defineProperty(Object.getPrototypeOf(navigator), 'userAgent', original)
    }
  }
}
