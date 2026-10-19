import { useTranslation } from 'react-i18next'
import type { ClientProduct } from '../../../shared/api/types'
import { formatAmount } from '../../../shared/format'
import { Badge, Button } from '../../../shared/ui'
import { Dialog } from '../../../shared/ui/Dialog'
import { ProductImage } from '../../../shared/ui/ProductImage'
import { QuantityStepper } from '../../../shared/ui/QuantityStepper'
import { formatQuantity, formatRate } from '../cart/format'

type Props = {
  product: ClientProduct
  quantity: number
  onQuantity: (quantity: number) => void
  readOnly: boolean
  onClose: () => void
}

/**
 * Fiche produit (§7.3), ouverte par `?produit=ID` : le bouton Retour la
 * referme. Carrousel des photos par défilement natif (CSS scroll-snap,
 * zoom du navigateur), grande image en 600 / 1 200 px, UV et unités, prix
 * HT et TTC, prix unitaire indicatif (prix de l'UV ÷ unités par UV),
 * sélecteur de quantité lié au panier.
 */
export function ProductSheet({ product, quantity, onQuantity, readOnly, onClose }: Props) {
  const { t } = useTranslation()
  const count = product.photos.length

  const details = [
    { label: t('orders.client.sheet.sku'), value: product.sku },
    ...(product.format ? [{ label: t('orders.client.sheet.format'), value: product.format }] : []),
    { label: t('orders.client.sheet.salesUnit'), value: `${product.sales_unit_label} — ${t('orders.client.sheet.units', { count: product.units_per_sales_unit })}` },
    { label: t('orders.client.sheet.priceHt'), value: formatAmount(product.price.price_ht) },
    { label: t('orders.client.sheet.priceTtc'), value: formatAmount(product.price.price_ttc) },
    { label: t('orders.client.sheet.unitPrice'), value: t('orders.client.sheet.unitPriceValue', { amount: formatAmount(product.price.unit_price_ht) }) },
    { label: t('orders.client.sheet.vat'), value: t('orders.client.sheet.vatValue', { rate: formatRate(product.vat_rate) }) },
  ]

  return (
    <Dialog title={product.name} onClose={onClose} size="lg">
      <div className="flex flex-col gap-4">
        {count === 0 ? (
          <ProductImage photo={null} alt={product.name} size={600} className="mx-auto w-full max-w-sm" />
        ) : (
          <div>
            <div
              role="group"
              aria-label={t('orders.client.sheet.photos', { name: product.name })}
              tabIndex={0}
              className="flex snap-x snap-mandatory gap-2 overflow-x-auto overscroll-x-contain rounded-lg focus-visible:outline-2 focus-visible:outline-brand-600"
            >
              {product.photos.map((photo, index) => (
                <img
                  key={photo.id}
                  src={photo.urls.medium}
                  srcSet={`${photo.urls.medium} 600w, ${photo.urls.large} 1200w`}
                  sizes="(min-width: 640px) 640px, 100vw"
                  alt={t('orders.client.sheet.photo', { name: product.name, index: index + 1, count })}
                  loading={index === 0 ? 'eager' : 'lazy'}
                  decoding="async"
                  className="aspect-square w-full shrink-0 snap-center rounded-lg bg-slate-100 object-contain"
                />
              ))}
            </div>
            {count > 1 && <p className="mt-1 text-center text-xs text-slate-500">{t('orders.client.sheet.swipe')}</p>}
          </div>
        )}

        <div className="flex flex-wrap items-center gap-1">
          {product.sold_out && <Badge tone="danger">{t('orders.client.catalogue.soldOut')}</Badge>}
          {product.is_new && <Badge tone="brand">{t('orders.client.catalogue.isNew')}</Badge>}
          {product.is_featured && <Badge tone="warning">{t('orders.client.catalogue.featured')}</Badge>}
        </div>

        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          {details.map((detail) => (
            <div key={detail.label} className="contents">
              <dt className="text-slate-600">{detail.label}</dt>
              <dd className="text-slate-900">{detail.value}</dd>
            </div>
          ))}
        </dl>

        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
          <div className="flex flex-col">
            <span className="text-sm font-medium text-slate-800">{t('orders.client.sheet.quantity')}</span>
            {product.limit !== null && <span className="text-xs text-slate-600">{t('orders.client.catalogue.max', { max: formatQuantity(product.available ?? 0) })}</span>}
          </div>
          <QuantityStepper name={product.name} value={quantity} onChange={onQuantity} max={product.available} disabled={readOnly || product.sold_out} />
        </div>

        <Button variant="secondary" onClick={onClose}>
          {t('orders.client.sheet.done')}
        </Button>
      </div>
    </Dialog>
  )
}
