<?php

namespace App\Console\Commands;

use App\Domain\Auth\AuthAudit;
use App\Domain\Auth\SessionRevoker;
use App\Domain\Auth\TemporaryPassword;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Réinitialisation de secours, depuis le serveur, du mot de passe d'un
 * compte (par exemple l'Admin qui a perdu le sien) : mot de passe
 * temporaire affiché une seule fois, à changer à la connexion suivante
 * (EF-AUTH-02), compte déverrouillé et sessions fermées (EF-AUTH-05).
 */
class ResetPassword extends Command
{
    protected $signature = 'ramymaak:reset-password {username : Identifiant de connexion}';

    protected $description = 'Réinitialise le mot de passe d\'un compte avec un mot de passe temporaire';

    public function handle(AuthAudit $audit, SessionRevoker $revoker): int
    {
        $username = User::normalizeUsername((string) $this->argument('username'));
        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error("Aucun compte « {$username} ».");

            return self::FAILURE;
        }

        $password = TemporaryPassword::generate();
        DB::transaction(function () use ($user, $password, $audit, $revoker): void {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => true,
                'failed_logins' => 0,
                'locked_until' => null,
            ])->save();
            $revoker->revokeAll($user);
            $audit->record('password.reset', 'Mot de passe réinitialisé depuis le serveur', request(), subject: $user, properties: ['source' => 'console']);
            // N-15 (I-111) : le compte est prévenu dans l'application.
            (new AccountSecurityNotification(now(), AccountSecurityNotification::BY_CONSOLE))->sendOnce([$user]);
        });

        $this->info("Mot de passe de « {$username} » réinitialisé ; ses sessions sont fermées.");
        if (! $user->is_active) {
            $this->warn('Ce compte est désactivé : le réactiver depuis Comptes pour qu\'il puisse se connecter.');
        }
        $this->line("Mot de passe temporaire : {$password}");
        $this->line('Il devra être changé à la connexion suivante.');

        return self::SUCCESS;
    }
}
