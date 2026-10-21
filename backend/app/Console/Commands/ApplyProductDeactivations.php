<?php

namespace App\Console\Commands;

use App\Domain\Quotas\ProductDeactivation;
use App\Models\Product;
use App\Support\BusinessClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applique les désactivations de produits programmées (RG-Q07) : un produit
 * qui avait un quota publié sur la journée en cours lors de sa désactivation
 * devient inactif à la journée suivante. Planifiée à 00:01 (heure d'Alger) ;
 * rejouable sans double effet, chaque application est journalisée.
 */
class ApplyProductDeactivations extends Command
{
    protected $signature = 'ramymaak:apply-product-deactivations';

    protected $description = 'Applique les désactivations de produits programmées arrivées à échéance (RG-Q07)';

    public function handle(): int
    {
        $today = BusinessClock::today()->format('Y-m-d');
        $applied = 0;

        Product::query()
            ->whereNotNull('deactivate_on')
            ->where('deactivate_on', '<=', $today)
            ->chunkById(200, function ($products) use ($today, &$applied): void {
                foreach ($products as $product) {
                    $applied += $this->apply($product->id, $today) ? 1 : 0;
                }
            });

        $this->info("{$applied} désactivation(s) de produit appliquée(s).");

        return self::SUCCESS;
    }

    /** Relu sous verrou : une réactivation concurrente l'emporte. */
    private function apply(int $productId, string $today): bool
    {
        return DB::transaction(function () use ($productId, $today): bool {
            $product = Product::query()->lockForUpdate()->find($productId);
            $due = $product?->deactivate_on?->format('Y-m-d');
            if ($due === null || $due > $today) {
                return false;
            }

            $wasActive = $product->is_active;
            // Une seule entrée, explicite, au lieu de « Produit modifié ».
            $product->disableLogging();
            $product->forceFill(['is_active' => false, 'deactivate_on' => null])->save();
            // RG-Q02 : plus de brouillon publiable sur les journées encore ouvertes.
            app(ProductDeactivation::class)->discardDrafts($product, BusinessClock::parseDay($due));
            if (! $wasActive) {
                return false;
            }

            activity('referentials')
                ->performedOn($product)
                ->event('deactivation.applied')
                ->withProperties([
                    'old' => ['is_active' => true, 'deactivate_on' => $due],
                    'attributes' => ['is_active' => false, 'deactivate_on' => null],
                ])
                ->log(__('quotas.deactivation.applied'));

            return true;
        });
    }
}
