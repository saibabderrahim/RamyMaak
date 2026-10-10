<?php

namespace App\Policies;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Droits sur les journées (§3.2, ligne « Journée » ; contrat du lot 3, §2) :
 * réservées au back-office (ADV, Admin). Lecture avec `quota.view` (le
 * régional, qui l'a aussi, passe par /regional/quotas) ; ouverture, heures
 * limites et date de livraison avec `day.manage` ; prolongation par région
 * (RG-A05) avec `day.extend-cutoff`, dans le périmètre de l'ADV ce jour-là
 * (RG-S05). Une région hors périmètre ou inactive répond 404.
 */
class BusinessDayPolicy
{
    public function __construct(private readonly Perimeter $perimeter) {}

    /** Calendrier des journées (EF-ADV-01). */
    public function viewAny(User $actor): bool
    {
        return $this->backOffice($actor) && $actor->can(Permission::QuotaView->value);
    }

    public function view(User $actor, BusinessDay $day): bool
    {
        return $this->viewAny($actor);
    }

    /** Ouverture d'une journée. */
    public function create(User $actor): bool
    {
        return $this->backOffice($actor) && $actor->can(Permission::DayManage->value);
    }

    /** Heures limites et date de livraison de la journée (§4.3). */
    public function update(User $actor, BusinessDay $day): bool
    {
        return $this->create($actor);
    }

    /** Prolongation d'une heure limite pour une région (RG-A05). */
    public function extendCutoff(User $actor, BusinessDay $day, Region $region): Response
    {
        if (! $this->backOffice($actor) || ! $actor->can(Permission::DayExtendCutoff->value)) {
            return Response::deny();
        }

        return $region->is_active && $this->perimeter->canAccessRegion($actor, $region->id, $day->day)
            ? Response::allow()
            : Response::denyAsNotFound(__('errors.NOT_FOUND'));
    }

    /** ADV ou Admin : le régional et le client n'ont pas accès aux journées ADV. */
    private function backOffice(User $actor): bool
    {
        return $actor->appRole()?->isBackOffice() ?? false;
    }
}
