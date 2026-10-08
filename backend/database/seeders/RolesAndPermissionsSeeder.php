<?php

namespace Database\Seeders;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Synchronise le catalogue des rôles et permissions (§3.2). Rejouable à
 * chaque déploiement : crée ce qui manque, accorde aux rôles existants les
 * seules permissions nouvellement créées, et ne retire jamais un droit
 * (les ajustements faits par l'Admin sont conservés).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $created = [];
        foreach (Permission::cases() as $permission) {
            $model = PermissionModel::query()->firstOrCreate([
                'name' => $permission->value,
                'guard_name' => 'web',
            ]);
            if ($model->wasRecentlyCreated) {
                $created[] = $permission->value;
            }
        }

        foreach (Role::cases() as $role) {
            $model = RoleModel::query()->firstOrCreate([
                'name' => $role->value,
                'guard_name' => 'web',
            ]);

            $defaults = array_map(fn (Permission $p) => $p->value, $role->defaultPermissions());
            $grant = $model->wasRecentlyCreated ? $defaults : array_values(array_intersect($defaults, $created));

            if ($grant !== []) {
                $model->givePermissionTo($grant);
            }
        }

        $registrar->forgetCachedPermissions();
    }
}
