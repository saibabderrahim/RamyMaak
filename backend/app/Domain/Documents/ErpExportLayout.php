<?php

namespace App\Domain\Documents;

use App\Models\BusinessDay;
use App\Support\Csv;

/**
 * Format d'import ERP **provisoire** `erp-v0` (Q-14, I-85), en attendant la
 * définition de l'ERP : une ligne par ligne de commande Validée à Expédiée,
 * point décimal, dates ISO, UTF-8 sans BOM, séparateur « ; ». Tout changement
 * de colonnes se fait ici et nulle part ailleurs.
 */
final class ErpExportLayout implements ExportLayout
{
    public const VERSION = 'erp-v0';

    public const COLUMNS = [
        'day', 'delivery_date', 'order_number', 'client_code', 'region_code', 'depot_code', 'sku',
        'validated_qty', 'shipped_qty', 'unit_price_ht', 'vat_rate', 'amount_ht', 'status',
    ];

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function filename(BusinessDay $day): string
    {
        return 'commandes-'.self::VERSION.'-'.$day->dayKey().'.csv';
    }

    public function write(iterable $lines, string $target = 'php://output'): void
    {
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Sortie de l\'export inaccessible.');
        }

        try {
            fputcsv($out, self::COLUMNS, ';', '"', '');
            foreach ($lines as $line) {
                $order = $line->order;
                fputcsv($out, [
                    Csv::sanitize($order->day->format('Y-m-d')),
                    Csv::sanitize($order->deliveryDate?->format('Y-m-d')),
                    Csv::sanitize($order->number),
                    Csv::sanitize($order->clientCode),
                    Csv::sanitize($order->regionCode),
                    Csv::sanitize($order->depotCode),
                    Csv::sanitize($line->sku),
                    $line->validated ?? '',
                    $line->shipped ?? '',
                    number_format($line->unitPriceHt, 2, '.', ''),
                    number_format($line->vatRate, 2, '.', ''),
                    $line->amountHt === null ? '' : number_format($line->amountHt, 2, '.', ''),
                    $order->status->value,
                ], ';', '"', '');
            }
        } finally {
            fclose($out);
        }
    }
}
