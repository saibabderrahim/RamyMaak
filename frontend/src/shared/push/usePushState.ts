import { useCallback, useEffect, useState, useSyncExternalStore } from 'react'
import { useAuthenticatedUser } from '../auth/session'
import { useInstallPrompt } from '../pwa/install'
import { disablePush, enablePush, immediateStatus, ownSubscription, PushError, type PushStatus } from './device'

/**
 * Révision de l'état des notifications de l'appareil : relue après une
 * activation, une désactivation ou un renvoi à l'ouverture, et au retour sur
 * l'application (la permission a pu changer dans les réglages du navigateur).
 */
let revision = 0
const listeners = new Set<() => void>()

/** Signale un changement de l'abonnement de l'appareil : chaque `usePushState` le relit. */
export function notifyPushChanged(): void {
  revision += 1
  listeners.forEach((listener) => listener())
}

function onVisibilityChange(): void {
  if (document.visibilityState === 'visible') notifyPushChanged()
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  if (listeners.size === 1) document.addEventListener('visibilitychange', onVisibilityChange)
  return () => {
    listeners.delete(listener)
    if (listeners.size === 0) document.removeEventListener('visibilitychange', onVisibilityChange)
  }
}

const getRevision = () => revision

export type PushState = {
  status: PushStatus
  /** Activation ou désactivation en cours. */
  busy: boolean
  /** Dernier échec : `PushError` (navigateur) ou erreur d'API. */
  error: unknown
  enable: () => Promise<void>
  disable: () => Promise<void>
}

/**
 * Notifications push de cet appareil pour le compte connecté (contrat §8) :
 * état et actions. À n'utiliser que sous un garde `RequireAuth`.
 */
export function usePushState(): PushState {
  const user = useAuthenticatedUser()
  const { installed } = useInstallPrompt()
  const current = useSyncExternalStore(subscribe, getRevision)
  const immediate = immediateStatus(user, installed)
  const publicKey = user.push?.public_key ?? null

  // Abonnement du navigateur lu pour ce compte : null avant la première lecture.
  const [subscribed, setSubscribed] = useState<boolean | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<unknown>(null)

  useEffect(() => {
    if (immediate !== null) return
    let cancelled = false
    const done = (on: boolean) => {
      if (!cancelled) setSubscribed(on)
    }
    ownSubscription(user.id).then(
      (subscription) => done(subscription !== null),
      () => done(false),
    )
    return () => {
      cancelled = true
    }
  }, [immediate, user.id, current])

  // Pendant une relecture, l'état précédent reste affiché (pas de clignotement).
  const status: PushStatus = immediate ?? (subscribed === null ? 'checking' : subscribed ? 'on' : 'off')

  const enable = useCallback(async () => {
    if (publicKey === null) return
    setBusy(true)
    setError(null)
    try {
      // La permission est demandée tout de suite, pendant le geste de l'utilisateur (Safari l'exige).
      const permission = await enablePush(user.id, publicKey)
      if (permission === 'default') setError(new PushError('dismissed'))
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(false)
      notifyPushChanged()
    }
  }, [publicKey, user.id])

  const disable = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      await disablePush()
    } catch (reason) {
      setError(reason)
    } finally {
      setBusy(false)
      notifyPushChanged()
    }
  }, [])

  return { status, busy, error, enable, disable }
}
