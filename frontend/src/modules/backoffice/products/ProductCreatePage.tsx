import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { Alert, Card, PageHeader } from '../../../shared/ui'
import { useBackoffice } from '../shared/access'
import { useCreateProduct } from './api'
import { ProductForm } from './ProductForm'

/**
 * Création d'un produit (EF-PRD-01), réservée à product.manage. La fiche
 * s'ouvre ensuite pour y ajouter les photos et le prix.
 */
export default function ProductCreatePage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { base, can } = useBackoffice()
  const create = useCreateProduct()

  const back = (
    <Link to={`${base}/produits`} className="text-sm text-brand-700 hover:underline">
      {t('common.back')}
    </Link>
  )

  if (!can('product.manage')) {
    return (
      <>
        <PageHeader title={t('backoffice.products.create')} actions={back} />
        <Alert tone="warning">{t('errors.forbidden')}</Alert>
      </>
    )
  }

  return (
    <>
      <PageHeader title={t('backoffice.products.create')} actions={back} />
      <Card>
        <ProductForm
          submitting={create.isPending}
          error={create.error}
          onSubmit={async (values) => {
            const product = await create.mutateAsync(values)
            navigate(`${base}/produits/${product.id}`, { state: { created: true } })
          }}
        />
      </Card>
    </>
  )
}
