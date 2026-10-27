import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { ClientAccount } from '../../../shared/api/types'
import { formatDateTime } from '../../../shared/format'
import { useOnline } from '../../../shared/pwa/online'
import { Alert, Button, Card, ErrorAlert } from '../../../shared/ui'
import { useResetClientPassword } from './api'

/**
 * Comptes d'accès du client et réinitialisation de leur mot de passe par le
 * régional (EF-AUTH-05) : c'est lui que le client appelle en cas d'oubli
 * (fiche d'accès). Le mot de passe temporaire n'est affiché qu'une fois.
 */
export function ClientAccess({ accounts }: { accounts: ClientAccount[] }) {
  const { t } = useTranslation()
  const reset = useResetClientPassword()
  // Écriture en ligne seulement (D-13).
  const online = useOnline()
  const [confirming, setConfirming] = useState<ClientAccount | null>(null)
  const [copied, setCopied] = useState(false)

  const confirm = (account: ClientAccount) => {
    setCopied(false)
    reset.mutate(account.id, { onSettled: () => setConfirming(null) })
  }

  const copy = async (password: string) => {
    await navigator.clipboard?.writeText(password)
    setCopied(true)
  }

  return (
    <Card title={t('field.access.title')}>
      {accounts.length === 0 ? (
        <p className="text-sm text-slate-600">{t('field.access.none')}</p>
      ) : (
        <ul className="flex flex-col gap-4">
          {accounts.map((account) => (
            <li key={account.id} className="flex flex-col gap-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex flex-col text-sm">
                  <span dir="ltr" className="text-base font-medium text-slate-900">
                    {account.username}
                  </span>
                  <span className="text-slate-600">
                    {account.is_active ? t('field.access.active') : t('field.access.inactive')}
                    {' · '}
                    {account.last_login_at
                      ? t('field.access.lastLogin', { date: formatDateTime(account.last_login_at) })
                      : t('field.access.neverLoggedIn')}
                  </span>
                </span>
                {account.is_active && confirming?.id !== account.id && (
                  <Button
                    variant="secondary"
                    className="min-h-11"
                    aria-label={t('field.access.resetLabel', { username: account.username })}
                    disabled={reset.isPending || !online}
                    onClick={() => {
                      reset.reset()
                      setConfirming(account)
                    }}
                  >
                    {t('field.access.reset')}
                  </Button>
                )}
              </div>
              {confirming?.id === account.id && (
                <Alert tone="warning">
                  <p className="mb-3">{t('field.access.confirm', { username: account.username })}</p>
                  <div className="flex flex-wrap gap-2">
                    <Button className="min-h-11" disabled={reset.isPending || !online} onClick={() => confirm(account)}>
                      {t('field.access.confirmButton')}
                    </Button>
                    <Button variant="secondary" className="min-h-11" disabled={reset.isPending} onClick={() => setConfirming(null)}>
                      {t('common.cancel')}
                    </Button>
                  </div>
                </Alert>
              )}
              {reset.data && reset.variables === account.id && (
                <Alert tone="success">
                  <p className="mb-2 font-semibold">{t('field.access.temporaryTitle', { username: reset.data.user.username })}</p>
                  <div className="mb-2 flex flex-wrap items-center gap-3">
                    <code dir="ltr" className="rounded-md bg-white px-3 py-2 font-mono text-lg tracking-wider text-slate-900" data-testid="temporary-password">
                      {reset.data.temporary_password}
                    </code>
                    <Button variant="secondary" className="min-h-11" onClick={() => void copy(reset.data.temporary_password)}>
                      {copied ? t('common.copied') : t('common.copy')}
                    </Button>
                  </div>
                  <p className="text-sm">{t('field.access.temporaryHelp')}</p>
                </Alert>
              )}
              {reset.error && reset.variables === account.id && <ErrorAlert error={reset.error} />}
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
