<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment(['local', 'testing', 'recette'])) {
            $this->call([DemoUsersSeeder::class, DemoReferentialsSeeder::class]);
        }
    }
}
