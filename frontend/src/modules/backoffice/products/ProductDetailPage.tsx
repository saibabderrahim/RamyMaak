import { useTranslation } from 'react-i18next'
import { Link, useLocation, useParams } from 'react-router'
import type { Product } from '../../../shared/api/types'
import { Alert, Card, ErrorAlert, PageHeader, Spinner } from '../../../shared/ui'
import { useBackoffice } from '../shared/access'
import { type DetailItem, DetailList } from '../shared/ui'
import { useProduct, useUpdateProduct } from './api'
import { ProductBadges } from './components'
import { formatRate } from './numbers'
import { PhotoManager } from './PhotoManager'
import { PricesPanel } from './PricesPanel'
import { ProductForm } from './ProductForm'

/**
 * Fiche produit back-office (§7.3) : modification (product.manage), photos
 * et prix ; en lecture seule sans ce droit (ADV par défaut).
 */
export default function ProductDetailPage() {
  const { id } = useParams()
  return <ProductDetail key={id} id={Number(id)} />
}

function ProductDetail({ id }: { id: number }) {
  const { t } = useTranslation()
  const { base, can } = useBackoffice()
  const location = useLocation()
  const product = useProduct(id)
  const update = useUpdateProduct(id)
  const canManage = can('product.manage')
  const created = (location.state as { created?: boolean } | null)?.created === true

  const back = (
    <Link to={`${base}/produits`} className="text-sm text-brand-700 hover:underline">
      {t('common.back')}
    </Link>
  )

  if (!Number.isInteger(id) || id <= 0) {
    return <Alert tone="warning">{t('backoffice.products.notFound')}</Alert>
  }
  if (product.isPending) return <Spinner />
  if (product.error) {
    return (
      <>
        <PageHeader title={t('backoffice.products.title')} actions={back} />
        <ErrorAlert error={product.error} />
      </>
    )
  }

  const item = product.data

  return (
    <>
      <PageHeader title={item.name} actions={back} />
      <div className="-mt-4 mb-6 flex flex-wrap items-center gap-3 text-sm text-slate-600">
        <span className="font-mono">{item.sku}</span>
        <ProductBadges product={item} />
      </div>

      {created && (
        <Alert tone="success" className="mb-6">
          {t('backoffice.products.created')}
        </Alert>
      )}

      <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
        <Card title={t('backoffice.products.edit')}>
          {canManage ? (
            <>
              {update.isSuccess && (
                <Alert tone="success" className="mb-4">
                  {t('backoffice.products.updated')}
                </Alert>
              )}
              <ProductForm
                key={item.updated_at}
                product={item}
                submitting={update.isPending}
                error={update.error}
                onSubmit={({ sku: _sku, ...values }) => update.mutateAsync(values)}
              />
            </>
          ) : (
            <ProductFacts product={item} />
          )}
        </Card>

        <PhotoManager product={item} canManage={canManage} />
      </div>

      <div className="mt-6">
        <PricesPanel product={item} canManage={canManage} />
      </div>
    </>
  )
}

/** Fiche en lecture seule (EF-PRD-01). */
function ProductFacts({ product }: { product: Product }) {
  const { t } = useTranslation()
  const items: DetailItem[] = [
    { label: t('backoffice.products.fields.sku'), value: <span className="font-mono">{product.sku}</span> },
    { label: t('backoffice.products.fields.name'), value: product.name },
    { label: t('backoffice.products.fields.shortName'), value: product.short_name },
    { label: t('backoffice.products.fields.family'), value: product.family ? `${product.family.name} (${product.family.code})` : null },
    { label: t('backoffice.products.fields.format'), value: product.format },
    { label: t('backoffice.products.fields.salesUnitLabel'), value: product.sales_unit_label },
    { label: t('backoffice.products.fields.unitsPerSalesUnit'), value: product.units_per_sales_unit },
    { label: t('backoffice.products.fields.salesUnitsPerPallet'), value: product.sales_units_per_pallet },
    { label: t('backoffice.products.fields.ean'), value: product.ean },
    { label: t('backoffice.products.fields.vatRate'), value: formatRate(product.vat_rate) },
    { label: t('backoffice.products.fields.sortOrder'), value: product.sort_order },
    { label: t('backoffice.products.fields.status'), value: <ProductBadges product={product} /> },
  ]

  return (
    <div className="flex flex-col gap-4">
      <Alert>{t('backoffice.products.readOnly')}</Alert>
      <DetailList items={items} />
    </div>
  )
}
