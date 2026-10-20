<?php

namespace App\Domain\Orders;

use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\RegionDay;
use Illuminate\Support\Collection;

/**
 * Verrous des écrivains de commandes (§5.8 ; I-61, prolongé par I-67), à
 * prendre dans cet ordre, le même partout :
 *
 * 1. client FOR UPDATE (écritures qui changent S, V ou le nombre de
 *    commandes du client), toujours la première instruction ;
 * 2. journée(s) en partagé, par identifiant croissant (tout écrivain de
 *    commande, ADV compris, même après la clôture) : la clôture et la
 *    publication des quotas, qui la prennent en exclusif, attendent ;
 * 3. journée régionale en partagé (validation) : la publication d'une borne
 *    (LimitBook, journée régionale en exclusif) attend (I-2) ;
 * 4. commande(s) FOR UPDATE, par identifiant croissant ;
 * 5. bornes du client, puis 6. compteurs région (QuotaLedger), par produit
 *    croissant ; 7. numéro du jour (envoi, en dernier).
 *
 * Jamais de verrou de journée après un verrou de commande. Sans effet sous
 * SQLite (les tests de concurrence tournent sur MySQL).
 */
final class OrderLocks
{
    public function client(int $clientId): ?Client
    {
        return Client::query()->lockForUpdate()->find($clientId);
    }

    /** Journée en partagé. */
    public function day(int $businessDayId): BusinessDay
    {
        return BusinessDay::query()->sharedLock()->findOrFail($businessDayId);
    }

    /**
     * Journées en partagé, par identifiant croissant.
     *
     * @param  iterable<int>  $businessDayIds
     * @return Collection<int, BusinessDay> identifiant → journée
     */
    public function days(iterable $businessDayIds): Collection
    {
        $ids = collect($businessDayIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $ids === [] ? collect() : BusinessDay::query()->whereKey($ids)->orderBy('id')->sharedLock()->get()->keyBy('id');
    }

    /** Journée régionale en partagé (validation), null si elle n'existe pas. */
    public function regionDay(int $businessDayId, int $regionId): ?RegionDay
    {
        return RegionDay::query()
            ->where('business_day_id', $businessDayId)
            ->where('region_id', $regionId)
            ->sharedLock()
            ->first();
    }

    public function order(int $orderId): ?Order
    {
        return Order::query()->lockForUpdate()->find($orderId);
    }

    /**
     * Commandes FOR UPDATE, par identifiant croissant.
     *
     * @param  iterable<int>  $orderIds
     * @return Collection<int, Order> identifiant → commande
     */
    public function orders(iterable $orderIds): Collection
    {
        $ids = collect($orderIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $ids === [] ? collect() : Order::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }
}
