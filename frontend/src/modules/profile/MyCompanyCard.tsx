import { useTranslation } from 'react-i18next'
import type { Client } from '../../shared/api/types'
import { formatDate } from '../../shared/format'
import { Alert, Card, ErrorAlert, Spinner } from '../../shared/ui'
import { useFieldClient } from '../regional/clients/api'
import { ClientStatusBadge, InfoList, InfoRow } from '../regional/clients/components'
import { addressLines, phonesOf } from '../regional/clients/contact'

/**
 * Carte « Mon entreprise » du profil d'un compte client (EF-CLT-06) : sa
 * fiche en lecture (GET /clients/{client_id}). Un client bloqué voit que la
 * prise de commande est suspendue (§3.5).
 */
export function MyCompanyCard({ clientId }: { clientId: number }) {
  const { t } = useTranslation()
  const client = useFieldClient(clientId)

  return (
    <Card title={t('field.company.title')}>
      <ErrorAlert error={client.error} />
      {client.isPending && <Spinner />}
      {client.data && <CompanyDetails client={client.data} />}
    </Card>
  )
}

function CompanyDetails({ client }: { client: Client }) {
  const { t } = useTranslation()
  const address = addressLines(client)
  const phones = phonesOf(client)
  const pending = client.pending_region_change

  return (
    <div className="flex flex-col gap-4">
      {client.status === 'BLOCKED' && <Alert tone="error">{t('field.company.blocked')}</Alert>}
      {client.status === 'INACTIVE' && <Alert tone="warning">{t('field.company.inactive')}</Alert>}

      <InfoList>
        <InfoRow label={t('field.fields.name')}>
          <span className="font-medium">{client.name}</span>
        </InfoRow>
        {client.trade_name && <InfoRow label={t('field.fields.tradeName')}>{client.trade_name}</InfoRow>}
        <InfoRow label={t('field.fields.code')}>{client.code}</InfoRow>
        <InfoRow label={t('field.fields.region')}>
          {client.region.name}
          {pending && (
            <span className="block text-slate-600">
              {t('field.pendingRegion', { region: pending.region.name, date: formatDate(pending.effective_on) })}
            </span>
          )}
        </InfoRow>
        <InfoRow label={t('field.fields.address')}>
          {address.length === 0
            ? t('common.none')
            : address.map((line, index) => (
                <span key={index} className="block">
                  {line}
                </span>
              ))}
        </InfoRow>
        <InfoRow label={t('field.fields.phone')}>
          {phones.length === 0
            ? t('common.none')
            : phones.map((phone, index) => (
                <span key={index} dir="ltr" className="block">
                  {phone}
                </span>
              ))}
        </InfoRow>
        {client.email && (
          <InfoRow label={t('field.fields.email')}>
            <span dir="ltr" className="break-all">
              {client.email}
            </span>
          </InfoRow>
        )}
        <InfoRow label={t('field.fields.status')}>
          <ClientStatusBadge status={client.status} />
        </InfoRow>
      </InfoList>

      <p className="text-xs text-slate-600">{t('field.company.help')}</p>
    </div>
  )
}
