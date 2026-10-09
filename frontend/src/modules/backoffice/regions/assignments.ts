import type { Assignment } from '../../../shared/api/types'

/** État d'une affectation au jour métier donné (§3.3, §3.4). */
export type AssignmentState = 'active' | 'upcoming' | 'ended' | 'revoked'

export function assignmentState(assignment: Assignment, today: string): AssignmentState {
  if (assignment.revoked_at) return 'revoked'
  if (assignment.is_active) return 'active'
  if (assignment.starts_on > today) return 'upcoming'
  return 'ended'
}

/** Affectation non révoquée, en cours ou à venir : on peut encore y mettre fin. */
export function isCurrentOrUpcoming(assignment: Assignment, today: string): boolean {
  return assignment.revoked_at === null && (assignment.ends_on === null || assignment.ends_on >= today)
}

/**
 * Titulaires non révoqués dont la période chevauche celle demandée (bornes
 * incluses, fin facultative) : un seul titulaire à la fois (H-02), leur
 * remplacement exige une confirmation explicite.
 */
export function overlappingTitulars(assignments: readonly Assignment[], startsOn: string, endsOn: string | null): Assignment[] {
  return assignments.filter(
    (assignment) =>
      assignment.kind === 'TITULAR' &&
      assignment.revoked_at === null &&
      (assignment.ends_on === null || assignment.ends_on >= startsOn) &&
      (endsOn === null || assignment.starts_on <= endsOn),
  )
}
