<?php

namespace App\Listeners;

use App\Domain\Days\RegionStaff;
use App\Events\OrderCancelled;
use App\Events\OrderUpdated;
use App\Models\Order;
use App\Notifications\OrderChangedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * N-06 (§7.8, RG-C05) : commande modifiée ou annulée par le client, notifiée
 * aux régionaux de sa région le jour de la commande (RegionStaff). Une
 * modification sans changement ne produit pas d'événement (I-51). Tâche de
 * fond (EF-NOT-06), rejouable sans double envoi (§9.7) : la version de
 * l'événement, pas celle relue, distingue deux modifications rapprochées.
 */
final class NotifyOrderChanged implements ShouldQueue
{
    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly RegionStaff $staff) {}

    public function handle(OrderUpdated|OrderCancelled $event): void
    {
        $order = Order::query()->with(['client', 'businessDay'])->find($event->orderId);
        if ($order === null) {
            return;
        }

        (new OrderChangedNotification($order, $event->version, $event instanceof OrderCancelled))
            ->sendOnce($this->staff->regionals($order->region_id, $order->businessDay->day));
    }
}
