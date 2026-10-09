import type { ClientStatus } from '../../../shared/api/types'

/** Actions sur le statut d'un client (§3.5, EF-CLI-04), réservées à client.block. */
export type StatusAction = 'block' | 'unblock' | 'deactivate' | 'reactivate' | 'editReason'

/** Statut visé par chaque action ; `editReason` ne modifie que le motif du statut actuel. */
export const STATUS_TARGETS: Record<StatusAction, ClientStatus | null> = {
  block: 'BLOCKED',
  unblock: 'ACTIVE',
  deactivate: 'INACTIVE',
  reactivate: 'ACTIVE',
  editReason: null,
}

export function statusActions(status: ClientStatus): StatusAction[] {
  switch (status) {
    case 'ACTIVE':
      return ['block', 'deactivate']
    case 'BLOCKED':
      return ['unblock', 'editReason', 'deactivate']
    case 'INACTIVE':
      return ['reactivate']
  }
}

/** Le blocage exige un motif (EF-CLI-04), y compris quand on modifie ce motif. */
export function requiresReason(action: StatusAction): boolean {
  return action === 'block' || action === 'editReason'
}
