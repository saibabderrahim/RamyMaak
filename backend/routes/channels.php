<?php

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\Broadcast;

/*
| Canaux temps réel privés (§9.6). Chaque accès est autorisé ici selon le
| rôle et le périmètre (RG-S02) ; un événement ne porte qu'un identifiant et
| un résumé, l'application relit ensuite la donnée par l'API.
*/

// Notifications personnelles.
Broadcast::channel('user.{userId}', fn (User $user, int $userId) => $user->id === $userId);

// Une région pour une journée : régionaux de la région ce jour-là, ADV et Admin.
Broadcast::channel('region-day.{regionId}.{day}', function (User $user, int $regionId, string $day) {
    $date = BusinessClock::parseDay($day);

    return $date !== null
        && $user->can(Permission::QuotaView->value)
        && ! $user->hasAppRole(Role::Client)
        && app(Perimeter::class)->canAccessRegion($user, $regionId, $date);
});

// Suivi de la journée côté ADV (et Admin, en lecture).
Broadcast::channel('adv.{day}', fn (User $user, string $day) => BusinessClock::parseDay($day) !== null
    && ($user->hasAppRole(Role::Adv) || $user->hasAppRole(Role::Admin)));
