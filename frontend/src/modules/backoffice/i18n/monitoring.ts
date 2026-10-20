// Libellés back-office « suivi de la journée » (lot 6, EF-ADV-03, docs/lot-6-contrat-api.md
// §6 et §8) : chiffres clés, régions et alertes, matrice produit × région, et les libellés
// des liens vers ce suivi depuis les journées (prolongation de l'heure limite de validation).
const monitoring = {
  title: 'Suivi de la journée',
  subtitle: 'Journée du {{day}}',
  invalid: 'Date de journée invalide : format attendu AAAA-MM-JJ.',
  notOpened: 'Cette journée n’est pas ouverte : il n’y a rien à suivre.',
  loading: 'Chargement du suivi…',
  generatedAt: 'Chiffres relus le {{date}}',
  links: {
    day: 'Fiche de la journée',
    orders: 'Commandes de la journée',
  },
  tiles: {
    label: 'Chiffres clés',
    SUBMITTED: 'À valider',
    VALIDATED: 'À préparer',
    IN_PREPARATION: 'En préparation',
    PREPARED: 'Préparées',
    SHIPPED: 'Expédiées',
    REJECTED: 'Rejetées',
    CANCELLED: 'Annulées',
    EXPIRED: 'Expirées',
    clients: 'Clients ayant commandé',
    clientsValue: '{{ordered}} / {{notified}}',
    quotas: 'Quotas validés',
    quotasValue: '{{validated}} / {{quota}}',
    quotasHint: '{{pending}} en attente de validation',
  },
  regions: {
    title: 'Régions',
    empty: 'Aucune région dans votre périmètre pour cette journée.',
    columns: {
      region: 'Région',
      status: 'Statut',
      cutoffs: 'Heures limites',
      clients: 'Clients',
      orders: 'Commandes',
      lastValidated: 'Dernière validation',
      alerts: 'Alertes',
      links: 'Accès',
    },
    cutoff: {
      distribution: 'Répartition {{time}}',
      ordering: 'Commande {{time}}',
      validation: 'Validation {{time}}',
    },
    clientsValue: '{{ordered}} sur {{notified}}',
    noOrders: 'Aucune commande',
    noAlert: 'Aucune alerte',
    alerts: {
      DISTRIBUTION_LATE: 'Répartition en retard',
      VALIDATION_LATE: 'Validation en retard',
    },
    alertHints: {
      DISTRIBUTION_LATE: 'Quotas publiés, répartition non envoyée et heure limite de répartition dépassée',
      VALIDATION_LATE: 'Commandes à valider et heure limite de validation dépassée',
    },
    ordersLink: 'Commandes',
    ordersLinkLabel: 'Commandes de la région {{name}}',
    distributionLink: 'Répartition',
    distributionLinkLabel: 'Répartition de la région {{name}}',
    noDepot: 'Sans dépôt',
  },
  matrix: {
    title: 'Quotas validés par produit et par région',
    label: 'Quantités validées sur quota, par produit et par région',
    empty: 'Aucun quota publié ni aucune commande à valider pour cette journée.',
    product: 'Produit',
    total: 'Total',
    legend: 'Validé / Quota',
    legendPending: 'quantité en attente de validation',
    legendSoldOut: 'Épuisé : quota entièrement validé',
    cell: '{{validated}} / {{quota}}',
    pending: '+{{pending}} en attente',
    soldOut: 'Épuisé',
    none: '—',
    cellLabel: '{{product}}, {{region}} : {{validated}} validés sur {{quota}}, {{pending}} en attente',
    remaining: 'reste {{remaining}}',
  },
  // Libellés des écrans « Journées » liés au suivi et à la validation (I-98) : le fichier des journées est celui du lot 3.
  days: {
    link: 'Suivi de la journée',
    linkLabel: 'Suivi de la journée du {{day}}',
    validationColumn: 'Validation',
    kinds: {
      VALIDATION: 'Validation',
    },
    kindLower: {
      DISTRIBUTION: 'répartition',
      ORDERING: 'commande',
      VALIDATION: 'validation',
    },
  },
} as const

export default monitoring
