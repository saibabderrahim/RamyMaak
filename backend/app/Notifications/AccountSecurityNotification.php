<?php

namespace App\Notifications;

use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * N-15 (§7.8, contrat du lot 8 I-111) : mot de passe du compte réinitialisé
 * par l'Admin, par un régional (comptes clients, EF-AUTH-05) ou depuis le
 * serveur (`ramymaak:reset-password`). Dans l'application seulement (pas de
 * push, l'e-mail reste reporté). « Mot de passe réinitialisé » / « Votre mot
 * de passe a été réinitialisé le 08/10/2026 à 09:12. … » Un appui ouvre le
 * profil. Une notification par réinitialisation.
 */
final class AccountSecurityNotification extends AppNotification
{
    /** Réinitialisation depuis le serveur. */
    public const BY_CONSOLE = 'console';

    /** Réinitialisation par l'Admin (gestion des comptes). */
    public const BY_ADMIN = 'admin';

    /** Réinitialisation par un régional (compte client de son périmètre). */
    public const BY_REGIONAL = 'regional';

    /** Instant de la réinitialisation, UTC (microsecondes comprises). */
    public readonly string $resetAt;

    /** `$by` : BY_CONSOLE, BY_ADMIN ou BY_REGIONAL. */
    public function __construct(CarbonInterface $resetAt, public readonly string $by = self::BY_CONSOLE)
    {
        $this->resetAt = $resetAt->toImmutable()->utc()->format('Y-m-d\TH:i:s.u');
    }

    public function type(): NotificationType
    {
        return NotificationType::AccountSecurity;
    }

    public function title(): string
    {
        return __('notifications.account_security.password_reset.title');
    }

    public function body(): string
    {
        $local = BusinessClock::toLocal(CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.u', $this->resetAt, 'UTC'));

        return __('notifications.account_security.password_reset.body.'.$this->by, [
            'date' => $local->format('d/m/Y'),
            'time' => $local->format('H:i'),
        ]);
    }

    public function url(): ?string
    {
        return '/profil';
    }

    protected function uniqueKey(): string
    {
        return $this->type()->value.'|password_reset|at:'.$this->resetAt;
    }
}
