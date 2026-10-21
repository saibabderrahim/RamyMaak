/** Racine des requêtes du suivi de la journée : un changement de commande ou de V les invalide. */
export const monitoringRoot = ['backoffice', 'monitoring'] as const

export const monitoringKey = (day: string) => [...monitoringRoot, day] as const
