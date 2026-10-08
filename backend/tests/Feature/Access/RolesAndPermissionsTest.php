<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

// Extraits de la matrice des droits (§3.2).
it('grants each role the rights of the specification', function (Permission $permission, array $granted) {
    foreach (Role::cases() as $role) {
        $user = userWithRole($role);

        expect($user->can($permission->value))->toBe(in_array($role, $granted, true), "{$role->value} / {$permission->value}");
    }
})->with([
    'accounts' => [Permission::UserManage, [Role::Admin]],
    'client password reset' => [Permission::UserResetClientPassword, [Role::Admin, Role::Regional]],
    'quota entry and publication' => [Permission::QuotaPublish, [Role::Adv]],
    'quota reading' => [Permission::QuotaView, [Role::Admin, Role::Adv, Role::Regional]],
    'limit entry' => [Permission::LimitEdit, [Role::Regional]],
    'ordering' => [Permission::OrderCreate, [Role::Client]],
    'validation' => [Permission::OrderValidate, [Role::Regional]],
    'preparation and shipping' => [Permission::OrderProcess, [Role::Adv]],
    'exceptional cancellation' => [Permission::OrderCancelValidated, [Role::Adv]],
    'cutoff extension' => [Permission::DayExtendCutoff, [Role::Admin, Role::Adv]],
    'dashboards' => [Permission::DashboardView, [Role::Admin, Role::Adv, Role::Regional]],
    'settings' => [Permission::SettingsManage, [Role::Admin]],
    'audit' => [Permission::AuditView, [Role::Admin]],
]);

it('keeps the Admin out of the operational flow (separation of duties)', function () {
    $admin = admin();

    foreach ([Permission::QuotaEdit, Permission::QuotaPublish, Permission::LimitEdit, Permission::OrderCreate, Permission::OrderValidate, Permission::OrderProcess] as $permission) {
        expect($admin->can($permission->value))->toBeFalse($permission->value);
    }
});

it('creates every permission and role of the catalog', function () {
    expect(PermissionModel::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all())
        ->and(RoleModel::query()->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'adv', 'client', 'regional']);
});

it('can be replayed without removing adjustments made by an Admin', function () {
    $adv = RoleModel::findByName('adv');
    $adv->revokePermissionTo('dashboard.view');
    $adv->givePermissionTo('product.manage');

    (new RolesAndPermissionsSeeder)->run();
    (new RolesAndPermissionsSeeder)->run();

    $adv->refresh();
    expect($adv->hasPermissionTo('dashboard.view'))->toBeFalse()
        ->and($adv->hasPermissionTo('product.manage'))->toBeTrue()
        ->and(PermissionModel::query()->count())->toBe(count(Permission::cases()));
});

it('grants a permission introduced by a later release to its default roles', function () {
    PermissionModel::findByName('order.validate')->delete();

    (new RolesAndPermissionsSeeder)->run();

    expect(RoleModel::findByName('regional')->hasPermissionTo('order.validate'))->toBeTrue()
        ->and(RoleModel::findByName('adv')->hasPermissionTo('order.validate'))->toBeFalse();
});

it('lists the permissions in the profile', function () {
    $this->actingAs(userWithRole(Role::Regional));

    $permissions = $this->getJson('/api/v1/auth/me')->assertOk()->json('permissions');

    expect($permissions)->toContain('order.validate', 'limit.edit')->not->toContain('quota.publish');
});
