// Notifications push, profil (notifications de l'appareil, rappels, appareils
// connectés) et invitation de l'accueil (lot 8, contrat §8). Volet C.
// Sans dépendance : le service worker en lit les textes de repli (`sw`).
const push = {
  // Notification affichée par le service worker quand la charge du push est illisible (§7).
  sw: {
    fallbackTitle: 'RamyMaak',
    fallbackBody: 'Nouvelle notification',
  },
  // Profil → Notifications : état sur cet appareil et action qui convient (§8).
  device: {
    title: 'Notifications',
    subtitle: 'Sur cet appareil',
    checking: 'Vérification des notifications sur cet appareil…',
    status: {
      on: 'Activées',
      off: 'Désactivées',
      denied: 'Bloquées',
    },
    on: 'Vous recevez les notifications de RamyMaak sur cet appareil, même application fermée.',
    off: 'Les notifications ne sont pas activées sur cet appareil. Activez-les pour être prévenu sans ouvrir l’application.',
    enable: 'Activer les notifications sur cet appareil',
    enabling: 'Activation…',
    disable: 'Désactiver sur cet appareil',
    disabling: 'Désactivation…',
    dismissed: 'Vous n’avez pas autorisé les notifications. Touchez de nouveau « Activer » et choisissez « Autoriser ».',
    unavailable: 'Les notifications push ne sont pas proposées pour le moment. Vos notifications restent dans le centre de notifications (la cloche, en haut de l’écran).',
    unsupported:
      'Ce navigateur ne reçoit pas les notifications push. Utilisez Chrome ou Edge sur Android et sur PC. Vos notifications restent dans le centre de notifications (la cloche, en haut de l’écran).',
    ios: {
      intro: 'Sur iPhone et iPad, les notifications ne fonctionnent qu’avec l’application installée (iOS 16.4 ou plus récent) :',
      steps: [
        'dans Safari, touchez le bouton Partager ;',
        'choisissez « Sur l’écran d’accueil », puis « Ajouter » ;',
        'ouvrez RamyMaak depuis son icône, puis revenez ici pour activer les notifications.',
      ],
    },
    denied: {
      intro: 'Les notifications de RamyMaak sont bloquées dans les réglages de ce navigateur. Pour les rétablir :',
      steps: [
        'Chrome ou Edge sur PC : cliquez sur l’icône à gauche de l’adresse, puis « Autorisations » et « Notifications » : Autoriser.',
        'Android : touchez l’icône à gauche de l’adresse, puis « Autorisations » et « Notifications » ; application installée : Paramètres du téléphone → Applications → RamyMaak → Notifications.',
        'iPhone ou iPad : Réglages → Notifications → RamyMaak → Autoriser les notifications.',
      ],
      after: 'Revenez ensuite sur cette page : l’état se met à jour.',
    },
    errors: {
      notReady: 'L’application n’est pas encore prête à recevoir des notifications. Rechargez la page, puis réessayez.',
      subscribe: 'Le navigateur n’a pas pu activer les notifications. Réessayez.',
      enable: 'Activation impossible : {{reason}}',
      disable: 'Désactivation impossible : {{reason}}',
    },
  },
  // Réglages de l'appareil et du compte : écriture en ligne seulement (D-13).
  offline: 'Hors ligne : reconnectez-vous au réseau pour modifier ce réglage.',
  // Rappels N-10 à N-12 (§3, §4) : clients et régionaux.
  reminders: {
    title: 'Rappels',
    label: 'Recevoir les rappels avant les heures limites',
    client: 'Un rappel avant l’heure limite de commande, si vous n’avez pas encore commandé pour la journée.',
    regional: 'Un rappel avant l’heure limite de répartition, et avant celle de validation quand des commandes attendent votre décision.',
    note: 'Dans l’application et, si elles sont activées, en notification sur vos appareils.',
    saved: 'Préférence enregistrée.',
  },
  // Profil → Appareils connectés (§5).
  sessions: {
    title: 'Appareils connectés',
    intro: 'Les appareils où votre compte est ouvert, du plus récent au plus ancien.',
    list: 'Appareils connectés à votre compte',
    loading: 'Chargement des appareils…',
    empty: 'Aucun appareil connecté.',
    current: 'Cet appareil',
    // Appareil connecté avec « Rester connecté » (I-113) : il reste dans la liste même inactif.
    remembered: 'Reste connecté',
    lastActive: 'Dernière activité : {{date}}',
    ip: 'Adresse IP {{ip}}',
    push: 'Notifications activées',
    revoke: 'Déconnecter',
    revokeLabel: 'Déconnecter {{device}}',
    revokeTitle: 'Déconnecter cet appareil ?',
    revokeConfirm: 'Le compte sera fermé sur « {{device}} » (dernière activité : {{date}}) et cet appareil ne recevra plus de notifications.',
    revoked: '« {{device}} » est déconnecté.',
    logoutAllAction: 'Tout déconnecter',
    logoutAllHint: 'En cas de perte ou de vol d’un appareil, déconnectez-le, ou fermez votre compte partout, y compris ici.',
  },
  // Accueil du client et « Ma journée » du régional (§8) : une fois par compte et par appareil.
  invite: {
    title: 'Recevez vos notifications',
    text: 'Soyez prévenu sur cet appareil des quotas, des commandes et des heures limites, même application fermée.',
    enable: 'Activer',
    later: 'Plus tard',
  },
  // Admin → Paramètres → Notifications (§2.1).
  settings: {
    pushEnabled: 'Notifications push',
    pushHint: 'Interrupteur général des notifications push. Désactivées, les notifications restent dans le centre de notifications de chacun.',
  },
}

export default push
