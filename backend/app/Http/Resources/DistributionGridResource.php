<?php

namespace App\Http\Resources;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Limits\ClientUsage;
use App\Domain\Limits\LimitBook;
use App\Domain\Settings\Settings;
use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\Product;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Grille de répartition d'une région pour une journée (EF-REG-02, EF-REG-03 ;
 * contrat du lot 4, §3), commune au régional et au back-office (lecture
 * seule : `can_edit` et `can_publish` faux).
 *
 * - Colonnes : produits disponibles (LimitBook::availableQuotas, RG-B02),
 *   triés comme la matrice du lot 3, avec Q, ΣB (valeurs affichées des
 *   clients de la grille), S, V(r,p) et R = Q − V(r,p) (§5.1).
 * - Lignes : clients de la région ce jour-là, actifs ou bloqués (RG-S04).
 * - Cellules : `cells[client][produit]`, seulement celles qui ont une borne,
 *   un brouillon, ou des quantités en attente ou validées (ClientUsage).
 * - Quotas de la région non publiés : ni colonnes ni cellules.
 *
 * Nombre de requêtes borné, quelle que soit la taille de la grille.
 *
 * @mixin RegionDay
 */
class DistributionGridResource extends JsonResource
{
    /**
     * @param  Collection<int, Product>  $products  colonnes, dans l'ordre d'affichage
     * @param  array<int, int>  $quotas  produit → Q(r,p)
     * @param  array<int, int>  $validated  produit → V(r,p)
     * @param  Collection<int, Client>  $clients  lignes, par code
     * @param  Collection<int, Collection<int, ClientLimit>>  $limits  bornes des lignes et colonnes, par client puis par produit
     * @param  array<int, array<int, array{pending: int, validated: int}>>  $usage  S et V par client puis par produit
     * @param  array<string, mixed>  $publication  LimitBook::previewPublication
     */
    public function __construct(
        RegionDay $regionDay,
        private readonly Collection $products,
        private readonly array $quotas,
        private readonly array $validated,
        private readonly Collection $clients,
        private readonly Collection $limits,
        private readonly array $usage,
        private readonly array $publication,
        private readonly string $policy,
        private readonly bool $canEdit,
        private readonly bool $canPublish,
    ) {
        parent::__construct($regionDay);
    }

