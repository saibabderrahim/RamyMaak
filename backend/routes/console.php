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
