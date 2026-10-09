import { useTranslation } from 'react-i18next'
import type { Assignment } from '../../../shared/api/types'
import { formatDate } from '../../../shared/format'
import { Badge } from '../../../shared/ui'
import { ActiveBadge } from '../shared/ui'
import type { AssignmentState } from './assignments'

export function RegionStatusBadge({ active }: { active: boolean }) {
  const { t } = useTranslation()
  return <ActiveBadge active={active} labels={{ active: t('backoffice.regions.status.active'), inactive: t('backoffice.regions.status.inactive') }} />
}

/** Période « Du JJ/MM/AAAA au JJ/MM/AAAA » ou « À partir du JJ/MM/AAAA ». */
export function AssignmentPeriod({ assignment }: { assignment: Assignment }) {
  const { t } = useTranslation()
  const from = formatDate(assignment.starts_on)
  return (
    <>
      {assignment.ends_on
        ? t('backoffice.regions.assignments.period.between', { from, to: formatDate(assignment.ends_on) })
        : t('backoffice.regions.assignments.period.from', { from })}
    </>
  )
}

const STATE_TONES = { active: 'success', upcoming: 'brand', ended: 'neutral', revoked: 'neutral' } as const

export function AssignmentStateBadge({ state }: { state: AssignmentState }) {
  const { t } = useTranslation()
  return <Badge tone={STATE_TONES[state]}>{t(`backoffice.regions.assignments.state.${state}`)}</Badge>
}
