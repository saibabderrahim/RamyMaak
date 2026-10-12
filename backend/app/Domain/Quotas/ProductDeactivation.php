<?php

namespace App\Domain\Quotas;

use App\Domain\Days\BusinessDayStatus;
use App\Models\Product;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * RG-Q07 : un produit doté d'un quota publié > 0 sur la journée ouverte
 * d'aujourd'hui n'est désactivé qu'à partir de demain (`deactivate_on`,
 * appliqué par ramymaak:apply-product-deactivations) ; le réactiver annule
 * cette désactivation programmée. Utilisé par la fiche produit et par
 * l'import Excel des produits.
 */
final class ProductDeactivation
{
    /**
     * Applique le statut demandé à un produit (verrouillé par l'appelant,
     * qui l'enregistre ensuite).
     */
    public function setActive(Product $product, bool $active): void
    {
        $deferred = ! $active && $product->is_active && $this->hasPublishedQuotaToday($product);
        $product->is_active = $active || $deferred;
        $product->deactivate_on = $deferred ? BusinessClock::today()->addDay() : null;

        if (! $active) {
            $this->discardDrafts($product, $product->deactivate_on ?? BusinessClock::today());
        }
    }

    /**
     * Efface les brouillons du produit sur les journées ouvertes à partir du
     * jour donné : inactif ces jours-là, il ne peut plus y être publié
     * (RG-Q02). Les quotas déjà publiés restent.
     */
    public function discardDrafts(Product $product, CarbonInterface $from): int
    {
        return RegionQuota::query()
            ->where('product_id', $product->id)
            ->whereNotNull('draft_quantity')
            ->whereHas('regionDay.businessDay', fn (Builder $day) => $day
                ->where('status', BusinessDayStatus::Open->value)
                ->where('day', '>=', $from->format('Y-m-d')))
            ->update(['draft_quantity' => null]);
    }

    /**
     * Journalise la programmation ou l'annulation d'une désactivation
     * (journal « referentials ») si `deactivate_on` a changé.
     *
     * @param  ?string  $before  `deactivate_on` avant la modification (AAAA-MM-JJ)
     */
    public function logChange(Product $product, ?string $before, ?User $by): void
    {
        $after = $product->deactivate_on?->format('Y-m-d');
        if ($after === $before || ($after === null && ! $product->is_active)) {
            return;
        }

        $logger = activity('referentials')->performedOn($product);
        if ($by !== null) {
            $logger->causedBy($by);
        }
        $logger
            ->event($after !== null ? 'deactivation.scheduled' : 'deactivation.cancelled')
            ->withProperties(['old' => ['deactivate_on' => $before], 'attributes' => ['deactivate_on' => $after]])
            ->log(__($after !== null ? 'quotas.deactivation.scheduled' : 'quotas.deactivation.cancelled'));
    }

    /** Quota publié > 0 sur la journée ouverte d'aujourd'hui, dans une région au moins. */
    public function hasPublishedQuotaToday(Product $product): bool
    {
        return RegionQuota::query()
            ->join('region_days', 'region_days.id', '=', 'region_quotas.region_day_id')
            ->join('business_days', 'business_days.id', '=', 'region_days.business_day_id')
            ->where('business_days.day', BusinessClock::today()->format('Y-m-d'))
            ->where('business_days.status', BusinessDayStatus::Open->value)
            ->where('region_days.quota_version', '>', 0)
            ->where('region_quotas.product_id', $product->id)
            ->where('region_quotas.quantity', '>', 0)
            ->exists();
    }
}
