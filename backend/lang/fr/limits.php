<?php

// Bornes client et répartition du régional (lot 4, §5.3).
return [
    'client_not_in_region' => 'Client hors de la région ce jour-là.',
    'product_unavailable' => 'Produit sans quota publié dans la région ce jour-là.',
    'above_quota' => 'La borne ne peut pas dépasser le quota de la région (:quota).',
    'below_validated' => 'Borne inférieure aux :validated déjà validés pour ce client.',
    'quotas_not_published' => 'Les quotas de la région :region du :day ne sont pas encore publiés.',
    'no_previous' => 'Aucune répartition n\'a encore été envoyée pour cette région.',
    'nothing_to_publish' => 'Aucune modification à envoyer.',
    'publish_above_quota' => 'Des bornes dépassent le quota de la région, diminué depuis leur saisie : corrigez-les avant l\'envoi.',
    'publish_below_validated' => 'Des bornes sont inférieures aux quantités déjà validées : corrigez-les avant l\'envoi.',
    'audit' => [
        'published' => 'Répartition envoyée aux clients',
        'updated' => 'Bornes modifiées envoyées aux clients',
    ],
];
