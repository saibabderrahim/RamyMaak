import { useTranslation } from 'react-i18next'
import type { ClientProduct } from '../../../shared/api/types'
import { formatAmount } from '../../../shared/format'
import { Badge } from '../../../shared/ui'
import { ProductImage } from '../../../shared/ui/ProductImage'
import { QuantityStepper } from '../../../shared/ui/QuantityStepper'
import { limitText } from '../cart/format'
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
  const limit = product.limit !== null ? limitText(t, product) : null

  // Charte Ramy (lot 7) : en liste sur téléphone, une ligne compacte (vignette, désignation, prix, puis
  // maximum et quantité sur la même ligne) dans un seul bloc à filets ; carte à partir de la tablette.
  return (
    <article
      aria-label={product.name}
      className={`flex h-full gap-3 bg-white ${
        list ? 'flex-row py-3 sm:flex-col sm:rounded-lg sm:border sm:p-3' : 'flex-col rounded-lg border p-2 sm:p-3'
      } ${product.sold_out ? 'border-slate-200 opacity-60' : quantity > 0 ? 'border-slate-900' : 'border-slate-200'}`}
    >
      {/* Vignette : ouvre la fiche (le nom fait de même au clavier). */}
      <button type="button" tabIndex={-1} aria-hidden="true" onClick={onOpen} className={`shrink-0 ${list ? 'w-16 sm:w-full' : 'w-full'}`}>
        <ProductImage photo={photo} alt="" size={list ? 64 : 200} format={product.format} className={list ? 'sm:aspect-[4/3]' : 'aspect-[4/3]'} />
      </button>

      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <button
          type="button"
          onClick={onOpen}
          aria-label={t('orders.client.catalogue.open', { name: product.name })}
          className="min-h-6 rounded text-start font-semibold leading-snug text-slate-900 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        >
          {/* Désignation courte sur téléphone (EF-PRD-01, §7.3), complète à partir de la tablette. */}
          <span className="sm:hidden">{product.short_name || product.name}</span>
          <span className="hidden sm:inline">{product.name}</span>
        </button>
        <p className="text-xs text-slate-600">{product.sales_unit_label}</p>
        <p className="text-sm tabular-nums text-slate-900">
          {t('orders.client.catalogue.price', { ht: formatAmount(product.price.price_ht), ttc: formatAmount(product.price.price_ttc) })}
        </p>
        {(product.sold_out || product.is_new || product.is_featured) && (
          <div className="flex flex-wrap items-center gap-1">
            {product.sold_out && <Badge tone="danger">{t('orders.client.catalogue.soldOut')}</Badge>}
            {product.is_new && <Badge tone="brand">{t('orders.client.catalogue.isNew')}</Badge>}
            {product.is_featured && <Badge>{t('orders.client.catalogue.featured')}</Badge>}
          </div>
        )}
        {/* Sans borne, aucune quantité n'est montrée (RG-C09). */}
        <div className={`mt-auto flex items-center gap-3 pt-1 ${list ? 'justify-between sm:flex-col sm:items-stretch' : 'flex-col items-stretch'}`}>
          {limit ? <span className="text-xs font-semibold tabular-nums text-slate-900">{limit}</span> : <span aria-hidden="true" />}
          <div className={list ? FLUID_FROM_TABLET : FLUID}>
            <QuantityStepper name={product.name} value={quantity} onChange={onQuantity} max={product.available} disabled={readOnly || product.sold_out} />
          </div>
        </div>
      </div>
    </article>
  )
}
