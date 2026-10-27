import { useState } from 'react'
import type { ProductPhoto } from '../api/types'

type Props = {
  photo: Pick<ProductPhoto, 'urls'> | { urls: { thumb: string; medium: string } } | null
  /** Texte alternatif : le nom du produit. */
  alt: string
  /** Taille affichée, pour `sizes` (px). */
  size?: number
  className?: string
  /** Sans photo : format affiché en grand (« 1 L », « 25 cl ») au lieu du pictogramme. */
  format?: string | null
  /** Sans photo : ligne sous le format (la famille du produit). */
  caption?: string | null
}

/**
 * Photo produit (EF-PRD-02, §9.8) : variante WebP adaptée à l'écran
 * (`srcset` 200 / 600 px), chargement différé, place réservée (pas de saut
 * de mise en page), visuel par défaut sans photo. Charte Ramy (lot 7) : la
 * bouteille entière reste visible (object-contain) sur le fond blanc mesuré des
 * photos, et un produit sans photo montre son format en grand. Hors ligne
 * (lot 8, §6.2), une photo jamais vue sur l'appareil (absente du cache du
 * service worker) laisse place au visuel par défaut.
 */
export function ProductImage({ photo, alt, size = 72, className = '', format = null, caption = null }: Props) {
  // Photo illisible (hors ligne, jamais vue) : visuel par défaut, jusqu'à ce que l'adresse change.
  const [failed, setFailed] = useState<string | null>(null)
  // Carré par défaut ; une classe aspect-* sans préfixe de l'appelant le remplace (sm:aspect-* s'y ajoute).
  const square = /(^|\s)aspect-/.test(className) ? '' : 'aspect-square'
  // self-start : dans une rangée flex, la vignette garde son format au lieu de s'étirer à la hauteur de la ligne.
  const box = `relative self-start overflow-hidden rounded-md ${square} ${className}`
  if (!photo || failed === photo.urls.thumb) {
    return (
      <div className={`${box} flex items-center justify-center bg-slate-100 text-slate-600`} role="img" aria-label={alt}>
        {format ? (
          <span aria-hidden="true" className="flex flex-col items-center gap-0.5 px-1 text-center leading-tight">
            <span className={`font-semibold tabular-nums ${size >= 120 ? 'text-3xl' : 'text-lg'}`}>{format}</span>
            {caption && <span className="text-xs">{caption}</span>}
          </span>
        ) : (
          <svg viewBox="0 0 24 24" aria-hidden="true" className="size-1/2" fill="none" stroke="currentColor" strokeWidth="1.5">
            <path d="M4 7h16v12H4zM8 7V5h8v2M9 12h6" />
          </svg>
        )}
      </div>
    )
  }
  return (
    // Fond des photos détourées sur blanc (mesuré) : la bouteille se détache sans cadre visible.
    <div className={`${box} bg-photo`}>
      <img
        src={photo.urls.thumb}
        srcSet={`${photo.urls.thumb} 200w, ${photo.urls.medium} 600w`}
        sizes={`${size}px`}
        alt={alt}
        loading="lazy"
        decoding="async"
        onError={() => setFailed(photo.urls.thumb)}
        className="absolute inset-0 size-full object-contain"
      />
    </div>
  )
}
