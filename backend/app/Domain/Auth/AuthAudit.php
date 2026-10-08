<?php

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Journal des connexions (EF-AUTH-08) et des événements de sécurité des
 * comptes, dans le journal d'audit (log `auth`).
 */
final class AuthAudit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $event,
        string $description,
        Request $request,
        ?User $subject = null,
        ?User $causer = null,
        array $properties = [],
    ): void {
        activity('auth')
            ->event($event)
            ->when($subject !== null, fn ($logger) => $logger->performedOn($subject))
            ->causedBy($causer ?? $subject)
            ->withProperties([
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
                ...$properties,
            ])
            ->log($description);
    }
}
