<?php

namespace App\Notifications\Channels;

use App\Domain\Push\PushPayload;
use App\Domain\Push\PushThrottler;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Canal `push` des notifications (contrat du lot 8, §2.3 et §2.4) : rien si
 * le push est indisponible (clés VAPID, `notifications.push_enabled`), pendant
 * la plage sans push (abandonné, pas reporté : I-105) ou sans abonnement ;
 * sinon décision d'anti-saturation (PushThrottler), envoi en tâche de fond.
 *
 * Atteint une seule fois par notification : `sendOnce` ne renvoie pas une
 * notification déjà enregistrée (§9.7).
 */
final class PushChannel
{
    public function __construct(private readonly PushThrottler $throttler) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof AppNotification || ! $notification->type()->pushable()) {
            return;
        }

        try {
            if (! $this->throttler->deliverable($notifiable->id)) {
                return;
            }

            $this->throttler->submit($notifiable, $notification->type(), PushPayload::single($notification, $notifiable));
        } catch (Throwable $e) {
            // Le push n'est qu'un signal : la notification est déjà enregistrée
            // et diffusée, les destinataires suivants sont servis.
            report($e);
        }
    }
}
