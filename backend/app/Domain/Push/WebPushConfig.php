<?php

namespace App\Domain\Push;

use App\Domain\Settings\Settings;

/**
 * Disponibilité du push (contrat du lot 8, §2.1) : clés VAPID présentes
 * (configuration `webpush` du paquet) et paramètre général
 * `notifications.push_enabled` actif.
 */
final class WebPushConfig
{
    public static function configured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    public static function available(): bool
    {
        return self::configured() && (bool) app(Settings::class)->get('notifications.push_enabled');
    }

    public static function publicKey(): string
    {
        return (string) config('webpush.vapid.public_key');
    }

    /** Sujet VAPID : `mailto:` ou URL du site ; l'URL de l'application par défaut. */
    public static function subject(): string
    {
        return (string) (config('webpush.vapid.subject') ?: config('app.url'));
    }
}
