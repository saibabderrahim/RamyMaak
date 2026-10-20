/**
 * Événements de décision et de traitement des commandes (lot 6) — contrat :
 * docs/lot-6-contrat-api.md §7. Identifiants seulement : l'écran relit l'API.
 * Les listes d'événements des écrans (grille, commandes reçues, quotas du
 * jour, matrice, suivi, file ADV) s'en composent, pour qu'aucun ne manque
 * (une fois connecté, useLiveRefresh ne relit plus toutes les 30 s).
 */

/** Validation par le régional : S baisse, V(r,p) et V(c,p) montent. */
export const ORDER_VALIDATED = 'OrderValidated'
/** Rejet par le régional : S baisse. */
export const ORDER_REJECTED = 'OrderRejected'
/** Changement de statut ADV, en masse ou unitaire (un événement par commande sur `region-day.*`). */
export const ORDERS_PROCESSED = 'OrdersProcessed'
/** Quantités expédiées ou bon de livraison saisis par l'ADV (`adv.{day}` seulement). */
export const ORDER_SHIPPING_UPDATED = 'OrderShippingUpdated'
/** Annulation exceptionnelle par l'ADV : V(r,p) et V(c,p) restitués (distinct de OrderCancelled, client). */
export const ORDER_CANCELLED_BY_ADV = 'OrderCancelledByAdv'

/** Canal `region-day.{regionId}.{day}` : `{ id, number, status, version, day, client_id }`, un par commande. */
export const REGION_DAY_DECISION_EVENTS = [ORDER_VALIDATED, ORDER_REJECTED, ORDERS_PROCESSED, ORDER_CANCELLED_BY_ADV] as const

/** Canal `adv.{day}` : `{ id, status, version, day }`, sans région ni client (I-81). */
export const ADV_DAY_ORDER_EVENTS = [ORDER_VALIDATED, ORDERS_PROCESSED, ORDER_SHIPPING_UPDATED, ORDER_CANCELLED_BY_ADV] as const

/** Événements qui changent V(r,p) (validé de la région) : à écouter par tout écran qui montre V ou R. */
export const VALIDATED_QUANTITY_EVENTS = [ORDER_VALIDATED, ORDER_CANCELLED_BY_ADV] as const
