<?php

return [

    /*
    | Fuseau de référence de la logique métier (H-10). Les dates sont stockées
    | en UTC (app.timezone) ; journées et heures limites se calculent ici.
    */
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Africa/Algiers'),

    /*
    | Documents et exports (lot 6) : dossier de travail des fichiers Excel
    | (celui du système par défaut ; les tests en parallèle en isolent un).
    */
    'documents' => [
        'temp_dir' => env('DOCUMENTS_TEMP_DIR'),
    ],

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
        'notifications.push_enabled' => true,
        'notifications.quiet_start' => '21:00',
        'notifications.quiet_end' => '06:00',
    ],

    /*
    | Notifications push (lot 8, contrat §2). Clés VAPID : configuration
    | `webpush` du paquet (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT).
    | Le serveur n'écrit qu'aux services push de cette liste (I-104) ; « * »
    | en tête de nom couvre les sous-domaines.
    */
    'push' => [
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'PUSH_ALLOWED_HOSTS',
            'fcm.googleapis.com,android.googleapis.com,*.push.services.mozilla.com,*.notify.windows.com,*.push.apple.com',
        ))))),
        // Fenêtre d'anti-saturation par utilisateur et par groupe (EF-NOT-02, I-109).
        'group_window_minutes' => 5,
        // Abonnements gardés par compte (appareils), les plus récemment utilisés.
        'max_per_user' => 10,
        // Durée de vie d'un push non distribué (appareil éteint), en secondes.
        'ttl' => 6 * 3600,
        // Purge nocturne (§9.7).
        'max_failures' => 3,
        'unused_days' => 60,
        // Transport : « webpush » (services push réels) ou « log » (une ligne
        // JSON par message dans storage/logs/push.log, sans envoi : recette).
        'transport' => env('PUSH_TRANSPORT', 'webpush'),
    ],

    /*
    | Rappels du planificateur (§4.3, contrat du lot 8 §3, I-108) : minutes
    | avant l'heure limite effective de la région.
    */
    'reminders' => [
        'distribution' => 30,
        'ordering' => 60,
        'validation' => 30,
    ],

];
