<?php

// Régions, dépôts et affectations (EF-ADM-03, §3.3, §3.4).
return [
    'attributes' => [
        'code' => 'code',
        'name' => 'nom',
        'address' => 'adresse',
        'depot_id' => 'dépôt',
        'is_active' => 'statut',
        'user_id' => 'utilisateur',
        'kind' => 'nature de l\'affectation',
        'starts_on' => 'date de début',
        'ends_on' => 'date de fin',
        'replace' => 'remplacement',
    ],
    'code_format' => 'Le code ne peut contenir que des lettres majuscules, des chiffres, des tirets et des tirets bas (2 à 20 caractères).',
    'code_immutable' => 'Le code ne peut pas être modifié : il sert de clé dans les fichiers Excel.',
    'depot_inactive' => 'Ce dépôt est désactivé.',
    'has_clients' => '{1} La région compte encore un client actif ou bloqué, aujourd\'hui ou à partir de demain : changez-le de région ou désactivez-le d\'abord.|[2,*] La région compte encore :count clients actifs ou bloqués, aujourd\'hui ou à partir de demain : changez-les de région ou désactivez-les d\'abord.',
    'user_inactive' => 'Ce compte est désactivé.',
    'user_role' => 'Une affectation « :kind » exige un compte de rôle :role.',
    'deputy_needs_end' => 'Une suppléance exige une date de fin.',
    'ends_before_start' => 'La date de fin doit être postérieure ou égale à la date de début.',
    'starts_in_past' => 'Une affectation ne peut pas commencer dans le passé.',
    'titular_taken' => '{1} La région a déjà un titulaire sur cette période (:name). Confirmez le remplacement pour mettre fin à son affectation.|[2,*] La région a déjà des titulaires sur cette période (:name). Confirmez le remplacement pour mettre fin à leurs affectations.',
    'duplicate' => 'Cet utilisateur a déjà une affectation « :kind » sur cette région pour une période qui chevauche celle-ci.',
    'already_revoked' => 'Cette affectation est déjà terminée.',
    'active_filter' => 'Le filtre « active » attend 1 ou 0.',
];
