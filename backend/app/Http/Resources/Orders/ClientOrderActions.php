<?php

namespace App\Http\Resources\Orders;

use App\Domain\Orders\ClientDayStatus;
use App\Domain\Orders\OrderingState;
use App\Domain\Orders\OrderingWindow;
use App\Domain\Orders\OrderStatus;
use App\Domain\Referentials\ClientStatus;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Droits d'action du client sur ses commandes (contrat du lot 5, §3) :
 *
 * - `can_modify` : commande envoyée, fenêtre de commande de sa région
 *   ouverte (OrderingWindow), client actif ;
 * - `can_cancel` : mêmes conditions, client bloqué compris (I-53) ;
 * - `modifiable_until` : heure limite applicable tant que la commande est
 *   envoyée.
 *
 * La fenêtre est celle de la journée et de la région figées de la commande,
 * évaluée une fois par couple (journée, région) : une page de commandes
 * coûte au plus une requête par couple, et rien pour les autres statuts.
 * Le serveur reste juge : ClientOrders rejoue tous les contrôles.
 */
final class ClientOrderActions
{
    /** @var array<int, BusinessDay> journées complètes, par identifiant */
    private array $days = [];

    /** @var array<string, OrderingState> « journée:région » → fenêtre */
    private array $windows = [];

    public function __construct(
        private readonly Client $client,
        private readonly OrderingWindow $window,
    ) {}

    public static function for(Client $client): self
    {
        return new self($client, app(OrderingWindow::class));
    }

    /** Fenêtre déjà évaluée (accueil du client) : réutilisée pour les commandes de sa journée et de sa région. */
    public function remember(OrderingState $state): self
    {
        if ($state->businessDay !== null) {
            $this->rememberDay($state->businessDay);
            $this->windows[$state->businessDay->id.':'.$state->regionId] = $state;
        }

        return $this;
    }

    /** Journée chargée en entier (statut, heures limites) : évite de la relire. */
    public function rememberDay(BusinessDay $day): self
    {
        $this->days[$day->id] = $day;

        return $this;
    }

    /**
     * @return array{can_modify: bool, can_cancel: bool}
     */
    public function rights(Order $order): array
    {
        $open = $this->window($order)?->status === ClientDayStatus::OrderingOpen;

        return [
            'can_modify' => $open && $this->client->status === ClientStatus::Active,
            'can_cancel' => $open,
        ];
    }

    /** Heure limite applicable (ISO 8601 UTC) tant que la commande est envoyée, sinon null. */
    public function modifiableUntil(Order $order): ?string
    {
        $cutoff = $this->window($order)?->cutoffAt;

        return $cutoff === null ? null : CarbonImmutable::instance($cutoff)->utc()->toIso8601String();
    }

    /** Fenêtre de commande de la journée et de la région de la commande ; null hors statut « Envoyée ». */
    private function window(Order $order): ?OrderingState
    {
        if ($order->status !== OrderStatus::Submitted) {
            return null;
        }

        return $this->windows[$order->business_day_id.':'.$order->region_id]
            ??= $this->window->forRegion($this->day($order->business_day_id), $order->region_id);
    }

    private function day(int $id): BusinessDay
    {
        return $this->days[$id] ??= BusinessDay::query()->findOrFail($id);
    }
}
