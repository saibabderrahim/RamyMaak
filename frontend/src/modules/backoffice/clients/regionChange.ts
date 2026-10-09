import type { Client } from '../../../shared/api/types'

/**
 * Région cible du client : la région programmée s'il y en a une (RG-S04),
 * sinon la région effective. C'est la valeur proposée par le formulaire ;
 * choisir la région actuelle annule le changement programmé.
 */
export function targetRegionId(client: Client): number {
  return client.pending_region_change?.region.id ?? client.region.id
}
