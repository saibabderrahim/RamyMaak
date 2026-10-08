<?php

return [
    'attributes' => [
        'username' => 'identifiant',
        'name' => 'nom',
        'email' => 'adresse e-mail',
        'phone' => 'téléphone',
        'role' => 'rôle',
        'client_id' => 'client',
        'is_active' => 'statut',
    ],
    'username_format' => 'L\'identifiant ne peut contenir que des lettres, des chiffres, des points, des tirets et des tirets bas.',
    'client_id_client_only' => 'Seul un compte client peut être rattaché à un client.',
    'cannot_demote_self' => 'Vous ne pouvez pas retirer vos propres droits d\'administration ni désactiver votre compte.',
    'last_admin' => 'La plateforme doit conserver au moins un administrateur actif.',
    'unknown_role' => 'Rôle inconnu.',
];
