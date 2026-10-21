<?php

namespace App\Listeners;

use App\Domain\Days\RegionStaff;
use App\Events\OrderSubmitted;
use App\Models\Order;
use App\Notifications\OrderSubmittedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * N-05 (§7.8, I-57) : commande envoyée, notifiée aux régionaux de sa région
 * le jour de la commande (titulaire et suppléants actifs, RegionStaff).
 * Tâche de fond (EF-NOT-06), rejouable sans double envoi (§9.7).
 */
final class NotifyOrderSubmitted implements ShouldQueue
{
    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly RegionStaff $staff) {}

    public function handle(OrderSubmitted $event): void
    {
        $order = Order::query()->with(['client', 'businessDay'])->find($event->orderId);
        if ($order === null) {
            return;
        }

        (new OrderSubmittedNotification($order))->sendOnce($this->staff->regionals($order->region_id, $order->businessDay->day));
    }
}
