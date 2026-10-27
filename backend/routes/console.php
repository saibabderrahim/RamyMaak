<?php

use Illuminate\Support\Facades\Schedule;

// Purge du journal d'audit au-delà de la durée de conservation (§12).
Schedule::command('activitylog:clean --force')
    ->dailyAt('02:30')
    ->timezone(config('ramymaak.business_timezone'))
    ->withoutOverlapping();

// Changements de région des clients effectifs à la journée suivante (RG-S04).
// Verrous courts : un arrêt brutal du planificateur (déploiement) ne bloque
// pas la tâche 24 h (verrou par défaut) ; les tâches sont rejouables.
Schedule::command('ramymaak:apply-region-changes')
    ->dailyAt('00:05')
    ->timezone(config('ramymaak.business_timezone'))
    ->withoutOverlapping(30);

// Clôture des journées à leur heure de clôture (§4.4, §9.7) : rejouable.
Schedule::command('ramymaak:close-days')
    ->everyMinute()
    ->withoutOverlapping(5);

// Rappels N-10 à N-12 et alerte N-13 (contrat du lot 8, §3) : fenêtres sur
// l'heure limite effective de la région, une fois par heure limite (I-107).
Schedule::command('ramymaak:send-reminders')
    ->everyMinute()
    ->withoutOverlapping(5);

// Désactivations de produits programmées à la journée suivante (RG-Q07).
Schedule::command('ramymaak:apply-product-deactivations')
    ->dailyAt('00:01')
    ->timezone(config('ramymaak.business_timezone'))
    ->withoutOverlapping(30);

// Réconciliation nocturne des quotas validés (§5.8, §9.7, I-93) : rapport seul,
// journal d'erreur et code de sortie 1 en cas d'écart, jamais de correction.
// Après la clôture (23:00) et avant la purge du journal d'audit.
Schedule::command('ramymaak:reconcile-quotas')
    ->dailyAt('02:10')
    ->timezone(config('ramymaak.business_timezone'))
    ->withoutOverlapping(30);

// Purge nocturne des abonnements push en échec ou inutilisés et des anciens
// états d'anti-saturation (§9.7, contrat du lot 8 §2.4) : rejouable.
Schedule::command('ramymaak:purge-push-subscriptions')
    ->dailyAt('03:10')
    ->timezone(config('ramymaak.business_timezone'))
    ->withoutOverlapping(30);
