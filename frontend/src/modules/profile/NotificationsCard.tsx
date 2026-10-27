import { useQueryClient } from '@tanstack/react-query'
import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import type { CurrentUser } from '../../shared/api/types'
import { useOnline } from '../../shared/pwa/online'
import { PushError, type PushStatus, usePushErrorMessage, usePushState } from '../../shared/push'
import { Alert, Badge, Button, Card, Checkbox, ErrorAlert, Spinner } from '../../shared/ui'
import { sessionsKey, useUpdatePreferences } from './api'

/**
 * Profil → Notifications (lot 8, contrat §8) : état des notifications push
 * sur cet appareil avec l'action qui convient, et interrupteur des rappels
 * (N-10 à N-12) pour les clients et les régionaux.
 */
export function NotificationsCard({ user }: { user: CurrentUser }) {
  const { t } = useTranslation()
  const hasReminders = user.role === 'client' || user.role === 'regional'
  return (
    <Card title={t('push.device.title')}>
      <div className="flex flex-col gap-5">
        <DevicePush />
        {hasReminders && <Reminders user={user} />}
      </div>
    </Card>
  )
}

const STATUS_BADGES: Partial<Record<PushStatus, { tone: 'success' | 'neutral' | 'danger'; label: 'on' | 'off' | 'denied' }>> = {
  on: { tone: 'success', label: 'on' },
  off: { tone: 'neutral', label: 'off' },
  denied: { tone: 'danger', label: 'denied' },
}

/** Notifications push de cet appareil : un état, une explication, une action. */
function DevicePush() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const online = useOnline()
  const push = usePushState()
  const pushError = usePushErrorMessage()
  const badge = STATUS_BADGES[push.status]

  // La liste des appareils indique ceux qui reçoivent les notifications.
  const run = (action: () => Promise<void>) => void action().then(() => queryClient.invalidateQueries({ queryKey: sessionsKey }))

  if (push.status === 'checking') return <Spinner label={t('push.device.checking')} />

  return (
    <section className="flex flex-col gap-3" aria-label={t('push.device.subtitle')}>
      <p className="flex flex-wrap items-center gap-2 text-sm text-slate-600">
        {t('push.device.subtitle')}
        {badge && <Badge tone={badge.tone}>{t(`push.device.status.${badge.label}`)}</Badge>}
      </p>

      {push.status === 'unavailable' && <p className="text-sm text-slate-700">{t('push.device.unavailable')}</p>}
      {push.status === 'unsupported' && <p className="text-sm text-slate-700">{t('push.device.unsupported')}</p>}
      {push.status === 'ios-install' && <IosGuide />}
      {push.status === 'denied' && <DeniedHelp />}
      {push.status === 'off' && <p className="text-sm text-slate-700">{t('push.device.off')}</p>}
      {push.status === 'on' && <p className="text-sm text-slate-700">{t('push.device.on')}</p>}

      {push.error !== null && (
        <Alert tone={push.error instanceof PushError && push.error.code === 'dismissed' ? 'warning' : 'error'}>
          {pushError(push.error, push.status === 'on' ? 'disable' : 'enable')}
        </Alert>
      )}

      {push.status === 'off' && (
        <Button className="self-start whitespace-normal" onClick={() => run(push.enable)} disabled={push.busy || !online}>
          {push.busy ? t('push.device.enabling') : t('push.device.enable')}
        </Button>
      )}
      {push.status === 'on' && (
        <Button variant="secondary" className="self-start whitespace-normal" onClick={() => run(push.disable)} disabled={push.busy || !online}>
          {push.busy ? t('push.device.disabling') : t('push.device.disable')}
        </Button>
      )}
      {!online && (push.status === 'off' || push.status === 'on') && <p className="text-sm text-slate-600">{t('push.offline')}</p>}
    </section>
  )
}

function IosGuide() {
  const { t } = useTranslation()
  const steps = t('push.device.ios.steps', { returnObjects: true })
  return (
    <div className="flex flex-col gap-2 text-sm text-slate-700">
      <p>{t('push.device.ios.intro')}</p>
      <ol className="list-decimal space-y-1 ps-5">
        {steps.map((step) => (
          <li key={step}>{step}</li>
        ))}
      </ol>
    </div>
  )
}

function DeniedHelp() {
  const { t } = useTranslation()
  const steps = t('push.device.denied.steps', { returnObjects: true })
  return (
    <div className="flex flex-col gap-2 text-sm text-slate-700">
      <p>{t('push.device.denied.intro')}</p>
      <ul className="list-disc space-y-1 ps-5">
        {steps.map((step) => (
          <li key={step}>{step}</li>
        ))}
      </ul>
      <p className="text-slate-600">{t('push.device.denied.after')}</p>
    </div>
  )
}

/** Rappels avant les heures limites (N-10 à N-12) : dans l'application et en push (EF-NOT-04). */
function Reminders({ user }: { user: CurrentUser }) {
  const { t } = useTranslation()
  const online = useOnline()
  const update = useUpdatePreferences()
  const hintId = useId()
  const checked = update.isPending ? update.variables.reminders : user.preferences.reminders

  return (
    <section className="flex flex-col gap-1 border-t border-slate-200 pt-4" aria-label={t('push.reminders.title')}>
      <h3 className="text-sm font-semibold text-slate-900">{t('push.reminders.title')}</h3>
      <Checkbox
        label={t('push.reminders.label')}
        checked={checked}
        disabled={update.isPending || !online}
        aria-describedby={hintId}
        onChange={(event) => update.mutate({ reminders: event.target.checked })}
      />
      <div id={hintId} className="flex flex-col gap-1 text-sm text-slate-600">
        <p>{user.role === 'client' ? t('push.reminders.client') : t('push.reminders.regional')}</p>
        <p>{t('push.reminders.note')}</p>
        {!online && <p>{t('push.offline')}</p>}
      </div>
      <ErrorAlert error={update.error} className="mt-2" />
      {update.isSuccess && (
        <p role="status" className="mt-1 text-sm text-success">
          {t('push.reminders.saved')}
        </p>
      )}
    </section>
  )
}
