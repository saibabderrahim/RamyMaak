import type { Client } from '../../../shared/api/types'

/** Lien d'appel (EF-CLI-03) : chiffres et « + » seulement (« 0550 12 34 56 » → tel:0550123456). */
export function telHref(phone: string | null | undefined): string | null {
  const dialable = phone?.replace(/[^\d+]/g, '') ?? ''
  return dialable === '' ? null : `tel:${dialable}`
}

/** Lien cartographique universel : ouvre l'application de cartes sur téléphone. */
export function mapHref(lat: number | null, lng: number | null): string | null {
  if (lat === null || lng === null) return null
  return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`
}

/** Téléphones renseignés du client, dans l'ordre de la fiche. */
export function phonesOf(client: Pick<Client, 'phone' | 'phone2'>): string[] {
  return [client.phone, client.phone2].filter((phone): phone is string => Boolean(phone?.trim()))
}

/** Adresse sur deux lignes : rue, puis « commune, wilaya ». */
export function addressLines(client: Pick<Client, 'address' | 'commune' | 'wilaya'>): string[] {
  const locality = [client.commune, client.wilaya].filter((part) => Boolean(part?.trim())).join(', ')
  return [client.address?.trim() ?? '', locality].filter((line) => line !== '')
}
