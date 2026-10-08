<?php

return [

    /*
    | Fuseau de référence de la logique métier (H-10). Les dates sont stockées
    | en UTC (app.timezone) ; journées et heures limites se calculent ici.
    */
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Africa/Algiers'),

    /*
    | Limitation de débit (§11.1).
    */
    'rate_limits' => [
        'api_per_minute' => (int) env('API_RATE_LIMIT', 120),
        'login_per_minute' => (int) env('LOGIN_RATE_LIMIT', 10),
    ],

    /*
    | Valeurs par défaut des paramètres généraux (EF-ADM-04). Les valeurs
    | modifiées par l'Admin sont stockées dans la table `settings`.
    */
    'settings' => [
        'cutoffs.distribution' => '10:00',
        'cutoffs.ordering' => '13:00',
        'cutoffs.validation' => '15:00',
        'cutoffs.closing' => '23:00',
        'cutoffs.validation_blocking' => false,
        'calendar.working_days' => [0, 1, 2, 3, 4],
        'limits.no_limit_policy' => 'FREE',
        'orders.max_per_day' => 1,
        'orders.min_amount' => null,
        'sessions.remember_days' => 30,
        'sessions.staff_absolute_hours' => 12,
        'sessions.staff_idle_minutes' => 120,
        'passwords.min_length' => 8,
        'passwords.min_length_staff' => 12,
        'auth.max_failed_logins' => 5,
        'auth.lockout_minutes' => 15,
        'notifications.email_enabled' => false,
        'notifications.quiet_start' => '21:00',
        'notifications.quiet_end' => '06:00',
    ],

];
