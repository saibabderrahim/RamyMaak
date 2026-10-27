// Hors ligne : bandeaux « données de HH:MM » et boîte d'envoi des commandes
// client (lot 8, contrat §6). Volet D.
// Sans dépendance : le service worker en lit les textes des notifications locales (`sync`).
const offline = {
  // Écrans lus hors ligne (§6.1) : heure de la dernière lecture réussie de la requête principale.
  data: {
    title: 'Hors ligne — données de {{time}}',
    titleDay: 'Hors ligne — données du {{date}} à {{time}}',
    none: 'Pas de données enregistrées sur cet appareil pour cet écran : reconnectez-vous au réseau.',
    readOnly: 'Les actions qui modifient des données reprendront au retour du réseau.',
  },
  // Boîte d'envoi des commandes client (§6.3).
  outbox: {
    title: 'Commandes en attente d’envoi',
    day: 'Commande du {{date}}',
    lines_one: '{{count}} produit',
    lines_other: '{{count}} produits',
    status: {
      pending: 'En attente d’envoi',
      sending: 'Envoi en cours…',
      sent: 'Envoyée — {{number}}',
      refused: 'Refusée : {{reason}}',
    },
    pendingHint: 'Elle partira d’elle-même dès que le réseau le permettra.',
    unauthenticated: 'Reconnectez-vous pour envoyer.',
    refusedFallback: 'le serveur n’a pas accepté la commande',
    open: 'Voir la commande {{number}}',
    resume: 'Reprendre dans le panier',
    remove: 'Retirer',
    resumeLabel: 'Reprendre dans le panier la commande du {{date}}',
    removeLabel: 'Retirer de cet appareil la commande du {{date}}',
    replaceTitle: 'Remplacer le panier ?',
    replace_one: 'Votre panier contient {{count}} produit : il sera remplacé par la commande refusée.',
    replace_other: 'Votre panier contient {{count}} produits : il sera remplacé par la commande refusée.',
    replaceEditing: 'La modification de la commande {{number}} en cours sera abandonnée.',
    replaceConfirm: 'Remplacer le panier',
    removeTitle: 'Retirer cette commande ?',
    removeText: 'La commande refusée du {{date}} sera retirée de cet appareil. Elle n’a pas été enregistrée par le serveur.',
    resumed: 'Panier reconstruit à partir de la commande refusée : corrigez-le si besoin, puis envoyez-le à nouveau.',
    // Pastille de l'onglet « Commandes » (nom accessible).
    badge_one: '{{count}} commande à envoyer ou refusée',
    badge_other: '{{count}} commandes à envoyer ou refusées',
  },
  // Panier : l'envoi d'une nouvelle commande passe par la boîte d'envoi, en ligne comme hors ligne (I-102).
  cart: {
    offlineNew: 'Hors ligne : la commande sera enregistrée sur cet appareil et envoyée dès le retour du réseau.',
    offlineEditing:
      'Hors ligne : l’enregistrement des modifications reprendra au retour du réseau. Votre panier reste enregistré sur cet appareil.',
    kept: 'Votre panier reste enregistré sur cet appareil.',
    busyDay: 'Une commande est déjà en attente d’envoi pour cette journée.',
    queued: 'Commande enregistrée sur cet appareil : elle partira dès le retour du réseau.',
    storageFailed: 'La commande n’a pas pu être enregistrée sur cet appareil : réessayez. Votre panier est conservé.',
  },
  // Écritures en ligne seulement (D-13).
  actions: {
    order: 'Hors ligne : la modification, l’annulation et « Recommander » reprendront au retour du réseau.',
    reset: 'Hors ligne : la réinitialisation d’un mot de passe reprendra au retour du réseau.',
  },
  // Déconnexion avec des commandes en attente (§6.3, point 7).
  logout: {
    title: 'Se déconnecter ?',
    pending_one: '{{count}} commande en attente d’envoi sera supprimée de cet appareil. Se déconnecter quand même ?',
    pending_other: '{{count}} commandes en attente d’envoi seront supprimées de cet appareil. Se déconnecter quand même ?',
    confirm: 'Se déconnecter',
  },
  // Notifications locales du service worker après un envoi en arrière-plan (Background Sync).
  sync: {
    sentTitle: 'Commande {{number}} envoyée',
    sentBody: 'Votre commande enregistrée sur l’appareil a été reçue.',
    refusedTitle: 'Commande refusée : {{reason}}',
    refusedBody: 'Ouvrez « Mes commandes » pour la reprendre dans le panier ou la retirer.',
  },
} as const

export default offline
