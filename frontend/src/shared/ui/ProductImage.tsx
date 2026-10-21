import type { ProductPhoto } from '../api/types'

type Props = {
  photo: Pick<ProductPhoto, 'urls'> | { urls: { thumb: string; medium: string } } | null
  /** Texte alternatif : le nom du produit. */
  alt: string
  /** Taille affichée, pour `sizes` (px). */
  size?: number
  className?: string
}

/**
 * Photo produit (EF-PRD-02, §9.8) : variante WebP adaptée à l'écran
 * (`srcset` 200 / 600 px), chargement différé, place réservée (pas de saut
 * de mise en page), visuel par défaut sans photo.
 */
export function ProductImage({ photo, alt, size = 72, className = '' }: Props) {
  const box = `aspect-square overflow-hidden rounded-lg bg-slate-100 ${className}`
  if (!photo) {
    return (
      <div className={`${box} flex items-center justify-center text-slate-400`} role="img" aria-label={alt}>
        <svg viewBox="0 0 24 24" aria-hidden="true" className="size-1/2" fill="none" stroke="currentColor" strokeWidth="1.5">
          <path d="M4 7h16v12H4zM8 7V5h8v2M9 12h6" />
        </svg>
      </div>
    )
  }
  return (
    <div className={box}>
      <img
        src={photo.urls.thumb}
        srcSet={`${photo.urls.thumb} 200w, ${photo.urls.medium} 600w`}
        sizes={`${size}px`}
        alt={alt}
        loading="lazy"
        decoding="async"
        className="size-full object-cover"
      />
    </div>
  )
}
