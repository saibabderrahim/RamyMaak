import { useEffect, useRef } from 'react'
import { useAuthenticatedUser } from '../auth/session'
import { useOnline } from '../pwa/online'
import { syncPush } from './device'
import { notifyPushChanged } from './usePushState'

/**
 * Renvoi de l'abonnement push de l'appareil à chaque ouverture de
 * l'application (contrat §2.2), une fois l'utilisateur chargé et le réseau
 * disponible. Rien quand le push est désactivé sur le serveur (`push` null).
 * Monté une fois dans la coquille, pour tous les rôles ; aucun rendu.
 */
export function PushSync() {
  const user = useAuthenticatedUser()
  const online = useOnline()
  const publicKey = user.push?.public_key ?? null
  // Compte et clé déjà renvoyés pendant cette ouverture.
  const synced = useRef<string | null>(null)

  useEffect(() => {
    if (publicKey === null || !online) return
    const key = `${user.id}:${publicKey}`
    if (synced.current === key) return
    synced.current = key
    syncPush(user.id, publicKey).then(
      (changed) => {
        if (changed) notifyPushChanged()
      },
      () => {
        // Réseau ou serveur indisponible : nouvel essai au retour du réseau.
        if (synced.current === key) synced.current = null
      },
    )
  }, [user.id, publicKey, online])

  return null
}
