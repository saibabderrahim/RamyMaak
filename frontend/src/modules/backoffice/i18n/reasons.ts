// Libellés back-office « motifs » (lot 6, EF-ADM-05, docs/lot-6-contrat-api.md §4) :
// paramétrage par l'Admin, un onglet par type. Fichier de la fondation, complété par le
// lot de travail de l'écran.
const reasons = {
  title: 'Motifs',
  types: {
    REJECTION: 'Rejet',
    ADJUSTMENT: 'Ajustement',
    SHIPPING_GAP: 'Écart d’expédition',
    CANCELLATION: 'Annulation',
  },
}

export default reasons
