import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import type { DeviceSession } from '../../shared/api/types'
import { LOGIN_PATH } from '../../shared/auth/guards'
import { useLogoutAll } from '../../shared/auth/session'
import { formatDateTime } from '../../shared/format'
import { NoOfflineData } from '../../shared/offline/OfflineDataNotice'
import { useClientOutbox, waitingCount } from '../../shared/offline/useOutbox'
import { useOnline } from '../../shared/pwa/online'
import { Badge, Button, Card, ErrorAlert, Icon, Spinner } from '../../shared/ui'
import { ConfirmDialog } from '../../shared/ui/ConfirmDialog'
import { useDeviceSessions, useRevokeSession } from './api'

/**
 * Profil → Appareils connectés (lot 8, contrat §5 et §8) : sessions du
 * compte avec « Cet appareil », la dernière activité et les notifications
 * actives ; « Déconnecter » un autre appareil (avec confirmation) ; « Me
 * déconnecter de tous mes appareils » (EF-AUTH-07, lot 1), qui annonce les
 * commandes en attente d'envoi effacées de l'appareil (§6.3, point 7).
 */
export function DevicesCard() {
  const { t } = useTranslation()
  const online = useOnline()
  const sessions = useDeviceSessions()
  const revoke = useRevokeSession()
  const logoutAll = useLogoutAll()
  const navigate = useNavigate()
  const waiting = waitingCount(useClientOutbox())
  const [target, setTarget] = useState<DeviceSession | null>(null)
  const [confirmAll, setConfirmAll] = useState(false)
  const [revoked, setRevoked] = useState<string | null>(null)

  const ask = (session: DeviceSession) => {
    revoke.reset()
    setRevoked(null)
    setTarget(session)
  }

  const confirmRevoke = () => {
    if (!target) return
    revoke.mutate(target, {
      onSuccess: (session) => {
        setTarget(null)
        setRevoked(session.device)
      },
    })
  }

  const confirmLogoutAll = () => logoutAll.mutate(undefined, { onSuccess: () => navigate(LOGIN_PATH, { replace: true }) })

  return (
    <Card title={t('push.sessions.title')}>
      <div className="flex flex-col gap-4">
        <p className="text-sm text-slate-600">{t('push.sessions.intro')}</p>

        {/* Liste jamais enregistrée sur l'appareil : hors ligne, pas d'attente sans fin. */}
        {sessions.isPending && (sessions.fetchStatus === 'paused' ? <NoOfflineData /> : <Spinner label={t('push.sessions.loading')} />)}
        {sessions.error && (
          <div className="flex flex-col gap-3">
            <ErrorAlert error={sessions.error} />
            <Button variant="secondary" className="self-start" onClick={() => void sessions.refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        )}
        {sessions.data &&
          (sessions.data.length === 0 ? (
            <p className="text-sm text-slate-600">{t('push.sessions.empty')}</p>
          ) : (
            <ul aria-label={t('push.sessions.list')} className="divide-y divide-slate-200 rounded-lg border border-slate-200">
              {sessions.data.map((session) => (
                <DeviceItem key={session.id} session={session} disabled={!online || revoke.isPending} onRevoke={() => ask(session)} />
              ))}
            </ul>
          ))}

        {revoked && (
          <p role="status" className="text-sm text-success">
            {t('push.sessions.revoked', { device: revoked })}
          </p>
        )}

        <div className="flex flex-col gap-3 border-t border-slate-200 pt-4">
          <p className="text-sm text-slate-600">{t('push.sessions.logoutAllHint')}</p>
          <ErrorAlert error={logoutAll.error} />
          <Button variant="danger" className="self-start py-2" onClick={() => setConfirmAll(true)} disabled={logoutAll.isPending || !online}>
            <span className="whitespace-normal">{t('auth.logoutAll')}</span>
          </Button>
          {!online && <p className="text-sm text-slate-600">{t('push.offline')}</p>}
        </div>
      </div>

      <ConfirmDialog
        open={target !== null}
        title={t('push.sessions.revokeTitle')}
        confirmLabel={t('push.sessions.revoke')}
        tone="danger"
        pending={revoke.isPending}
        error={revoke.error}
        onConfirm={confirmRevoke}
        onCancel={() => setTarget(null)}
      >
        {target && <p>{t('push.sessions.revokeConfirm', { device: target.device, date: formatDateTime(target.last_active_at) })}</p>}
      </ConfirmDialog>

      <ConfirmDialog
        open={confirmAll}
        title={t('auth.logoutAll')}
        confirmLabel={t('push.sessions.logoutAllAction')}
        tone="danger"
        pending={logoutAll.isPending}
        error={logoutAll.error}
        onConfirm={confirmLogoutAll}
        onCancel={() => setConfirmAll(false)}
      >
        <p>{t('auth.logoutAllConfirm')}</p>
        {waiting > 0 && <p>{t('offline.logout.pending', { count: waiting })}</p>}
      </ConfirmDialog>
    </Card>
  )
}

/** Un appareil : libellé, « Cet appareil », « Reste connecté », dernière activité, adresse IP, notifications actives. */
function DeviceItem({ session, disabled, onRevoke }: { session: DeviceSession; disabled: boolean; onRevoke: () => void }) {
  const { t } = useTranslation()
  return (
    <li className="flex flex-wrap items-center justify-between gap-3 px-4 py-3" data-current={session.current || undefined}>
      <div className="flex min-w-0 flex-col gap-0.5">
        <p className="flex flex-wrap items-center gap-2">
          <span className="font-semibold break-words text-slate-900">{session.device}</span>
          {session.current && <Badge tone="brand">{t('push.sessions.current')}</Badge>}
          {session.remembered && <Badge tone="neutral">{t('push.sessions.remembered')}</Badge>}
        </p>
        <p className="text-sm text-slate-700">{t('push.sessions.lastActive', { date: formatDateTime(session.last_active_at) })}</p>
        {session.ip && (
          <p className="text-xs text-slate-600">{t('push.sessions.ip', { ip: session.ip })}</p>
        )}
        {session.push && (
          <p className="flex items-center gap-1.5 text-sm text-success">
            <Icon name="bell" className="size-4" />
            {t('push.sessions.push')}
          </p>
        )}
      </div>
      {!session.current && (
        <Button variant="secondary" onClick={onRevoke} disabled={disabled} aria-label={t('push.sessions.revokeLabel', { device: session.device })}>
          {t('push.sessions.revoke')}
        </Button>
      )}
    </li>
  )
}
