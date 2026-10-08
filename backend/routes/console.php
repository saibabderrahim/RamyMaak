<?php

use Illuminate\Support\Facades\Schedule;

// Purge du journal d'audit au-delà de la durée de conservation (§12).
Schedule::command('activitylog:clean --force')
    ->dailyAt('02:30')
    ->timezone(config('ramymaak.business_timezone'))
    ->withoutOverlapping();
