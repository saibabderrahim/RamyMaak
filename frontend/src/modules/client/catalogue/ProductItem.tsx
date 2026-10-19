import { useTranslation } from 'react-i18next'
import type { ClientProduct } from '../../../shared/api/types'
import { formatAmount } from '../../../shared/format'
import { Badge } from '../../../shared/ui'
import { ProductImage } from '../../../shared/ui/ProductImage'
import { QuantityStepper } from '../../../shared/ui/QuantityStepper'
import { formatQuantity } from '../cart/format'
import type { CatalogueView } from './view'

// Sélecteur étiré à la largeur de la carte (galerie à 2 colonnes, cartes étroites à côté du panier latéral).
const FLUID = '[&>div]:w-full [&_input]:w-0 [&_input]:min-w-0 [&_input]:flex-1'
const FLUID_FROM_TABLET = 'sm:[&>div]:w-full sm:[&_input]:w-0 sm:[&_input]:min-w-0 sm:[&_input]:flex-1'

type Props = {
  product: ClientProduct
  /** Quantité au panier (0 : absent). */
  quantity: number
  onQuantity: (quantity: number) => void
  /** Client bloqué ou commande impossible : quantités non modifiables. */
  readOnly: boolean
  view: CatalogueView
  onOpen: () => void
}

/**
 * Carte d'un produit du jour (EF-CLT-02) : photo, nom (nom court sur
 * téléphone), UV, prix HT et TTC,
 * « Max : n » seulement si une borne existe (le disponible D, RG-C09,
 * I-45), sélecteur de quantité lié au panier et bloqué au disponible.
 * Produit épuisé : grisé, sélecteur désactivé. Sur téléphone, ligne compacte
 * (vignette 72 px) ou carte de galerie ; carte à partir de la tablette.
 */
export function ProductItem({ product, quantity, onQuantity, readOnly, view, onOpen }: Props) {
  const { t } = useTranslation()
  const list = view === 'list'
  const photo = product.photos[0] ?? null

  return (
    <article
      aria-label={product.name}
      className={`flex h-full gap-3 rounded-xl border bg-white p-3 shadow-xs ${list ? 'flex-row sm:flex-col' : 'flex-col'} ${
        product.sold_out ? 'border-slate-200 opacity-60' : quantity > 0 ? 'border-brand-300' : 'border-slate-200'
      }`}
    >
      {/* Vignette : ouvre la fiche (le nom fait de même au clavier). */}
      <button type="button" tabIndex={-1} aria-hidden="true" onClick={onOpen} className={`shrink-0 ${list ? 'w-[72px] sm:w-full' : 'w-full'}`}>
        <ProductImage photo={photo} alt="" size={list ? 72 : 200} />
      </button>

      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <button
          type="button"
          onClick={onOpen}
          aria-label={t('orders.client.catalogue.open', { name: product.name })}
          className="min-h-11 text-start font-semibold leading-snug text-slate-900 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
        >
          {/* Désignation courte sur téléphone (EF-PRD-01, §7.3), complète à partir de la tablette. */}
          <span className="sm:hidden">{product.short_name || product.name}</span>
          <span className="hidden sm:inline">{product.name}</span>
        </button>
        <p className="text-xs text-slate-600">{product.sales_unit_label}</p>
        <p className="text-sm text-slate-800">
          {t('orders.client.catalogue.price', { ht: formatAmount(product.price.price_ht), ttc: formatAmount(product.price.price_ttc) })}
        </p>
        <div className="flex flex-wrap items-center gap-1">
          {product.sold_out && <Badge tone="danger">{t('orders.client.catalogue.soldOut')}</Badge>}
          {product.is_new && <Badge tone="brand">{t('orders.client.catalogue.isNew')}</Badge>}
          {product.is_featured && <Badge tone="warning">{t('orders.client.catalogue.featured')}</Badge>}
          {/* Sans borne, aucune quantité n'est montrée (RG-C09). */}
          {product.limit !== null && <span className="text-xs font-medium text-slate-700">{t('orders.client.catalogue.max', { max: formatQuantity(product.available ?? 0) })}</span>}
        </div>
        <div className={`mt-auto pt-1 ${list ? FLUID_FROM_TABLET : FLUID}`}>
          <QuantityStepper name={product.name} value={quantity} onChange={onQuantity} max={product.available} disabled={readOnly || product.sold_out} />
        </div>
      </div>
    </article>
  )
}
