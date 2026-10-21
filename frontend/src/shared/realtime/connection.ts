import type Echo from 'laravel-echo'
import { csrfHeaders } from '../api/client'

/**
 * Connexion temps réel (§9.6) : Reverb, protocole Pusher, au même domaine
 * que l'application sur le chemin /app. Une seule connexion par session,
 * ouverte au premier abonnement et fermée à la déconnexion. Les
 * bibliothèques sont chargées à la demande.
 *
 * - `off` : temps réel désactivé côté serveur (`realtime: null`) ;
 * - `unavailable` : connexion impossible ou perdue (réseau filtrant, coupure).
 *
 * Hors de `connected`, les écrans se rafraîchissent toutes les 30 s.
 */
export type RealtimeStatus = 'off' | 'connecting' | 'connected' | 'unavailable'

export const FALLBACK_REFRESH_MS = 30_000

type Connection = { userId: number; echo: Promise<Echo<'reverb'> | null> }

let current: Connection | null = null
let status: RealtimeStatus = 'off'
const statusListeners = new Set<() => void>()

function setStatus(next: RealtimeStatus): void {
  if (status === next) return
  status = next
  statusListeners.forEach((listener) => listener())
}

export function getRealtimeStatus(): RealtimeStatus {
  return status
}

export function subscribeRealtimeStatus(listener: () => void): () => void {
  statusListeners.add(listener)
  return () => statusListeners.delete(listener)
}

type AuthCallback = (error: Error | null, data: { auth: string; channel_data?: string } | null) => void

/** Autorisation d'un canal privé par la session Sanctum (cookie et jeton anti-CSRF). */
async function authorize(socketId: string, channelName: string, callback: AuthCallback): Promise<void> {
  const send = async (renew: boolean) =>
    fetch('/broadcasting/auth', {
      method: 'POST',
      credentials: 'include',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(await csrfHeaders(renew)) },
      body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
    })
  try {
    let response = await send(false)
    if (response.status === 419) response = await send(true)
    if (!response.ok) {
      callback(new Error(`broadcasting/auth ${response.status}`), null)
      return
    }
    callback(null, (await response.json()) as { auth: string; channel_data?: string })
  } catch (error) {
    callback(error instanceof Error ? error : new Error('broadcasting/auth'), null)
  }
}

const PUSHER_STATES: Record<string, RealtimeStatus> = {
  initialized: 'connecting',
  connecting: 'connecting',
  connected: 'connected',
  unavailable: 'unavailable',
  failed: 'unavailable',
  disconnected: 'unavailable',
}

async function open(key: string): Promise<Echo<'reverb'> | null> {
  try {
    const [{ default: EchoClient }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')])
    const secure = window.location.protocol === 'https:'
    const port = Number(window.location.port) || (secure ? 443 : 80)
    const client = new Pusher(key, {
      cluster: '',
      wsHost: window.location.hostname,
      wsPort: port,
      wssPort: port,
      forceTLS: secure,
      enabledTransports: ['ws', 'wss'],
      channelAuthorization: {
        customHandler: ({ socketId, channelName }, callback) => void authorize(socketId, channelName, callback),
      },
    })
    client.connection.bind('state_change', ({ current: state }: { current: string }) => {
      setStatus(PUSHER_STATES[state] ?? 'unavailable')
    })
    return new EchoClient({ broadcaster: 'reverb', key, client })
  } catch {
    setStatus('unavailable')
    return null
  }
}

/**
 * Connexion de l'utilisateur, ouverte au besoin. `key` null : temps réel
 * désactivé. Un autre utilisateur (nouvelle connexion sur l'appareil)
 * ferme la connexion précédente.
 */
export function connectRealtime(userId: number, key: string | null): Promise<Echo<'reverb'> | null> {
  if (key === null) {
    disconnectRealtime()
    return Promise.resolve(null)
  }
  if (current?.userId !== userId) {
    disconnectRealtime()
    setStatus('connecting')
    current = { userId, echo: open(key) }
  }
  return current.echo
}

/** Ferme la connexion (déconnexion, changement d'utilisateur). */
export function disconnectRealtime(): void {
  const previous = current
  current = null
  setStatus('off')
  void previous?.echo.then((echo) => echo?.disconnect())
}
