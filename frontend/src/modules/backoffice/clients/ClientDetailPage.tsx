import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useParams } from 'react-router'
import type { Client } from '../../../shared/api/types'
import { formatDate, formatDateTime } from '../../../shared/format'
import { Alert, Button, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useRegionOptions } from '../regions/api'
import { useBackoffice } from '../shared/access'
import { DetailList } from '../shared/ui'
import { useCategories, useClient, useUpdateClient } from './api'
import { ClientAccounts } from './ClientAccounts'
import { ClientForm } from './ClientForm'
import { ClientStatusBadge } from './components'
import { targetRegionId } from './regionChange'
import { type StatusAction, statusActions } from './status'
import { StatusDialog } from './StatusDialog'

function ClientFiche({ client }: { client: Client }) {
  const { t } = useTranslation()
  const { base } = useBackoffice()
  const gps = client.lat !== null && client.lng !== null ? `${client.lat}, ${client.lng}` : null

  return (
    <DetailList
      items={[
        { label: t('backoffice.clients.fields.code'), value: client.code },
        { label: t('backoffice.clients.fields.name'), value: client.name },
        { label: t('backoffice.clients.fields.tradeName'), value: client.trade_name },
        { label: t('backoffice.clients.fields.category'), value: client.category?.name },
        {
          label: t('backoffice.clients.fields.region'),
          value: (
            <Link to={`${base}/regions/${client.region.id}`} className="text-brand-700 hover:underline">
              {`${client.region.code} — ${client.region.name}`}
            </Link>
          ),
        },
        { label: t('backoffice.clients.fields.address'), value: client.address },
        { label: t('backoffice.clients.fields.wilaya'), value: client.wilaya },
        { label: t('backoffice.clients.fields.commune'), value: client.commune },
        { label: t('backoffice.clients.detail.gps'), value: gps },
        {
          label: t('backoffice.clients.fields.phone'),
          value: client.phone && (
            <a href={`tel:${client.phone.replace(/\s+/g, '')}`} className="text-brand-700 hover:underline">
              {client.phone}
            </a>
          ),
        },
        {
          label: t('backoffice.clients.fields.phone2'),
          value: client.phone2 && (
            <a href={`tel:${client.phone2.replace(/\s+/g, '')}`} className="text-brand-700 hover:underline">
              {client.phone2}
            </a>
          ),
        },
        {
          label: t('backoffice.clients.fields.email'),
          value: client.email && (
            <a href={`mailto:${client.email}`} className="text-brand-700 hover:underline">
              {client.email}
            </a>
          ),
        },
        { label: t('backoffice.clients.fields.nif'), value: client.nif },
        { label: t('backoffice.clients.fields.nis'), value: client.nis },
        { label: t('backoffice.clients.fields.rc'), value: client.rc },
        { label: t('backoffice.clients.fields.ai'), value: client.ai },
        { label: t('backoffice.common.createdAt'), value: formatDateTime(client.created_at) },
        { label: t('backoffice.common.updatedAt'), value: formatDateTime(client.updated_at) },
      ]}
    />
  )
}

/**
 * Fiche client (EF-CLI-01) : tous les champs, modification (client.manage)
 * avec changement de région programmé à J+1 (RG-S04), statut avec motif
 * (client.block, EF-CLI-04), comptes rattachés (user.manage, EF-ADM-02).
 * L'ADV consulte sans action.
 */
