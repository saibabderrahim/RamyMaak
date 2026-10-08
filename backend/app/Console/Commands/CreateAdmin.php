<?php

namespace App\Console\Commands;

use App\Domain\Access\Role;
use App\Domain\Auth\TemporaryPassword;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Création du premier compte Admin lors de la mise en service. Le mot de
 * passe temporaire est affiché une seule fois et devra être changé à la
 * première connexion (EF-AUTH-02).
 */
class CreateAdmin extends Command
{
    protected $signature = 'ramymaak:create-admin {username : Identifiant de connexion} {--name= : Nom affiché}';

    protected $description = 'Crée un compte Admin avec un mot de passe temporaire';

    public function handle(): int
    {
        $username = User::normalizeUsername((string) $this->argument('username'));

        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,49}$/', $username) !== 1) {
            $this->error('Identifiant invalide : 3 à 50 caractères parmi lettres, chiffres, « . », « - » et « _ ».');

            return self::FAILURE;
        }

        if (User::query()->where('username', $username)->exists()) {
            $this->error("L'identifiant « {$username} » est déjà utilisé.");

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $password = TemporaryPassword::generate();

        DB::transaction(function () use ($username, $password): void {
            $user = User::create([
                'username' => $username,
                'name' => (string) ($this->option('name') ?: $username),
                'password' => $password,
                'must_change_password' => true,
            ]);
            $user->assignRole(Role::Admin->value);
        });

        $this->info("Compte Admin « {$username} » créé.");
        $this->line("Mot de passe temporaire : {$password}");
        $this->line('Il devra être changé à la première connexion.');

        return self::SUCCESS;
    }
}
