import { readJson, writeJson } from '../storage'

/**
 * Invitation « Recevez vos notifications » de l'accueil (contrat §8) : une
 * fois par compte et par appareil. Le refus (« Plus tard ») est retenu dans
 * le navigateur ; stockage indisponible : l'invitation revient à l'ouverture suivante.
 */
export function inviteKey(userId: number): string {
  return `ramymaak:push-invite:${userId}`
}

export function isInviteDismissed(userId: number): boolean {
  return readJson(inviteKey(userId)) !== null
}

export function dismissInvite(userId: number): void {
  writeJson(inviteKey(userId), { dismissed_at: new Date().toISOString() })
}
