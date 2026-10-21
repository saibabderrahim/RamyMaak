// Libellés back-office « motifs » (lot 6, EF-ADM-05, docs/lot-6-contrat-api.md §4 et §8) :
// paramétrage par l'Admin, un onglet par type, création, modification, activation, ordre.
const reasons = {
  title: 'Motifs',
  sections: 'Types de motifs',
  types: {
    REJECTION: 'Rejet',
    ADJUSTMENT: 'Ajustement',
    SHIPPING_GAP: 'Écart d’expédition',
    CANCELLATION: 'Annulation',
  },
  // Où le motif est proposé, et qui le voit.
  intro: {
    REJECTION: 'Proposés au régional pour rejeter une commande. Le client voit le motif retenu et le commentaire.',
    ADJUSTMENT: 'Proposés au régional pour réduire la quantité d’une ligne à la validation. Le client voit le motif retenu et le commentaire.',
    SHIPPING_GAP:
      'Proposés à l’ADV quand la quantité expédiée est inférieure à la quantité validée. Pas de commentaire pour un écart d’expédition. Le client voit le motif une fois la commande expédiée.',
    CANCELLATION: 'Proposés à l’ADV pour annuler exceptionnellement une commande validée. Le client voit le motif retenu et le commentaire.',
  },
  deactivateHint: 'Un motif ne se supprime pas : désactivez-le. Il n’est plus proposé, mais les commandes déjà traitées gardent son libellé.',
  create: 'Nouveau motif',
  created: 'Motif créé.',
  updated: 'Motif modifié.',
  activated: 'Motif activé : il est de nouveau proposé.',
  deactivated: 'Motif désactivé : il n’est plus proposé. Les commandes déjà traitées gardent son libellé.',
  empty: 'Aucun motif de ce type.',
  columns: {
    code: 'Code',
    label: 'Libellé',
    comment: 'Commentaire',
    sortOrder: 'Ordre',
    status: 'Statut',
  },
  comment: {
    required: 'Obligatoire',
    optional: 'Facultatif',
    none: 'Sans commentaire',
  },
  status: {
    active: 'Actif',
    inactive: 'Désactivé',
  },
  edit: 'Modifier le motif {{code}}',
  editAction: 'Modifier',
  editActionLabel: 'Modifier le motif « {{label}} »',
  activate: 'Activer',
  activateLabel: 'Activer le motif « {{label}} »',
  deactivate: 'Désactiver',
  deactivateLabel: 'Désactiver le motif « {{label}} »',
  fields: {
    type: 'Type',
    code: 'Code',
    codeHint: 'Majuscules, chiffres et « _ » (2 à 30 caractères), en commençant par une lettre. Il ne pourra plus être modifié.',
    codeLocked: 'Le code et le type ne changent pas : désactivez ce motif et créez-en un autre si besoin.',
    label: 'Libellé',
    labelHint: 'Texte montré au régional, à l’ADV et au client (100 caractères au plus).',
    requiresComment: 'Commentaire obligatoire avec ce motif',
    noComment: 'Un écart d’expédition n’a pas de commentaire.',
    sortOrder: 'Ordre',
    sortOrderHint: 'Les motifs sont proposés par ordre croissant.',
  },
  validation: {
    code: 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).',
    label: 'Libellé obligatoire.',
    labelMax: 'Le libellé ne peut pas dépasser 100 caractères.',
    sortOrder: 'Ordre : nombre entier de 0 à 65 535.',
  },
  lastActive: {
    title: 'Désactiver le dernier motif actif ?',
    text: '« {{label}} » est le dernier motif actif de ce type : tant qu’aucun autre n’est créé ou activé, {{consequence}}.',
    confirm: 'Oui, désactiver',
    consequence: {
      REJECTION: 'le régional ne pourra plus rejeter de commande',
      ADJUSTMENT: 'le régional ne pourra plus réduire une quantité à la validation',
      SHIPPING_GAP: 'l’ADV ne pourra plus saisir d’écart d’expédition',
      CANCELLATION: 'l’ADV ne pourra plus annuler de commande validée',
    },
  },
}

export default reasons
