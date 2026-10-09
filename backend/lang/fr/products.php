<?php

// Catalogue produits (EF-PRD-01 à 05) : fiches, familles, prix (RG-P01), photos (§9.8).
return [
    'attributes' => [
        'sku' => 'code article',
        'name' => 'désignation',
        'short_name' => 'désignation courte',
        'family_id' => 'famille',
        'format' => 'format',
        'sales_unit_label' => 'libellé d\'UV',
        'units_per_sales_unit' => 'unités par UV',
        'sales_units_per_pallet' => 'UV par palette',
        'ean' => 'code EAN',
        'vat_rate' => 'taux de TVA',
        'is_active' => 'statut',
        'is_new' => 'badge « Nouveau »',
        'is_featured' => 'badge « Mis en avant »',
        'sort_order' => 'ordre d\'affichage',
    ],
    'sku_format' => 'Le code article ne peut contenir que des lettres, des chiffres, des tirets et des tirets bas (30 caractères au plus).',
    'sku_immutable' => 'Le code article ne peut pas être modifié : il sert de clé aux fichiers Excel et à l\'ERP.',
    'ean_format' => 'Le code EAN doit comporter de 8 à 14 chiffres.',
    'invalid_identifier' => 'Identifiant invalide.',
    'invalid_boolean' => 'Valeur attendue : 1 ou 0.',

    'families' => [
        'attributes' => [
            'code' => 'code',
            'name' => 'libellé',
            'parent_id' => 'famille parente',
            'sort_order' => 'ordre d\'affichage',
        ],
        'code_format' => 'Le code ne peut contenir que des lettres, des chiffres, des tirets et des tirets bas (20 caractères au plus).',
        'code_immutable' => 'Le code d\'une famille ne peut pas être modifié : il sert de clé aux fichiers Excel.',
        'parent_cycle' => 'Une famille ne peut pas être rattachée à elle-même ni à l\'une de ses sous-familles.',
    ],

    'prices' => [
        'attributes' => [
            'price_ht' => 'prix HT',
            'valid_from' => 'date d\'effet',
        ],
        'amount_format' => 'Le prix HT doit être un montant positif, avec 2 décimales au plus.',
    ],

    'photos' => [
        'attributes' => [
            'photo' => 'photo',
            'main' => 'photo principale',
        ],
        'too_big' => 'La photo ne doit pas dépasser :max Mo.',
        'type' => 'La photo doit être une image JPEG, PNG ou WebP.',
        'unreadable' => 'Le fichier n\'a pas pu être lu.',
        'invalid' => 'Ce fichier n\'est pas une image valide.',
        'dimensions' => 'L\'image est trop grande : :max millions de pixels au plus.',
        'too_detailed' => 'Cette image est trop détaillée pour être enregistrée : réduisez sa résolution et réessayez.',
        'limit' => 'Ce produit a déjà :max photos : supprimez-en une avant d\'en ajouter une autre.',
        'main_only' => 'Seule la désignation de la photo principale est possible (main : true).',
    ],

    // Entrées du journal d'audit (journal « referentials ») pour les photos.
    'audit' => [
        'photo_added' => 'Photo produit ajoutée',
        'photo_main' => 'Photo principale modifiée',
        'photo_deleted' => 'Photo produit supprimée',
    ],
];
