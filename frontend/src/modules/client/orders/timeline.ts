import type { Order, OrderStatus } from '../../../shared/api/types'

// Frise de statut d'une commande (EF-CLT-04, §6.2) : Envoyée → Validée → En
// préparation → Préparée → Expédiée (→ Livrée, V2), avec les fins Rejetée,
// Annulée et Expirée.

/** Parcours normal d'une commande. */
export const MAIN_STEPS = ['SUBMITTED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED'] as const satisfies readonly OrderStatus[]

/** Fins sans livraison : la frise s'arrête à l'étape d'où la commande en est sortie. */
export const TERMINAL_STATUSES = ['REJECTED', 'CANCELLED', 'EXPIRED'] as const satisfies readonly OrderStatus[]

export type StepState = 'done' | 'current' | 'upcoming'

export type TimelineStep = {
  status: OrderStatus
  state: StepState
  /** Fin sans livraison (rejet, annulation, expiration). */
  terminal: boolean
  /** Instant où la commande a atteint l'étape (historique) ; null : pas encore atteinte. */
  at: string | null
}

const isTerminal = (status: OrderStatus): boolean => (TERMINAL_STATUSES as readonly OrderStatus[]).includes(status)

/**
 * Étapes de la frise. La date d'une étape est celle de la dernière entrée de
 * l'historique qui y mène (l'envoi : `submitted_at`, gardé à la
 * modification). Pour une fin sans livraison, l'étape d'origine est lue dans
 * l'historique (annulation exceptionnelle par l'ADV après la validation, par
 * exemple) ; à défaut, Envoyée.
 */
export function timelineSteps(order: Pick<Order, 'status' | 'submitted_at' | 'history'>): TimelineStep[] {
  const reachedAt = (status: OrderStatus): string | null => {
    if (status === 'SUBMITTED') return order.submitted_at
    const entries = order.history.filter((entry) => entry.to === status && entry.from !== status)
    return entries[entries.length - 1]?.at ?? null
  }

  if (isTerminal(order.status)) {
    const exits = order.history.filter((entry) => entry.to === order.status && entry.from !== null)
    const from = exits[exits.length - 1]?.from ?? 'SUBMITTED'
    const index = Math.max(0, (MAIN_STEPS as readonly OrderStatus[]).indexOf(from))
    return [
      ...MAIN_STEPS.slice(0, index + 1).map((status): TimelineStep => ({ status, state: 'done', terminal: false, at: reachedAt(status) })),
      { status: order.status, state: 'current', terminal: true, at: reachedAt(order.status) },
    ]
  }

  const steps: readonly OrderStatus[] = order.status === 'DELIVERED' ? [...MAIN_STEPS, 'DELIVERED'] : MAIN_STEPS
  const current = Math.max(0, steps.indexOf(order.status))
  return steps.map((status, index) => {
    const state: StepState = index < current ? 'done' : index === current ? 'current' : 'upcoming'
    return { status, state, terminal: false, at: state === 'upcoming' ? null : reachedAt(status) }
  })
}
