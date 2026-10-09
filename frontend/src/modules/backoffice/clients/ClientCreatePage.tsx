import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { Alert, Card, PageHeader } from '../../../shared/ui'
import { useRegionOptions } from '../regions/api'
import { useBackoffice } from '../shared/access'
import { useCategories, useCreateClient } from './api'
import { ClientForm } from './ClientForm'

/** Création d'un client (EF-CLI-01), réservée à client.manage. */
export default function ClientCreatePage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { base, can } = useBackoffice()
  const createClient = useCreateClient()
  const regions = useRegionOptions()
  const categories = useCategories()

  return (
    <>
      <PageHeader
        title={t('backoffice.clients.create')}
        actions={
          <Link to={`${base}/clients`} className="text-sm text-brand-700 hover:underline">
            {t('backoffice.clients.detail.back')}
          </Link>
        }
      />
      <Card>
        {can('client.manage') ? (
          <ClientForm
            regions={regions.data}
            categories={categories.data}
            submitting={createClient.isPending}
            error={createClient.error}
            onSubmit={async (values) => {
              const client = await createClient.mutateAsync(values)
              navigate(`${base}/clients/${client.id}`, { state: { created: true } })
            }}
          />
        ) : (
          <Alert tone="warning">{t('backoffice.common.readOnly')}</Alert>
        )}
      </Card>
    </>
  )
}
