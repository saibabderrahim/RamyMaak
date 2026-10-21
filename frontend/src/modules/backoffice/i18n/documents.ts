// Libellés back-office « documents » (lot 6, RG-A04, docs/lot-6-contrat-api.md §6) : bon de
// commande PDF, bon de préparation (PDF, Excel), export de la journée (Excel, CSV, ERP).
const documents = {
  orderPdf: 'Bon de commande (PDF)',
  pickingList: 'Bon de préparation',
  export: 'Export',
  download: 'Télécharger',
  downloading: 'Préparation du fichier…',
  offline: 'Hors ligne : les téléchargements sont indisponibles.',
  picking: {
    title: 'Bon de préparation — {{day}}',
    intro: 'Quantités validées à préparer pour les commandes Validées, En préparation et Préparées de la journée, par produit.',
    group: 'Regroupement',
    groups: {
      region: 'Par région',
      depot: 'Par dépôt',
    },
    groupHints: {
      region: 'Une section par région, avec ses totaux.',
      depot: 'Une section par dépôt actuel de la région (« Sans dépôt » pour les régions qui n’en ont pas).',
    },
    format: 'Format',
    formats: {
      pdf: 'PDF',
      xlsx: 'Excel (XLSX)',
    },
  },
  exportDialog: {
    title: 'Export des commandes — {{day}}',
    intro: 'Une ligne par ligne de commande de la journée.',
    format: 'Format',
    formats: {
      xlsx: 'Excel (XLSX)',
      csv: 'CSV (Excel français)',
      erp: 'ERP (format provisoire)',
    },
    formatHints: {
      xlsx: 'Tous les statuts, cellules numériques.',
      csv: 'Séparateur « ; », virgule décimale, ouvert directement par Excel.',
      erp: 'Commandes validées à expédiées seulement ; format provisoire « erp-v0 » en attendant celui de l’ERP.',
    },
  },
} as const

export default documents
