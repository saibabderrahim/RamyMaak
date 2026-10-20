import type { ClientWithoutOrder, RegionalDashboard, RegionalDashboardRegion } from '../../../shared/api/types'

/**
 * « Ma journée » du régional (EF-REG-01) — contrat : docs/lot-6-contrat-api.md
 * §3 et §8. Liens, heures limites et clients sans commande, sans React.
 */

/** Liste des commandes à valider d'une région pour la journée (`?onglet=a-valider&region=`). */
export function ordersToValidatePath(day: string, regionId: number): string {
  return `/regional/commandes?${new URLSearchParams({ day, onglet: 'a-valider', region: String(regionId) })}`
}

/** Toutes les commandes reçues de la journée (raccourci). */
export function ordersPath(day: string): string {
  return `/regional/commandes?${new URLSearchParams({ day })}`
}

/** « Quotas du jour » de la journée (raccourci). */
export function quotasPath(day: string): string {
  return `/regional/quotas?${new URLSearchParams({ day })}`
}

/** Fiche d'un client de la région. */
export function clientPath(clientId: number): string {
  return `/regional/clients/${clientId}`
}

export type CutoffPart = {
  kind: 'distribution' | 'ordering' | 'validation'
  time: string
  /** Prolongée pour la région ; « bloquante » : la validation est refusée une fois l'heure passée (RG-V08). */
  notes: ('extended' | 'blocking')[]
}

/**
 * Heures limites d'une région, prolongation comprise : répartition, commande,
 * validation — celle-ci « bloquante » quand le paramètre de la journée refuse la
 * validation après l'heure (le rejet reste permis).
 */
export function cutoffParts(region: Pick<RegionalDashboardRegion, 'cutoffs' | 'extended'>, validationBlocking: boolean): CutoffPart[] {
  const notes = (extended: boolean, blocking = false): CutoffPart['notes'] => [
    ...(extended ? (['extended'] as const) : []),
    ...(blocking ? (['blocking'] as const) : []),
  ]
  return [
    { kind: 'distribution', time: region.cutoffs.distribution, notes: notes(region.extended.distribution) },
    { kind: 'ordering', time: region.cutoffs.ordering, notes: notes(region.extended.ordering) },
    { kind: 'validation', time: region.cutoffs.validation, notes: notes(region.extended.validation, validationBlocking) },
  ]
}

/** Répartition aux clients : à faire (« Répartir »), ou à consulter une fois envoyée ou la journée clôturée. */
export function distributionAction(region: Pick<RegionalDashboardRegion, 'status' | 'quota_published_at' | 'distribution_published_at'>): 'distribute' | 'view' | null {
  if (region.quota_published_at === null) return null
  return region.distribution_published_at !== null || region.status === 'CLOSED' ? 'view' : 'distribute'
}

export type RegionWithoutOrder = {
  region: RegionalDashboardRegion
  /** Répartition envoyée : seuls ses destinataires (N-03) peuvent être « sans commande » (I-88). */
  sent: boolean
  clients: ClientWithoutOrder[]
}

/** Clients sans commande par région, dans l'ordre des régions du tableau de bord. */
export function withoutOrderGroups(dashboard: Pick<RegionalDashboard, 'regions' | 'clients_without_order'>): RegionWithoutOrder[] {
  return dashboard.regions.map((region) => ({
    region,
    sent: region.distribution_published_at !== null,
    clients: dashboard.clients_without_order.filter((client) => client.region_id === region.region.id),
  }))
}
