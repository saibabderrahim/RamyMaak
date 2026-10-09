import { useTranslation } from 'react-i18next'
import type { Assignment, AssignmentKind, Region } from '../../../shared/api/types'
import { formatDateTime } from '../../../shared/format'
import { Button } from '../../../shared/ui'
import { DataTable, EmptyState, TableHead, Th } from '../shared/ui'
import { assignmentState, isCurrentOrUpcoming } from './assignments'
import { AssignmentPeriod, AssignmentStateBadge } from './components'

type CurrentProps = {
  region: Region
  /** Historique complet ; à défaut (chargement), la fiche de la région suffit. */
  history: readonly Assignment[] | undefined
  today: string
  onEnd?: (assignment: Assignment) => void
}

const GROUPS: ReadonlyArray<{ kind: AssignmentKind; title: 'titular' | 'deputies' | 'adv'; empty: 'noTitular' | 'noDeputy' | 'noAdv' }> = [
  { kind: 'TITULAR', title: 'titular', empty: 'noTitular' },
  { kind: 'DEPUTY', title: 'deputies', empty: 'noDeputy' },
  { kind: 'ADV', title: 'adv', empty: 'noAdv' },
]

/**
 * Affectations en cours et à venir, par nature : titulaire (H-02),
 * suppléants avec leur période (§3.4), ADV (RG-S05). « Mettre fin » révoque
 * l'affectation sur-le-champ (RG-S03).
 */
export function CurrentAssignments({ region, history, today, onEnd }: CurrentProps) {
  const { t } = useTranslation()
  const current = history
    ? history.filter((assignment) => isCurrentOrUpcoming(assignment, today))
    : [...(region.titular ? [region.titular] : []), ...region.deputies]

  return (
    <div className="flex flex-col gap-5">
      {GROUPS.map((group) => {
        const items = current
          .filter((assignment) => assignment.kind === group.kind)
          .sort((a, b) => a.starts_on.localeCompare(b.starts_on) || a.id - b.id)
        return (
          <section key={group.kind} aria-labelledby={`assignments-${group.kind}`}>
            <h3 id={`assignments-${group.kind}`} className="mb-2 text-sm font-semibold text-slate-700">
              {t(`backoffice.regions.detail.${group.title}`)}
            </h3>
            {items.length === 0 ? (
              <p className="text-sm text-slate-500">{t(`backoffice.regions.detail.${group.empty}`)}</p>
            ) : (
              <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                {items.map((assignment) => (
                  <li key={assignment.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2">
                    <div className="min-w-0">
                      <p className="font-medium text-slate-900">
                        {assignment.user.name} <span className="font-normal text-slate-500">({assignment.user.username})</span>
                      </p>
                      <p className="text-sm text-slate-600">
                        <AssignmentPeriod assignment={assignment} />
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <AssignmentStateBadge state={assignmentState(assignment, today)} />
                      {onEnd && (
                        <Button
                          variant="secondary"
                          onClick={() => onEnd(assignment)}
                          aria-label={t('backoffice.regions.assignments.endLabel', { name: assignment.user.name, kind: assignment.kind_label })}
                        >
                          {t('backoffice.regions.assignments.end')}
                        </Button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        )
      })}
    </div>
  )
}

/** Historique complet des affectations, plus récentes d'abord (RG-S03). */
export function AssignmentHistory({ history, today }: { history: readonly Assignment[]; today: string }) {
  const { t } = useTranslation()

  if (history.length === 0) return <EmptyState>{t('backoffice.regions.detail.historyEmpty')}</EmptyState>

  return (
    <DataTable label={t('backoffice.regions.detail.history')}>
      <TableHead>
        <Th>{t('backoffice.regions.assignments.columns.kind')}</Th>
        <Th>{t('backoffice.regions.assignments.columns.user')}</Th>
        <Th>{t('backoffice.regions.assignments.columns.period')}</Th>
        <Th>{t('backoffice.regions.assignments.columns.state')}</Th>
        <Th>{t('backoffice.regions.assignments.columns.createdAt')}</Th>
      </TableHead>
      <tbody className="divide-y divide-slate-100">
        {history.map((assignment) => (
          <tr key={assignment.id}>
            <td className="py-2 pe-4">{t(`backoffice.regions.assignments.kinds.${assignment.kind}`)}</td>
            <td className="py-2 pe-4">
              {assignment.user.name} <span className="text-slate-500">({assignment.user.username})</span>
            </td>
            <td className="py-2 pe-4">
              <AssignmentPeriod assignment={assignment} />
            </td>
            <td className="py-2 pe-4">
              <AssignmentStateBadge state={assignmentState(assignment, today)} />
              {assignment.revoked_at && (
                <span className="mt-0.5 block text-xs text-slate-600">
                  {t('backoffice.regions.assignments.revokedInfo', {
                    date: formatDateTime(assignment.revoked_at),
                    name: assignment.revoked_by?.name ?? '—',
                  })}
                </span>
              )}
            </td>
            <td className="py-2 pe-4 text-slate-600">{formatDateTime(assignment.created_at)}</td>
          </tr>
        ))}
      </tbody>
    </DataTable>
  )
}
