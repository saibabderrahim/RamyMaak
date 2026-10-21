<?php

// Quotas région (§5.2, EF-ADV-02) et désactivation programmée des produits (RG-Q07).
return [
    'attributes' => [
        'cells' => 'cellules',
        'cells.*' => 'cellule',
        'cells.*.region_id' => 'région',
        'cells.*.product_id' => 'produit',
        'cells.*.quantity' => 'quantité',
        'source_day' => 'journée source',
        'mode' => 'mode',
        'region_ids' => 'régions',
        'region_ids.*' => 'région',
        'all' => 'toutes les régions',
        'day' => 'journée',
    ],
    'day_not_open' => 'La journée du :day est clôturée : les quotas ne sont plus modifiables.',
    'region_unknown' => 'Région inconnue ou inactive.',
    'region_out_of_perimeter' => 'Région hors de votre périmètre.',
    'product_unknown' => 'Produit inconnu ou inactif : il ne peut pas recevoir de quota.',
    'empty_first_publication' => 'Région :region : aucun quota supérieur à 0 à publier.',
    'below_validated' => 'Le quota ne peut pas descendre sous la quantité déjà validée.',
    'duplicate_cell' => 'Cette région et ce produit figurent déjà dans la requête (élément :index).',
    'quantity_format' => 'La quantité doit être un nombre entier de 0 à :max, ou vide pour annuler le brouillon.',
    'invalid_day' => 'Date attendue au format AAAA-MM-JJ.',
    'source_day_unknown' => 'Aucune journée n\'a été ouverte le :day.',
    'source_day_same' => 'La journée source doit être différente de la journée à remplir.',
    'publish_choice' => 'Choisissez des régions ou toutes les régions.',
    'publish_both' => 'Choisissez des régions ou toutes les régions, pas les deux.',
    'audit' => [
        'published' => 'Quotas publiés',
        'updated' => 'Quotas modifiés après publication',
    ],
    // RG-Q07 : journal « referentials » de la fiche produit.
    'deactivation' => [
        'scheduled' => 'Désactivation du produit programmée (quota publié sur la journée en cours)',
        'cancelled' => 'Désactivation programmée du produit annulée',
        'applied' => 'Désactivation programmée du produit appliquée',
    ],
];
