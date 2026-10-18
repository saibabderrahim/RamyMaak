<?php

namespace App\Notifications;

use App\Domain\Access\Role;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * Notification portant sur une commande, adressée aux comptes de son client
 * (§7.8 : N-07, N-14) : comptes actifs de rôle client rattachés au client,
 * quel que soit le statut du client (une décision ou une expiration
 * concerne aussi un client bloqué depuis l'envoi, I-53). Un appui ouvre le
 * détail de la commande (EF-NOT-03) ; ni montant ni quantité.
 */
abstract class ClientOrderNotification extends AppNotification
{
    /** Destinataires lus par paquets de cette taille. */
    protected const CHUNK = 100;

    // Pas de « readonly » ici : SerializesModels (file de diffusion) ne sait
    // pas restaurer une propriété en lecture seule déclarée par une classe
    // parente.
    public int $orderId;

    /** Identifiant exposé (`public_id`, ULID). */
    public string $orderPublicId;

    /** Numéro lisible « RMK-AAMMJJ-NNNNN ». */
    public string $number;

    public int $clientId;

    public function __construct(Order $order)
    {
        $this->orderId = $order->id;
        $this->orderPublicId = $order->public_id;
        $this->number = $order->number;
        $this->clientId = $order->client_id;
    }

    /**
     * Comptes à prévenir pour des clients : actifs et de rôle client (un
     * compte passé à un autre rôle garde son client, il n'est plus
     * destinataire).
     *
     * @param  list<int>  $clientIds
     * @return Builder<User>
     */
    public static function recipientsQuery(array $clientIds): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('client_id', $clientIds)
            ->whereHas('roles', fn (Builder $q) => $q->where('name', Role::Client->value));
    }

    /**
     * Comptes du client de la commande, lus par paquets.
     *
     * @return LazyCollection<int, User>
     */
    public function recipients(): LazyCollection
    {
        return static::recipientsQuery([$this->clientId])->lazyById(self::CHUNK);
    }

    public function url(): ?string
    {
        return '/client/commandes/'.$this->orderPublicId;
    }

    protected function keyPrefix(): string
    {
        return $this->type()->value.'|order:'.$this->orderId;
    }
}
