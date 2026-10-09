import { useAuthenticatedUser } from '../../../shared/auth/session'

/** Permissions qui conditionnent les actions des écrans back-office (§3.2). */
export type BackofficePermission = 'region.manage' | 'client.manage' | 'client.block' | 'user.manage' | 'product.manage'

/**
 * Contexte des écrans back-office partagés entre l'Admin et l'ADV : chemin
 * de base (`/admin` ou `/adv`) et droits de l'utilisateur. Les actions ne
 * s'affichent qu'avec la permission correspondante ; le serveur reste seul
 * juge (RG-S02).
 */
export function useBackoffice() {
  const user = useAuthenticatedUser()
  const base = user.role === 'adv' ? '/adv' : '/admin'
  const can = (permission: BackofficePermission): boolean => user.permissions.includes(permission)
  return { user, base, can }
}
