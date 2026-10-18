<?php

namespace App\Domain\Orders;

use App\Models\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * Numéro lisible d'une commande (I-55) : RMK-AAMMJJ-NNNNN, rang dans la
 * journée. Alloué dans la transaction d'envoi, en dernier : une annulation
 * de la transaction rend le numéro (pas de trou), et le verrou de la ligne
 * de la journée, commune à toutes les régions, n'est tenu que jusqu'au commit.
 */
final class OrderNumbers
{
    public function next(BusinessDay $day): string
    {
        $now = now();
        // Une seule instruction qui pose d'emblée le verrou exclusif (INSERT …
        // ON DUPLICATE KEY UPDATE) : un INSERT IGNORE suivi d'un FOR UPDATE
        // passerait d'un verrou partagé à exclusif, et deux envois
        // simultanés s'interbloqueraient (MySQL).
        DB::table('order_number_sequences')->upsert(
            [['business_day_id' => $day->id, 'last_number' => 1, 'created_at' => $now, 'updated_at' => $now]],
            ['business_day_id'],
            ['last_number' => DB::raw('last_number + 1'), 'updated_at' => $now],
        );
        $number = (int) DB::table('order_number_sequences')->where('business_day_id', $day->id)->value('last_number');

        return sprintf('RMK-%s-%05d', $day->day->format('ymd'), $number);
    }
}
