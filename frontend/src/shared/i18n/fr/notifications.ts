// Libellés du centre de notifications (EF-NOT-01), de la cloche de l'en-tête
// et des « Quotas du jour » du régional (lot 3, lot de travail F), avec
// l'état de la répartition de chaque région (lot 4, lot de travail D). Les
// notifications N-01 à N-04 arrivent avec leur titre et leur corps rédigés
// par le serveur : aucun libellé par type ici.
const notifications = {
  title: 'Notifications',
  bell: {
    label: 'Notifications',
    unread_one: 'Notifications ({{count}} non lue)',
    unread_other: 'Notifications ({{count}} non lues)',
    received: 'Nouvelle notification : {{title}}',
  },
  filter: {
    label: 'Notifications à afficher',
    all: 'Toutes',
    unread: 'Non lues',
  },
  markAllRead: 'Tout marquer comme lu',
  unreadCount_one: '{{count}} notification non lue',
  unreadCount_other: '{{count}} notifications non lues',
  allRead: 'Toutes vos notifications sont lues.',
  unread: 'Non lue',
  list: 'Liste des notifications',
  more: 'Afficher plus',
  empty: 'Aucune notification pour le moment.',
  emptyUnread: 'Aucune notification non lue.',
  regionalQuotas: {
    title: 'Quotas du jour',
    dayChoice: 'Journée affichée',
    today: 'Aujourd’hui',
    tomorrow: 'Demain',
    dayTitle: 'Journée du {{date}}',
    updatedAt: 'Mis à jour à {{time}}',
    notOpened: 'Les quotas de cette journée ne sont pas encore ouverts.',
    notOpenedHint: 'Vous recevrez une notification dès leur publication par l’ADV.',
    closed: 'Journée clôturée : les quotas ne sont plus modifiables.',
    noRegion: 'Aucune région ne vous est affectée pour cette journée : vous n’avez pas de quotas à consulter. Contactez l’administrateur.',
    status: {
      QUOTA_DRAFT: 'En attente des quotas',
      QUOTA_PUBLISHED: 'À répartir',
      DISTRIBUTION_DRAFT: 'Répartition en cours',
      DISTRIBUTION_PUBLISHED: 'Répartition publiée',
      CLOSED: 'Clôturée',
    },
    published: 'Quotas publiés — version {{version}}, le {{date}} à {{time}}',
    waiting: 'L’ADV n’a pas encore publié les quotas de cette région. Vous recevrez une notification dès leur publication.',
    closedWithoutQuotas: 'Aucun quota n’a été publié pour cette région ce jour-là.',
    noQuota: 'Aucun quota pour cette région.',
    cutoffs: 'Heures limites',
    distribution: 'Répartition avant {{time}}',
    ordering: 'Commande avant {{time}}',
    extended: 'prolongée',
    quantity: '{{quantity}}\u00a0UV',
    validated: 'Validé : {{quantity}}\u00a0UV',
    products_one: '{{count}} produit',
    products_other: '{{count}} produits',
    // Répartition aux clients (§6.1, EF-REG-02), une fois les quotas publiés.
    distributionState: {
      toDistribute: 'À répartir avant {{time}}',
      draft: 'Répartition en cours — pas encore envoyée',
      sent: 'Répartition envoyée aux clients (v{{version}})',
      notSent: 'Répartition non envoyée aux clients',
      distribute: 'Répartir',
      view: 'Voir la répartition',
      linkLabel: '{{action}} — {{name}}',
    },
  },
}

export default notifications
