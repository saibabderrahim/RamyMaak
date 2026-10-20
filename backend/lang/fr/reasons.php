<?php

// Motifs (EF-ADM-05, lot 6) : rejet, ajustement, écart d'expédition, annulation.
return [
    'unknown' => 'Motif inconnu ou désactivé : choisissez un motif de la liste.',
    'comment_required' => 'Commentaire obligatoire pour ce motif.',
    'comment_max' => 'Le commentaire ne peut pas dépasser :max caractères.',
    'code_format' => 'Code attendu : de 2 à 30 caractères, majuscules, chiffres ou « _ », en commençant par une lettre (ex. QUOTA_EPUISE).',
    'code_taken' => 'Ce code existe déjà pour ce type de motif.',
    'label_taken' => 'Ce libellé existe déjà pour ce type de motif.',
    'label_format' => 'Libellé obligatoire, :max caractères au plus.',
    'comment_not_stored' => 'Un motif d\'écart d\'expédition ne peut pas exiger de commentaire.',
    'code_immutable' => 'Le type et le code d\'un motif ne se modifient pas : désactivez-le et créez-en un autre.',
    'unknown_type' => 'Type de motif inconnu : REJECTION, ADJUSTMENT, SHIPPING_GAP ou CANCELLATION.',
    'attributes' => [
        'type' => 'type',
        'code' => 'code',
        'label' => 'libellé',
        'requires_comment' => 'commentaire obligatoire',
        'is_active' => 'actif',
        'sort_order' => 'ordre',
        'filter' => 'filtre',
        'filter.type' => 'type',
    ],
    'audit' => [
        'created' => 'Motif créé',
        'updated' => 'Motif modifié',
        'deleted' => 'Motif supprimé',
    ],
];
