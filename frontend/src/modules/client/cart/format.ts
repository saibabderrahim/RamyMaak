import type { TFunction } from 'i18next'
import type { ClientProduct } from '../../../shared/api/types'
import { formatDate, formatQuantity } from '../../../shared/format'

// Mise en forme commune à l'accueil, au catalogue et au panier du client (ENF-13).

const weekdayFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'long' })

/** « jeudi 08/10/2026 » pour « 2026-10-08 » (date calendaire, sans fuseau). */
export function formatDayLong(day: string): string {
  return `${weekdayFormat.format(new Date(`${day.slice(0, 10)}T00:00:00Z`))} ${formatDate(day)}`
}

const rateFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })

/** Taux de TVA en pourcentage : « 19 », « 9,5 ». */
export function formatRate(rate: number): string {
  return rateFormat.format(rate)
}

/**
 * Quantités montrées au client pour un produit borné (RG-C09) : « Max : n »
 * (disponible), précédé de la borne quand le disponible est en dessous
 * (« Borne : 50 · Max : 20 »). Null sans borne : aucune quantité montrée.
 */
export function limitText(t: TFunction, product: Pick<ClientProduct, 'limit' | 'available'>): string | null {
  if (product.limit === null) return null
  const max = formatQuantity(product.available ?? 0)
  return product.available !== null && product.available < product.limit
    ? t('orders.client.catalogue.limitMax', { limit: formatQuantity(product.limit), max })
    : t('orders.client.catalogue.max', { max })
}
