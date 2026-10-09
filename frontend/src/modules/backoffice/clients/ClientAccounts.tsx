import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { Client, ClientAccount } from '../../../shared/api/types'
import { formatDateTime } from '../../../shared/format'
import { Alert, Button, ErrorAlert } from '../../../shared/ui'
import { ActiveBadge } from '../shared/ui'
import { useCreateClientAccounts } from './api'

/**
 * Comptes rattachés au client (EF-CLI-01), visibles avec user.manage : lien
 * vers chaque compte ; « Créer le compte » pour un client sans compte, qui
 * télécharge sa fiche d'accès PDF (EF-ADM-02).
 */
export function ClientAccounts({ client, accounts }: { client: Client; accounts: ClientAccount[] }) {
  const { t } = useTranslation()
  const createAccounts = useCreateClientAccounts()

  return (
    <div className="flex flex-col gap-3">
      {createAccounts.isSuccess && <Alert tone="success">{t('backoffice.clients.accounts.created')}</Alert>}
      <ErrorAlert error={createAccounts.error} />

      {accounts.length > 0 ? (
        <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
          {accounts.map((account) => (
            <li key={account.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
              <div>
                <Link to={`/admin/comptes/${account.id}`} className="font-medium text-brand-700 hover:underline">
                  {account.username}
                </Link>
                <p className="text-xs text-slate-600">
                  {t('backoffice.clients.accounts.lastLogin', { date: formatDateTime(account.last_login_at) })}
                </p>
              </div>
              <ActiveBadge
                active={account.is_active}
                labels={{ active: t('backoffice.clients.accounts.active'), inactive: t('backoffice.clients.accounts.inactive') }}
              />
            </li>
          ))}
        </ul>
      ) : (
        <>
          <p className="text-sm text-slate-600">{t('backoffice.clients.accounts.empty')}</p>
          {client.status === 'INACTIVE' ? (
            <p className="text-sm text-slate-600">{t('backoffice.clients.accounts.inactiveClient')}</p>
          ) : (
            <>
              <p className="text-xs text-slate-500">{t('backoffice.clients.accounts.createHint')}</p>
              <Button className="self-start" onClick={() => createAccounts.mutate([client.id])} disabled={createAccounts.isPending}>
                {createAccounts.isPending ? t('backoffice.clients.accounts.creating') : t('backoffice.clients.accounts.create')}
              </Button>
            </>
          )}
        </>
      )}
    </div>
  )
}
