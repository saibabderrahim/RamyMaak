<?php

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Un compte par rôle pour le développement et la recette. Jamais exécuté en
 * production (voir DatabaseSeeder).
 */
class DemoUsersSeeder extends Seeder
{
    public const PASSWORD = 'RamyMaak2026demo';

    public function run(): void
    {
        $accounts = [
            ['username' => 'admin', 'name' => 'Administrateur démo', 'role' => Role::Admin],
            ['username' => 'adv', 'name' => 'ADV démo', 'role' => Role::Adv],
            ['username' => 'regional', 'name' => 'Régional démo', 'role' => Role::Regional],
            ['username' => 'c0012', 'name' => 'Client A (démo)', 'role' => Role::Client],
        ];

        foreach ($accounts as $account) {
            $user = User::query()->firstOrNew(['username' => $account['username']]);
            $user->fill([
                'name' => $account['name'],
                'password' => self::PASSWORD,
                'is_active' => true,
                'must_change_password' => false,
            ])->save();
            $user->syncRoles([$account['role']->value]);
        }
    }
}
