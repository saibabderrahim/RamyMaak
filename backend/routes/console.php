<?php

use Illuminate\Support\Facades\Schedule;

// Purge du journal d'audit au-delà de la durée de conservation (§12).
Schedule::command('activitylog:clean --force')
    ->dailyAt('02:30')
    ->timezone(config('ramymaak.business_timezone'))
    ->withoutOverlapping();

// Changements de région des clients effectifs à la journée suivante (RG-S04).
Schedule::command('ramymaak:apply-region-changes')
    ->dailyAt('00:05')
    ->timezone(config('ramymaak.business_timezone'))
    ->withoutOverlapping();

// Clôture des journées à leur heure de clôture (§4.4, §9.7) : rejouable.
Schedule::command('ramymaak:close-days')
    ->everyMinute()
    ->withoutOverlapping();

// Désactivations de produits programmées à la journée suivante (RG-Q07).
Schedule::command('ramymaak:apply-product-deactivations')
    ->dailyAt('00:01')
    ->timezone(config('ramymaak.business_timezone'))
    ->withoutOverlapping();
