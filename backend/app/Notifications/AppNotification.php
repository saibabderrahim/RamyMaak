<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Ramsey\Uuid\Uuid;

/**
 * Notification du centre de notifications (EF-NOT-01) : enregistrée en base
 * (lu / non lu) et diffusée sur le canal privé `user.{id}` (§9.6). Contenu
 * minimal, sans donnée commerciale (EF-NOT-03) : un titre, un texte et
 * l'écran à ouvrir. Le push arrive au lot 7, l'e-mail est reporté.
 *
 * Rejouable sans double effet (§9.7) : l'identifiant est dérivé d'une clé
 * d'unicité (événement, journée régionale, version ou heure) et du
 * destinataire ; une notification déjà enregistrée n'est pas renvoyée.
 */
abstract class AppNotification extends Notification
{
    /** Espace de noms des identifiants déterministes (UUID v5). */
    private const ID_NAMESPACE = '8f0c5a52-6c1e-4f53-9d0a-2b7f6e4d9c31';

    abstract public function type(): NotificationType;

    abstract public function title(): string;

    abstract public function body(): string;

    /** Chemin de la PWA ouvert par un appui sur la notification (EF-NOT-03), ou null. */
    abstract public function url(): ?string;

    /** Clé d'unicité de l'envoi, hors destinataire. */
    abstract protected function uniqueKey(): string;

    /**
     * Base d'abord : une tâche concurrente qui perd la course s'arrête sur la
     * clé primaire, avant la diffusion.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** Colonne `type` de la table notifications. */
    public function databaseType(object $notifiable): string
    {
        return $this->type()->value;
    }

    /** Membre `type` de la diffusion sur `user.{id}`. */
    public function broadcastType(): string
    {
        return $this->type()->value;
    }

    /**
     * Données enregistrées (centre de notifications).
     *
     * @return array{type: string, code: ?string, title: string, body: string, url: ?string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type()->value,
            'code' => $this->type()->code(),
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
        ];
    }

    /**
     * Même contenu que la ressource Notification de l'API (Laravel ajoute
     * `id` et `type`) : la PWA peut l'afficher sans relire la liste.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            ...$this->toArray($notifiable),
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Identifiant de la notification pour un destinataire : UUID v5 de la clé
     * d'unicité, signée avec la clé de l'application pour rester non
     * devinable (§12).
     */
    public function idFor(User $recipient): string
    {
        $name = hash_hmac('sha256', $this->uniqueKey().'|user:'.$recipient->id, (string) config('app.key'));

        return Uuid::uuid5(self::ID_NAMESPACE, $name)->toString();
    }

    /**
     * Envoie la notification à chaque destinataire qui ne l'a pas déjà reçue
     * (tâche relancée, événement rejoué).
     *
     * @param  iterable<User>  $recipients
     * @return int nombre de notifications envoyées
     */
    public function sendOnce(iterable $recipients): int
    {
        $sent = 0;
        foreach ($recipients as $recipient) {
            $notification = clone $this;
            $notification->id = $this->idFor($recipient);
            if (DatabaseNotification::query()->whereKey($notification->id)->exists()) {
                continue;
            }

            try {
                $recipient->notifyNow($notification);
                $sent++;
            } catch (UniqueConstraintViolationException) {
                // Envoyée entre-temps par une autre tâche.
                continue;
            }
        }

        return $sent;
    }
}
