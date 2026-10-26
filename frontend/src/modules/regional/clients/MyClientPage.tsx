import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { ApiError } from '../../../shared/api/client'
import type { Client } from '../../../shared/api/types'
import { businessToday, formatDate } from '../../../shared/format'
import { Alert, Button, Card, ErrorAlert, Spinner } from '../../../shared/ui'
import { useFieldClient } from './api'
import { ClientAccess } from './ClientAccess'
import { CallLink, ClientStatusBadge, InfoList, InfoRow } from './components'
import { addressLines, mapHref, phonesOf } from './contact'

const LINK_CLASSES =
  'inline-flex min-h-11 items-center gap-2 rounded-lg font-medium text-brand-700 hover:underline ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'

/**
 * Fiche d'un client du périmètre (EF-CLI-01, EF-CLI-03) : coordonnées avec
 * appel en un geste, carte, statut et changement de région programmé
 * (RG-S04). Un client hors périmètre répond 404 (RG-S02).
 */
export default function MyClientPage() {
  const { t } = useTranslation()
  const id = Number(useParams().id)
  const validId = Number.isInteger(id) && id > 0
  const client = useFieldClient(id, validId)

  const back = (
    <Link to=".." relative="path" className={LINK_CLASSES}>
      <span aria-hidden="true">←</span>
      {t('field.client.back')}
    </Link>
  )

  if (!validId || (client.error instanceof ApiError && client.error.status === 404)) {
    return (
      <div className="flex flex-col gap-3">
        {back}
        <Alert tone="warning">{t('field.client.notFound')}</Alert>
      </div>
    )
  }

  if (client.error) {
    return (
      <div className="flex flex-col gap-3">
        {back}
        <ErrorAlert error={client.error} />
        <Button variant="secondary" className="self-start" onClick={() => void client.refetch()}>
          {t('common.retry')}
        </Button>
      </div>
    )
  }

  if (!client.data) return <Spinner />

  return (
    <div className="flex flex-col gap-4">
      {back}
      <ClientFiche client={client.data} />
    </div>
  )
}

function ClientFiche({ client }: { client: Client }) {
  const { t } = useTranslation()
  const phones = phonesOf(client)
  const address = addressLines(client)
  const map = mapHref(client.lat, client.lng)
  const fiscal = (['nif', 'nis', 'rc', 'ai'] as const).filter((key) => client[key])

  return (
    <>
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-bold text-slate-900">{client.name}</h1>
        {client.trade_name && <p className="text-base text-slate-700">{client.trade_name}</p>}
        <p className="flex flex-wrap items-center gap-2 text-sm text-slate-600">
          <span>{client.code}</span>
          <ClientStatusBadge status={client.status} />
        </p>
      </header>

      {client.status === 'BLOCKED' && (
        <Alert tone="error">
          <p className="font-semibold">{t('field.client.blocked')}</p>
          {client.status_reason && <p>{t('field.client.reason', { reason: client.status_reason })}</p>}
          {client.status_changed_at && <p>{t('field.client.since', { date: formatDate(businessToday(new Date(client.status_changed_at))) })}</p>}
        </Alert>
      )}
      {client.status === 'INACTIVE' && (
        <Alert tone="warning">
          <p className="font-semibold">{t('field.client.inactive')}</p>
          {client.status_reason && <p>{t('field.client.reason', { reason: client.status_reason })}</p>}
        </Alert>
      )}
      {client.pending_region_change && (
        <Alert>
          {t('field.client.pendingRegion', {
            region: client.pending_region_change.region.name,
            date: formatDate(client.pending_region_change.effective_on),
          })}
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={t('field.client.sections.contact')}>
          {phones.length === 0 && !client.email && <p className="text-sm text-slate-600">{t('field.client.noContact')}</p>}
          <ul className="flex flex-col gap-3">
            {phones.map((phone, index) => (
              <li key={`${index}-${phone}`} className="flex items-center justify-between gap-3">
                <span className="flex flex-col text-sm">
                  <span className="text-slate-500">{t(index === 0 ? 'field.fields.phone' : 'field.fields.phone2')}</span>
                  <span dir="ltr" className="text-base font-medium text-slate-900">
                    {phone}
                  </span>
                </span>
                <CallLink phone={phone} name={client.name} />
              </li>
            ))}
            {client.email && (
              <li className="flex flex-col text-sm">
                <span className="text-slate-500">{t('field.fields.email')}</span>
                <a href={`mailto:${client.email}`} dir="ltr" className={`${LINK_CLASSES} break-all`}>
                  {client.email}
                </a>
              </li>
            )}
          </ul>
        </Card>

        <Card title={t('field.client.sections.address')}>
          {address.length === 0 ? (
            <p className="text-sm text-slate-600">{t('field.client.noAddress')}</p>
          ) : (
            <address className="text-sm not-italic text-slate-900">
              {address.map((line, index) => (
                <span key={index} className="block">
                  {line}
                </span>
              ))}
            </address>
          )}
          {map && (
            <a href={map} target="_blank" rel="noopener noreferrer" className={`${LINK_CLASSES} mt-2`}>
              {t('field.client.map')}
            </a>
          )}
        </Card>

        <Card title={t('field.client.sections.identity')}>
          <InfoList>
            <InfoRow label={t('field.fields.code')}>{client.code}</InfoRow>
            <InfoRow label={t('field.fields.name')}>{client.name}</InfoRow>
            {client.trade_name && <InfoRow label={t('field.fields.tradeName')}>{client.trade_name}</InfoRow>}
            <InfoRow label={t('field.fields.category')}>{client.category?.name ?? t('common.none')}</InfoRow>
            <InfoRow label={t('field.fields.region')}>{client.region.name}</InfoRow>
          </InfoList>
        </Card>

        {/* Présents seulement pour qui peut réinitialiser un mot de passe client (EF-AUTH-05). */}
        {client.accounts && <ClientAccess accounts={client.accounts} />}

        {fiscal.length > 0 && (
          <Card title={t('field.client.sections.tax')}>
            <InfoList>
              {fiscal.map((key) => (
                <InfoRow key={key} label={t(`field.fields.${key}`)}>
                  {client[key]}
                </InfoRow>
              ))}
            </InfoList>
          </Card>
        )}
      </div>
    </>
  )
}
