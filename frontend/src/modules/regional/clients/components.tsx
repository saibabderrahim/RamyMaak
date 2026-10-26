import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type { Client, ClientStatus } from '../../../shared/api/types'
import { formatDate } from '../../../shared/format'
import { Badge } from '../../../shared/ui'
import { telHref } from './contact'

const STATUS_TONES: Record<ClientStatus, 'success' | 'danger' | 'neutral'> = {
  ACTIVE: 'success',
  BLOCKED: 'danger',
  INACTIVE: 'neutral',
}

/** Statut du client (§3.5) : un client bloqué ne peut pas commander. */
export function ClientStatusBadge({ status }: { status: ClientStatus }) {
  const { t } = useTranslation()
  return <Badge tone={STATUS_TONES[status]}>{t(`field.status.${status}`)}</Badge>
}

/** Changement de région programmé, effectif à la journée suivante (RG-S04). */
export function PendingRegionBadge({ change }: { change: NonNullable<Client['pending_region_change']> }) {
  const { t } = useTranslation()
  return <Badge tone="warning">{t('field.pendingRegion', { region: change.region.name, date: formatDate(change.effective_on) })}</Badge>
}

function PhoneIcon() {
  return (
    <svg viewBox="0 0 24 24" className="size-5 shrink-0" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"
      />
    </svg>
  )
}

/**
 * Appel en un geste depuis le téléphone (EF-CLI-03) : lien `tel:`, zone
 * tactile de 48 px (au moins 44 px, §7.3). Sans numéro, une mention neutre.
 */
export function CallLink({ phone, name }: { phone: string | null; name: string }) {
  const { t } = useTranslation()
  const href = telHref(phone)

  if (!href || !phone) return <span className="shrink-0 text-xs text-slate-500">{t('field.noPhone')}</span>

  return (
    <a
      href={href}
      aria-label={t('field.callLabel', { name, phone })}
      className="inline-flex min-h-12 min-w-12 shrink-0 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-900 hover:border-field hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
    >
      <PhoneIcon />
      {t('field.call')}
    </a>
  )
}

/** Liste « libellé : valeur » des fiches. */
export function InfoList({ children }: { children: ReactNode }) {
  return <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">{children}</dl>
}

export function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-slate-500">{label}</dt>
      <dd className="min-w-0 break-words text-slate-900">{children}</dd>
    </>
  )
}