    /** Grille de la journée régionale vue par l'utilisateur (droits de saisie et d'envoi). */
    public static function for(RegionDay $regionDay, User $user): self
    {
        $regionDay->loadMissing(['businessDay', 'region']);
        $book = app(LimitBook::class);

        $quotas = $regionDay->isQuotaPublished() ? $book->availableQuotas($regionDay) : [];
        $products = $quotas === [] ? collect() : Product::query()
            ->select(['products.id', 'products.sku', 'products.name', 'products.short_name', 'products.family_id', 'products.sales_unit_label'])
            ->join('product_families', 'product_families.id', '=', 'products.family_id')
            ->with('family:id,code,name')
            ->whereIn('products.id', array_keys($quotas))
            // Même ordre que les lignes de la matrice des quotas (lot 3).
            ->orderBy('product_families.sort_order')
            ->orderBy('product_families.code')
            ->orderBy('products.sort_order')
            ->orderBy('products.sku')
            ->get();
        $validated = $quotas === [] ? [] : RegionQuota::query()
            ->where('region_day_id', $regionDay->id)
            ->whereIn('product_id', array_keys($quotas))
            ->pluck('validated_qty', 'product_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->all();

        $clients = $book->clientsQuery($regionDay)
            ->with('category:id,code,name')
            ->get(['id', 'code', 'name', 'status', 'category_id']);

        $limits = collect();
        $usage = [];
        if ($quotas !== []) {
            $clientIds = $clients->pluck('id')->flip();
            $limits = ClientLimit::query()
                ->where('region_day_id', $regionDay->id)
                ->whereIn('product_id', array_keys($quotas))
                ->get(['client_id', 'product_id', 'max_qty', 'draft_max_qty', 'has_draft'])
                ->filter(fn (ClientLimit $limit) => $clientIds->has($limit->client_id))
                ->groupBy('client_id')
                ->map(fn (Collection $byClient) => $byClient->keyBy('product_id'));
            $usage = app(ClientUsage::class)->forRegionDay($regionDay);
        }

        // Saisie et envoi : rôle régional, permission, journée et région
        // ouvertes, quotas publiés (RG-B06) ; jamais pour le back-office.
        $writable = $user->hasAppRole(Role::Regional)
            && $regionDay->businessDay->isOpen()
            && $regionDay->status !== RegionDayStatus::Closed
            && $regionDay->isQuotaPublished();

        return new self(
            $regionDay,
            $products,
            $quotas,
            $validated,
            $clients,
            $limits,
            $usage,
            $book->previewPublication($regionDay),
            (string) app(Settings::class)->get('limits.no_limit_policy'),
            $writable && $user->can(Permission::LimitEdit->value),
            $writable && $user->can(Permission::LimitPublish->value),
        );
    }

    /**
     * Une cellule de la grille : borne publiée B (null : pas de borne),
     * brouillon (`null` : rien à envoyer ; `{ value }`, value null : borne
     * retirée), quantités en attente S et validées V du client.
     *
     * @param  array{pending?: int, validated?: int}|null  $usage
     * @return array{limit: ?int, draft: array{value: ?int}|null, pending: int, validated: int}
     */
    public static function cell(?ClientLimit $limit, ?array $usage): array
    {
        return [
            'limit' => $limit?->max_qty,
            'draft' => $limit !== null && $limit->has_draft ? ['value' => $limit->draft_max_qty] : null,
            'pending' => (int) ($usage['pending'] ?? 0),
            'validated' => (int) ($usage['validated'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RegionDay $regionDay */
        $regionDay = $this->resource;

        // Cellules renseignées, dans l'ordre des lignes puis des colonnes ;
        // ΣB sur les valeurs affichées des clients de la grille.
        $cells = [];
        $sumLimits = [];
        foreach ($this->clients as $client) {
            $limits = $this->limits->get($client->id);
            $usage = $this->usage[$client->id] ?? [];
            if ($limits === null && $usage === []) {
                continue;
            }
            foreach ($this->products as $product) {
                $limit = $limits?->get($product->id);
                $used = $usage[$product->id] ?? null;
                $sumLimits[$product->id] = ($sumLimits[$product->id] ?? 0) + (int) $limit?->displayed();
                $filled = ($limit !== null && ($limit->max_qty !== null || $limit->has_draft))
                    || ($used !== null && ((int) ($used['pending'] ?? 0) > 0 || (int) ($used['validated'] ?? 0) > 0));
                if ($filled) {
                    $cells[$client->id][$product->id] = self::cell($limit, $used);
                }
            }
        }

        // S(r,p) : quantités en attente de la région, client par client.
        $pending = [];
        foreach ($this->usage as $byProduct) {
            foreach ($byProduct as $productId => $used) {
                $pending[$productId] = ($pending[$productId] ?? 0) + (int) ($used['pending'] ?? 0);
            }
        }

        return [
            'day' => $this->dayEntry($regionDay),
            'region' => $this->regionEntry($regionDay),
            'policy' => $this->policy,
            'can_edit' => $this->canEdit,
            'can_publish' => $this->canPublish,
            'products' => $this->products->map(function (Product $product) use ($sumLimits, $pending): array {
                $quota = $this->quotas[$product->id];
                $validated = $this->validated[$product->id] ?? 0;

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'short_name' => $product->short_name,
                    'family' => [
                        'id' => $product->family->id,
                        'code' => $product->family->code,
                        'name' => $product->family->name,
                    ],
                    'sales_unit_label' => $product->sales_unit_label,
                    'quota' => $quota,
                    'sum_limits' => $sumLimits[$product->id] ?? 0,
                    'pending' => $pending[$product->id] ?? 0,
                    'validated' => $validated,
                    'remaining' => $quota - $validated,
                ];
            })->values()->all(),
            'clients' => $this->clients->map(fn (Client $client) => [
                'id' => $client->id,
                'code' => $client->code,
                'name' => $client->name,
                'status' => $client->status->value,
                'category' => $client->category === null ? null : [
                    'id' => $client->category->id,
                    'code' => $client->category->code,
                    'name' => $client->category->name,
                ],
            ])->values()->all(),
            // Objets JSON (et non listes), même vides : clés = identifiants.
            'cells' => (object) array_map(fn (array $byProduct) => (object) $byProduct, $cells),
            'publication' => $this->publication,
        ];
    }

    /**
     * Journée : statut, date de livraison et heures limites « HH:MM » (fuseau métier).
     *
     * @return array<string, mixed>
     */
    private function dayEntry(RegionDay $regionDay): array
    {
        $day = $regionDay->businessDay;
        $cutoffs = [];
        foreach (CutoffKind::chronological() as $kind) {
            $cutoffs[strtolower($kind->value)] = BusinessClock::toLocal($day->cutoff($kind))->format('H:i');
        }

        return [
            'day' => $day->dayKey(),
            'status' => $day->status->value,
            'delivery_date' => $day->delivery_date?->format('Y-m-d'),
            'cutoffs' => $cutoffs,
        ];
    }

    /**
     * Région : statut (§6.1), versions des quotas et de la répartition, heures
     * limites applicables, prolongation comprise (RG-A05).
     *
     * @return array<string, mixed>
     */
    private function regionEntry(RegionDay $regionDay): array
    {
        $cutoffs = [];
        $extended = [];
        foreach ([CutoffKind::Distribution, CutoffKind::Ordering] as $kind) {
            $key = strtolower($kind->value);
            $cutoffs[$key] = BusinessClock::toLocal($regionDay->effectiveCutoff($kind))->format('H:i');
            $extended[$key] = $regionDay->isExtended($kind);
        }

        return [
            'id' => $regionDay->region->id,
            'code' => $regionDay->region->code,
            'name' => $regionDay->region->name,
            'status' => $regionDay->status->value,
            'status_label' => $regionDay->status->label(),
            'quota_version' => $regionDay->quota_version,
            'distribution_version' => $regionDay->distribution_version,
            'distribution_published_at' => $regionDay->distribution_published_at?->toIso8601String(),
            'cutoffs' => $cutoffs,
            'extended' => $extended,
        ];
    }
}
