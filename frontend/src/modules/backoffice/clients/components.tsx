import { useTranslation } from 'react-i18next'
import type { Client, ClientStatus } from '../../../shared/api/types'
import { formatDate } from '../../../shared/format'
import { Badge } from '../../../shared/ui'

const STATUS_TONES = { ACTIVE: 'success', BLOCKED: 'danger', INACTIVE: 'neutral' } as const

/** Statut du client (§3.5) : actif, bloqué (ne commande plus), inactif (archivé). */
export function ClientStatusBadge({ status }: { status: ClientStatus }) {
  const { t } = useTranslation()
  return <Badge tone={STATUS_TONES[status]}>{t(`backoffice.clients.status.${status}`)}</Badge>
}

/** Changement de région programmé pour une journée suivante (RG-S04). */
export function PendingRegionMarker({ change }: { change: NonNullable<Client['pending_region_change']> }) {
  const { t } = useTranslation()
  return (
    <Badge tone="warning">
      <span aria-hidden="true">→&nbsp;</span>
      {t('backoffice.clients.pendingRegion', { region: change.region.code, date: formatDate(change.effective_on) })}
    </Badge>
  )
}
