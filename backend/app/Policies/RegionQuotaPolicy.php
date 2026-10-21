<?php

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\User;

/**
 * Droits sur les quotas région (§3.2 ; contrat du lot 3, §2). La matrice
 * (EF-ADV-02) est réservée au back-office : lecture avec `quota.view`,
 * brouillons, copie et import avec `quota.edit`, publication avec
 * `quota.publish`. Le régional, qui a aussi `quota.view`, ne lit que les
 * quotas publiés de ses régions (/regional/quotas, §7.6). Le périmètre de
 * l'ADV (RG-S05) est appliqué par QuotaBook et par les requêtes de lecture.
 */
class RegionQuotaPolicy
{
    /** Matrice produit × région, valeurs publiées et brouillons. */
    public function viewMatrix(User $actor): bool
    {
        return $this->backOffice($actor) && $actor->can(Permission::QuotaView->value);
    }

    /** Saisie de brouillons et copie depuis une autre journée (RG-Q03). */
    public function edit(User $actor): bool
    {
        return $this->backOffice($actor) && $actor->can(Permission::QuotaEdit->value);
    }

    /** Publication (RG-Q04). */
    public function publish(User $actor): bool
    {
        return $this->backOffice($actor) && $actor->can(Permission::QuotaPublish->value);
    }

    /** Quotas publiés des régions du régional (titulaire ou suppléant). */
    public function viewPublished(User $actor): bool
    {
        return $actor->hasAppRole(Role::Regional) && $actor->can(Permission::QuotaView->value);
    }

    /** ADV ou Admin. */
    private function backOffice(User $actor): bool
    {
        return $actor->appRole()?->isBackOffice() ?? false;
    }
}
