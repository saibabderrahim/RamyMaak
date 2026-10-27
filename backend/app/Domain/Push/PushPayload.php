<?php

namespace App\Domain\Push;

use App\Notifications\AppNotification;
use App\Notifications\NotificationType;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Charge utile d'un push (contrat du lot 8, §2.5) : les textes de la
 * notification enregistrée, jamais de montant ni de donnée commerciale
 * (EF-NOT-03). `tag` est le groupe d'anti-saturation : l'appareil remplace
 * la notification affichée du même groupe.
 *
 * @phpstan-type Payload array{id: ?string, type: string, code: ?string, title: string, body: string, url: string, tag: string, count: int}
 */
final class PushPayload
{
    /** Écran ouvert quand la notification n'en désigne pas. */
    private const FALLBACK_URL = '/notifications';

    /**
     * Push d'une notification.
     *
     * @return Payload
     */
    public static function single(AppNotification $notification, object $notifiable): array
    {
        $data = $notification->toArray($notifiable);

        return self::make($notification->id, $data, $notification->type()->pushGroup());
    }

    /**
     * Push d'une notification déjà enregistrée (seule notification comptée
     * d'une fenêtre d'anti-saturation).
     *
     * @return Payload
     */
    public static function stored(DatabaseNotification $notification, string $group): array
    {
        return self::make((string) $notification->id, (array) $notification->data, $group);
    }

    /**
     * Push groupé : `count` notifications comptées pendant la fenêtre
     * d'anti-saturation (EF-NOT-02, I-109).
     *
     * @return Payload
     */
    public static function grouped(string $group, int $count, ?string $lastTitle): array
    {
        $type = NotificationType::tryFrom($group);
        $key = $type === NotificationType::OrderSubmitted ? 'order_submitted' : 'default';

        return [
            'id' => null,
            'type' => $type?->value ?? $group,
            'code' => $type?->code(),
            'title' => trans_choice("push.grouped.{$key}.title", $count, ['count' => $count]),
            'body' => $key === 'order_submitted' ? __('push.grouped.order_submitted.body') : (string) $lastTitle,
            'url' => $key === 'order_submitted' ? '/regional/commandes' : self::FALLBACK_URL,
            'tag' => $group,
            'count' => $count,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  données enregistrées de la notification
     * @return Payload
     */
    private static function make(?string $id, array $data, string $group): array
    {
        return [
            'id' => $id,
            'type' => (string) ($data['type'] ?? $group),
            'code' => $data['code'] ?? null,
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'url' => (string) ($data['url'] ?? self::FALLBACK_URL),
            'tag' => $group,
            'count' => 1,
        ];
    }
}
