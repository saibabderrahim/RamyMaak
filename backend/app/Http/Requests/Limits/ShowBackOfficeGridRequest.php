<?php

namespace App\Http\Requests\Limits;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\Region;

/**
 * Grille de répartition en lecture seule pour l'ADV et l'Admin (contrat du
 * lot 4, §2 et §5) : `limit.view`, région active de son périmètre à la date
 * de la journée (RG-S05), sinon 404.
 */
class ShowBackOfficeGridRequest extends DistributionRequest
{
    protected function permission(): Permission
    {
        return Permission::LimitView;
    }

    protected function allowsRole(?Role $role): bool
    {
        return $role?->isBackOffice() ?? false;
    }

    protected function regionId(): ?int
    {
        /** @var Region $region */
        $region = $this->route('region');

        return $region->id;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
