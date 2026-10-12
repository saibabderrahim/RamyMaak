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
// Paramètres reçus en texte : un identifiant non numérique est refusé (403), sans erreur 500.
Broadcast::channel('user.{userId}', fn (User $user, string $userId) => ctype_digit($userId) && $user->id === (int) $userId);

// Une région pour une journée : régionaux de la région ce jour-là, ADV et Admin.
Broadcast::channel('region-day.{regionId}.{day}', function (User $user, string $regionId, string $day) {
    $date = BusinessClock::parseDay($day);

    return $date !== null
        && ctype_digit($regionId)
        && $user->can(Permission::QuotaView->value)
        && ! $user->hasAppRole(Role::Client)
        && app(Perimeter::class)->canAccessRegion($user, (int) $regionId, $date);
});

// Un client : ses bornes et, à partir du lot 5, ses commandes (comptes de ce client).
Broadcast::channel('client.{clientId}', fn (User $user, string $clientId) => ctype_digit($clientId)
    && $user->hasAppRole(Role::Client)
    && $user->client_id === (int) $clientId);

// Suivi de la journée côté ADV (et Admin, en lecture).
Broadcast::channel('adv.{day}', fn (User $user, string $day) => BusinessClock::parseDay($day) !== null
    && ($user->hasAppRole(Role::Adv) || $user->hasAppRole(Role::Admin)));
