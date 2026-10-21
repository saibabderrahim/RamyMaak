<?php

namespace App\Domain\Orders;

use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\Order;

/**
 * Verrouillage optimiste des commandes (RG-C05, RG-V06, §5.8 étape 1) : la
 * version transmise est comparée à la version lue sous verrou, puis la
 * nouvelle version est écrite par une mise à jour conditionnelle (statut et
 * version attendus), gardée en filet (I-68).
 */
final class OrderVersion
{
    /** @throws ApiException VERSION_CONFLICT (+ `version` actuelle) */
    public function assert(Order $order, int $version): void
    {
        if ($order->version !== $version) {
            throw new ApiException(ErrorCode::VersionConflict, __('orders.version_conflict'), ['version' => $order->version]);
        }
    }

    /**
     * `UPDATE orders SET …, status = :to, version = :expected + 1 WHERE id
     * AND status = :from AND version = :expected` ; 0 ligne → conflit. La
     * commande en mémoire reçoit les mêmes valeurs.
     *
     * @param  array<string, mixed>  $attributes  autres colonnes (décision, totaux, horodatages)
     *
     * @throws ApiException VERSION_CONFLICT (+ `version` actuelle)
     */
    public function bump(Order $order, int $expected, OrderStatus $from, OrderStatus $to, array $attributes = []): void
    {
        $updated = Order::query()
            ->whereKey($order->id)
            ->where('status', $from->value)
            ->where('version', $expected)
            ->update([...$attributes, 'status' => $to->value, 'version' => $expected + 1, 'updated_at' => now()]);
        if ($updated === 0) {
            throw new ApiException(ErrorCode::VersionConflict, __('orders.version_conflict'), [
                'version' => (int) Order::query()->whereKey($order->id)->value('version'),
            ]);
        }
        $order->forceFill([...$attributes, 'status' => $to, 'version' => $expected + 1]);
    }
}
