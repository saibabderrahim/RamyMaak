<?php

// Imports Excel des quotas et des référentiels (§8.1, §8.3, Annexes B.1, B.3 à
// B.5) : dépôt, rapport d'analyse au format du §8.1, application, gabarits.
return [
    'attributes' => [
        'type' => 'type de fichier',
        'file' => 'fichier',
        'business_day' => 'journée',
        'mode' => 'mode',
        'day' => 'journée',
    ],
    'unknown_type' => 'Type d\'import inconnu.',
    'unknown_status' => 'Statut inconnu.',
    'mode_invalid' => 'Mode attendu : REPLACE (remplacer) ou COMPLETE (compléter).',
    // Journée d'un import de quotas (§8.1) : ouverte et non clôturée.
    'day' => [
        'format' => 'Date attendue au format AAAA-MM-JJ.',
        'not_open' => 'La journée du :day n\'est pas ouverte.',
        'closed' => 'La journée du :day est clôturée : ses quotas ne sont plus modifiables.',
    ],
    'failed' => 'L\'analyse du fichier a été interrompue par un incident technique. Déposez à nouveau le fichier ; si le problème persiste, contactez l\'administrateur.',
    // Motif d'un blocage importé sans motif (EF-CLI-04).
    'status_reason' => 'Import Excel du :date',

    // Contrôles du dépôt (§8.4, §12 « Fichiers »).
    'file' => [
        'format' => 'Fichier .xlsx attendu, 5 Mo maximum.',
        'macros' => 'Les classeurs contenant des macros sont refusés : enregistrez le fichier au format « Classeur Excel (.xlsx) ».',
    ],

    'apply' => [
        'has_errors' => 'Le fichier contient des erreurs : corrigez-le puis déposez-le à nouveau.',
        'new_errors' => 'Les données ont changé depuis l\'analyse et le fichier contient maintenant des erreurs : rien n\'a été enregistré. Consultez le rapport, corrigez le fichier puis déposez-le à nouveau.',
        'pending' => 'L\'analyse du fichier n\'a pas encore commencé.',
        'analyzing' => 'L\'analyse du fichier est en cours.',
        'applied' => 'Cet import a déjà été appliqué.',
        'failed' => 'L\'analyse de ce fichier a échoué : déposez-le à nouveau.',
    ],

    'audit' => [
        'applied' => 'Import Excel appliqué',
    ],

    // Fichier d'erreurs annoté (§8.1, étape 3).
    'report' => [
        'column' => 'erreurs',
        'filename' => ':name-erreurs.xlsx',
    ],

    'template' => [
        'filename' => 'gabarit-:type-:date.xlsx',
        'names' => [
            'CLIENTS' => 'clients',
            'PRODUCTS' => 'produits',
            'PRICES' => 'prix',
            'QUOTAS' => 'quotas',
        ],
        'information' => ':column (information)',
        'required' => 'Colonne obligatoire (et valeur obligatoire pour une création).',
    ],

    'subjects' => [
        'client' => 'client :key',
        'product' => 'produit :key',
        'price' => 'prix :key',
        'price_key' => ':code au :date',
    ],

    // Messages du rapport, au format du §8.1 : « Ligne 14, colonne code_region : … ».
    'errors' => [
        'cell' => 'Ligne :line, colonne :column : :message',
        'header' => 'Colonne :column : :message',
        'missing_column' => 'colonne obligatoire absente',
        'duplicate_column' => 'colonne présente plusieurs fois',
        'unreadable' => 'Fichier illisible : enregistrez-le au format « Classeur Excel (.xlsx) » puis déposez-le à nouveau.',
        'too_many_rows' => 'Le fichier dépasse :max lignes : découpez-le en plusieurs fichiers.',
        'empty' => 'Le fichier ne contient aucune ligne de données.',
        'formula' => 'la formule renvoie une erreur (:value)',
        'required' => 'valeur obligatoire',
        'required_to_create' => 'valeur obligatoire pour créer le :subject',
        'code_format' => ':value n\'est pas un code valide (lettres, chiffres, tirets et tirets bas, 30 caractères au plus)',
        'too_long' => ':max caractères au plus',
        'positive_integer' => ':value n\'est pas un entier positif',
        'natural_integer' => ':value n\'est pas un entier positif ou nul',
        'integer_max' => ':value dépasse le maximum autorisé (:max)',
        'date' => ':value n\'est pas une date valide (JJ/MM/AAAA)',
        'past_price_date' => 'date d\'effet passée : un prix déjà appliqué ne se modifie pas',
        'duplicate' => ':subject déjà présent ligne :first',
        'client_out_of_perimeter' => 'client :value hors de votre périmètre',
        'unknown_region' => 'code région inconnu : :value',
        'inactive_region' => 'la région :value est inactive',
        'region_out_of_perimeter' => 'région :value hors de votre périmètre',
        'unknown_category' => 'catégorie inconnue : :value',
        'email' => ':value n\'est pas une adresse e-mail valide',
        'latitude' => ':value n\'est pas une latitude valide (de -90 à 90)',
        'longitude' => ':value n\'est pas une longitude valide (de -180 à 180)',
        'coordinates_pair' => 'renseignez la latitude et la longitude ensemble',
        'client_status' => 'statut inconnu : :value (ACTIF, BLOQUE ou INACTIF)',
        'status_forbidden' => 'vous n\'avez pas le droit de changer le statut d\'un client',
        'unknown_family' => 'code famille inconnu : :value',
        'ean' => ':value n\'est pas un code EAN valide (8 à 14 chiffres)',
        'vat_rate' => ':value n\'est pas un taux de TVA valide (de 0 à 100, 2 décimales au plus)',
        'product_status' => 'statut inconnu : :value (ACTIF ou INACTIF)',
        'unknown_product' => 'code produit inconnu : :value',
        // Quotas (§8.1).
        'day_closed' => 'La journée du :day est clôturée : ses quotas ne sont plus modifiables.',
        'unknown_region_column' => 'code région inconnu',
        'inactive_region_column' => 'région inactive',
        'region_column_out_of_perimeter' => 'région hors de votre périmètre',
        'ambiguous_region_column' => 'code région ambigu (:codes)',
        'region_day_closed' => 'la journée de cette région est clôturée',
        'no_region_column' => 'Aucune colonne de région : ajoutez une colonne par code région (ex. CENTRE), comme dans le gabarit.',
        'unknown_or_inactive_product' => 'code produit :value inconnu ou inactif',
        'below_validated' => ':value inférieur aux :validated déjà validés',
        'absent_below_validated' => 'produit :product absent du fichier (0 en mode Remplacer) : inférieur aux :validated déjà validés',
        'amount' => ':value n\'est pas un montant valide (nombre positif, 2 décimales au plus)',
    ],
];
