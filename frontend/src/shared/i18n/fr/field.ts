// Libellés des écrans terrain du lot 2 : « Mes clients » du régional
// (EF-CLI-03), « Mon entreprise » du client (EF-CLT-06). Fichier du lot de
// travail « écrans terrain ».
const field = {
  status: {
    ACTIVE: 'Actif',
    BLOCKED: 'Bloqué',
    INACTIVE: 'Inactif',
  },
  call: 'Appeler',
  callLabel: 'Appeler {{name}} au {{phone}}',
  noPhone: 'Pas de téléphone',
  pendingRegion: 'Région {{region}} à partir du {{date}}',
  clients: {
    title: 'Mes clients',
    search: 'Rechercher un client',
    searchPlaceholder: 'Code, nom, enseigne ou téléphone',
    count_one: '{{count}} client',
    count_other: '{{count}} clients',
    shown: '{{shown}} affichés sur {{total}}',
    more: 'Afficher plus de clients',
    empty: 'Aucun client actif ou bloqué dans vos régions.',
    noMatch: 'Aucun client ne correspond à « {{search}} ».',
    noRegion: 'Aucune région ne vous est affectée aujourd’hui : vous n’avez pas encore de clients. Contactez l’administrateur.',
  },
  client: {
    back: 'Retour à mes clients',
    notFound: 'Ce client est introuvable ou ne fait plus partie de vos régions.',
    blocked: 'Client bloqué : il ne peut pas commander.',
    inactive: 'Client inactif : il ne peut plus commander.',
    reason: 'Motif : {{reason}}',
    since: 'Depuis le {{date}}',
    pendingRegion: 'Passera en région {{region}} à partir du {{date}}.',
    map: 'Voir sur la carte',
    noContact: 'Aucun téléphone ni e-mail renseigné.',
    noAddress: 'Adresse non renseignée.',
    sections: {
      contact: 'Contact',
      address: 'Adresse',
      identity: 'Identification',
      tax: 'Identifiants fiscaux',
    },
  },
  fields: {
    code: 'Code client',
    name: 'Raison sociale',
    tradeName: 'Enseigne',
    category: 'Catégorie',
    region: 'Région',
    address: 'Adresse',
    phone: 'Téléphone',
    phone2: 'Second téléphone',
    email: 'E-mail',
    status: 'Statut',
    nif: 'NIF',
    nis: 'NIS',
    rc: 'RC (registre du commerce)',
    ai: 'Article d’imposition',
  },
  company: {
    title: 'Mon entreprise',
    blocked: 'Votre compte client est bloqué : la prise de commande est suspendue. Contactez votre commercial régional.',
    inactive: 'Votre compte client est inactif : la prise de commande n’est plus possible.',
    help: 'Une information est inexacte ? Signalez-la à votre commercial régional.',
  },
} as const

export default field
