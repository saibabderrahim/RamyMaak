<?php

return [
    'unknown' => 'Paramètre inconnu.',
    'cutoff_order' => 'Les heures doivent se succéder : répartition, commande, validation puis clôture.',
    'attributes' => [
        'cutoffs.distribution' => 'heure limite de répartition',
        'cutoffs.ordering' => 'heure limite de commande',
        'cutoffs.validation' => 'heure limite de validation',
        'cutoffs.closing' => 'heure de clôture',
        'cutoffs.validation_blocking' => 'heure limite de validation bloquante',
        'calendar.working_days' => 'jours ouvrés',
        'calendar.working_days.*' => 'jour ouvré',
        'limits.no_limit_policy' => 'politique en l\'absence de borne',
        'orders.max_per_day' => 'nombre de commandes par jour',
        'orders.min_amount' => 'montant minimum de commande',
        'sessions.remember_days' => 'durée « Rester connecté »',
        'sessions.staff_absolute_hours' => 'durée maximale de session (Admin, ADV)',
        'sessions.staff_idle_minutes' => 'expiration après inactivité (Admin, ADV)',
        'passwords.min_length' => 'longueur minimale du mot de passe',
        'passwords.min_length_staff' => 'longueur minimale du mot de passe (Admin, ADV)',
        'auth.max_failed_logins' => 'nombre d\'échecs avant verrouillage',
        'auth.lockout_minutes' => 'durée du verrouillage',
        'notifications.email_enabled' => 'notifications par e-mail',
        'notifications.push_enabled' => 'notifications push',
        'notifications.quiet_start' => 'début de la plage sans push',
        'notifications.quiet_end' => 'fin de la plage sans push',
    ],
];
