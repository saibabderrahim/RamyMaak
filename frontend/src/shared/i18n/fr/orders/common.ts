// Libellés communs des commandes (lot 5) : statuts, état de la journée.
// Fichier de la fondation ; les écrans ont chacun leur fichier.
const common = {
  status: {
    SUBMITTED: 'Envoyée',
    VALIDATED: 'Validée',
    REJECTED: 'Rejetée',
    CANCELLED: 'Annulée',
    EXPIRED: 'Expirée',
    IN_PREPARATION: 'En préparation',
    PREPARED: 'Préparée',
    SHIPPED: 'Expédiée',
    DELIVERED: 'Livrée',
  },
  dayStatus: {
    WAITING_QUOTAS: 'En attente des quotas du jour',
    ORDERING_OPEN: 'Commandes ouvertes jusqu’à {{time}}',
    ORDERING_CLOSED: 'Commandes clôturées',
    DAY_CLOSED: 'Commandes clôturées',
  },
  extended: 'prolongée',
  amountsHint: 'Montants indicatifs : la facture fait foi.',
  currency: '{{value}} DA',
}

export default common
