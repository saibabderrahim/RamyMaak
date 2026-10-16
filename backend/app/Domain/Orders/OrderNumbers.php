<?php

namespace App\Domain\Orders;

use App\Models\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * Numéro lisible d'une commande (I-55) : RMK-AAMMJJ-NNNNN, rang dans la
 * journée. Alloué dans la transaction d'envoi, en dernier : une annulation
 * de la transaction rend le numéro (pas de trou).
 */
final class OrderNumbers
{
    public function next(BusinessDay $day): string
    {
        $now = now();
        DB::table('order_number_sequences')->insertOrIgnore([
            'business_day_id' => $day->id,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $last = (int) DB::table('order_number_sequences')
            ->where('business_day_id', $day->id)
            ->lockForUpdate()
            ->value('last_number');
        DB::table('order_number_sequences')
            ->where('business_day_id', $day->id)
            ->update(['last_number' => $last + 1, 'updated_at' => $now]);

        return sprintf('RMK-%s-%05d', $day->day->format('ymd'), $last + 1);
    }
}
