<?php

namespace Tests\Feature\Push;

use App\Domain\Settings\Settings;
use App\Notifications\NotificationType;
use Minishlink\WebPush\VAPID;

/**
 * Outils des tests du push (lot 8, volet A) : clés VAPID de test, paramètres,
 * notification de test d'un type quelconque.
 */
final class PushTesting
{
    /** Clé publique P-256 d'un appareil (65 octets en base64url) et son secret (16 octets). */
    public const P256DH = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';

    public const AUTH = 'tBHItJI5svbpez7KI4CCXg';

    public const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/dpH5lCsTSSM:APA91bHqjZxM0VImWWqDRN7U0a3AycjUf4O-byuxb_wJsKRaKvV_iKw56s16ekq6FUqoCF7k2nqWqNYsLYkf';

    /** @var array{publicKey: string, privateKey: string}|null */
    private static ?array $keys = null;

    /** Clés VAPID générées une fois par processus de test. */
    public static function configureVapid(): void
    {
        self::$keys ??= VAPID::createVapidKeys();

        config([
            'webpush.vapid.public_key' => self::$keys['publicKey'],
            'webpush.vapid.private_key' => self::$keys['privateKey'],
            'webpush.vapid.subject' => 'mailto:test@ramymaak.test',
        ]);
    }

    public static function publicKey(): string
    {
        self::configureVapid();

        return self::$keys['publicKey'];
    }

    /**
     * @param  array<string, mixed>  $notifications  ex. ['quiet_start' => '21:00']
     */
    public static function settings(array $notifications): void
    {
        app(Settings::class)->update(['notifications' => $notifications], null);
    }

    public static function notification(
        NotificationType $type,
        string $key,
        string $title = 'Titre de test',
        string $body = 'Texte de test.',
        ?string $url = '/notifications',
    ): TestPushNotification {
        return new TestPushNotification($type, $key, $title, $body, $url);
    }
}