export default function ClientDetailPage() {
  const { t } = useTranslation()
  const id = Number(useParams().id)
  const location = useLocation()
  const { base, can } = useBackoffice()
  const manage = can('client.manage')
  const block = can('client.block')

  const [editing, setEditing] = useState(false)
  const client = useClient(id)
  const updateClient = useUpdateClient(id)
  const cancelRegionChange = useUpdateClient(id)
  // Listes de choix chargées seulement pour le formulaire de modification.
  const regions = useRegionOptions(editing)
  const categories = useCategories(editing)

  const [statusAction, setStatusAction] = useState<StatusAction | null>(null)
  const [notice, setNotice] = useState<string | null>((location.state as { created?: boolean } | null)?.created ? t('backoffice.clients.created') : null)

  if (client.isPending) return <Spinner />
  if (client.error) return <ErrorAlert error={client.error} />

  const current = client.data
  const pending = current.pending_region_change

  return (
    <>
      <PageHeader
        title={`${current.code} — ${current.name}`}
        actions={
          <Link to={`${base}/clients`} className="text-sm text-brand-700 hover:underline">
            {t('backoffice.clients.detail.back')}
          </Link>
        }
      />

      <div className="mb-4 flex flex-col gap-3">
        {notice && <Alert tone="success">{notice}</Alert>}
        {pending && (
          <Alert tone="warning">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <span>
                {t('backoffice.clients.detail.pendingRegion', {
                  from: current.region.name,
                  to: pending.region.name,
                  date: formatDate(pending.effective_on),
                })}
              </span>
              {/* Pas pendant la modification : le formulaire ouvert renverrait l'ancienne région cible. */}
              {manage && !editing && (
                <Button
                  variant="secondary"
                  disabled={cancelRegionChange.isPending}
                  onClick={() =>
                    cancelRegionChange.mutate(
                      // La région actuelle annule le changement programmé.
                      { region_id: current.region.id },
                      { onSuccess: () => setNotice(t('backoffice.clients.detail.regionChangeCancelled')) },
                    )
                  }
                >
                  {t('backoffice.clients.detail.cancelRegionChange')}
                </Button>
              )}
            </div>
          </Alert>
        )}
        <ErrorAlert error={cancelRegionChange.error} />
      </div>

      <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <Card
          title={editing ? t('backoffice.clients.detail.edit') : t('backoffice.clients.detail.fiche')}
          actions={
            manage && !editing ? (
              <Button
                variant="secondary"
                onClick={() => {
                  updateClient.reset()
                  setNotice(null)
                  setEditing(true)
                }}
              >
                {t('backoffice.clients.detail.edit')}
              </Button>
            ) : undefined
          }
          className="min-w-0"
        >
          {editing && (regions.isPending || categories.isPending) ? (
            // Listes de choix attendues : une liste déroulante non contrôlée
            // perdrait sinon la catégorie ou la région actuelle.
            <Spinner />
          ) : editing ? (
            <ClientForm
              client={current}
              regions={regions.data}
              categories={categories.data}
              submitting={updateClient.isPending}
              error={updateClient.error}
              onCancel={() => setEditing(false)}
              onSubmit={async ({ code: _code, region_id, ...values }) => {
                // La région n'est envoyée que si elle a été modifiée (RG-S04).
                await updateClient.mutateAsync(region_id === targetRegionId(current) ? values : { ...values, region_id })
                setEditing(false)
                setNotice(t('backoffice.clients.detail.updated'))
              }}
            />
          ) : (
            <ClientFiche client={current} />
          )}
        </Card>

        <div className="flex min-w-0 flex-col gap-6">
          <Card title={t('backoffice.clients.statusCard.title')}>
            <div className="flex flex-col gap-3">
              <div>
                <ClientStatusBadge status={current.status} />
              </div>
              {current.status === 'BLOCKED' && <p className="text-sm text-slate-700">{t('backoffice.clients.statusCard.blockedAlert')}</p>}
              {current.status === 'INACTIVE' && <p className="text-sm text-slate-700">{t('backoffice.clients.statusCard.inactiveAlert')}</p>}
              <DetailList
                items={[
                  { label: t('backoffice.clients.statusCard.reason'), value: current.status_reason, wide: true },
                  { label: t('backoffice.clients.statusCard.since'), value: current.status_changed_at ? formatDateTime(current.status_changed_at) : null, wide: true },
                ]}
              />
              {block && manage && (
                <div className="flex flex-wrap gap-2">
                  {statusActions(current.status).map((action) => (
                    <Button
                      key={action}
                      variant={action === 'block' || action === 'deactivate' ? 'danger' : 'secondary'}
                      onClick={() => {
                        setNotice(null)
                        setStatusAction(action)
                      }}
                    >
                      {t(`backoffice.clients.statusCard.${action}`)}
                    </Button>
                  ))}
                </div>
              )}
            </div>
          </Card>

          {current.accounts !== undefined && (
            <Card title={t('backoffice.clients.accounts.title')}>
              <ClientAccounts client={current} accounts={current.accounts} />
            </Card>
          )}
        </div>
      </div>

      {statusAction && (
        <StatusDialog
          client={current}
          action={statusAction}
          onClose={() => setStatusAction(null)}
          onDone={() => {
            setStatusAction(null)
            setNotice(t('backoffice.clients.statusCard.changed'))
          }}
        />
      )}
    </>
  )
}
