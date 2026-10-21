import { useTranslation } from 'react-i18next'
import type { Product } from '../../../shared/api/types'
import { formatDate } from '../../../shared/format'
import { Badge } from '../../../shared/ui'

/**
 * Vignette du produit (§9.8) : la variante « thumb » de la photo principale,
 * chargée à la demande, emplacement réservé (largeur et hauteur fixées) ;
 * visuel neutre par défaut sans photo (EF-PRD-02).
 */
export function ProductThumbnail({ product, size = 48 }: { product: Pick<Product, 'photos'>; size?: number }) {
  const { t } = useTranslation()
  const photo = product.photos[0]
  const style = { width: size, height: size }

  if (!photo) {
    return (
      <span
        role="img"
        aria-label={t('backoffice.products.placeholder')}
        data-testid="product-placeholder"
        style={style}
        className="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-400"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true" className="size-1/2">
          <rect x="3" y="5" width="18" height="14" rx="2" />
          <circle cx="9" cy="10" r="1.5" />
          <path d="m21 16-5-5-8 8" />
        </svg>
      </span>
    )
  }

  return (
    <img
      src={photo.urls.thumb}
      alt=""
      width={size}
      height={size}
      loading="lazy"
      decoding="async"
      style={style}
      className="shrink-0 rounded-md bg-slate-50 object-contain"
    />
  )
}

type BadgeProduct = Pick<Product, 'is_active' | 'is_new' | 'is_featured'> & Partial<Pick<Product, 'pending_deactivation_on'>>

/**
 * Statut, désactivation programmée (RG-Q07) et badges « Nouveau », « Mis en
 * avant » (EF-PRD-05).
 */
export function ProductBadges({ product }: { product: BadgeProduct }) {
  const { t } = useTranslation()
  return (
    <span className="inline-flex flex-wrap gap-1">
      {product.is_active ? (
        <Badge tone="success">{t('backoffice.products.status.active')}</Badge>
      ) : (
        <Badge>{t('backoffice.products.status.inactive')}</Badge>
      )}
      {product.pending_deactivation_on && (
        <span title={t('backoffice.products.pendingDeactivation.reason')}>
          <Badge tone="warning">{t('backoffice.products.pendingDeactivation.badge', { date: formatDate(product.pending_deactivation_on) })}</Badge>
          <span className="sr-only"> : {t('backoffice.products.pendingDeactivation.reason')}</span>
        </span>
      )}
      {product.is_new && <Badge tone="brand">{t('backoffice.products.badges.new')}</Badge>}
      {product.is_featured && <Badge tone="warning">{t('backoffice.products.badges.featured')}</Badge>}
    </span>
  )
}
