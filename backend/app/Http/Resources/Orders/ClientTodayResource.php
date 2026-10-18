<?php

namespace App\Http\Resources\Orders;

use App\Domain\Orders\ClientDayStatus;
use App\Domain\Orders\OrderBook;
use App\Domain\Orders\OrderingState;
use App\Domain\Orders\OrderingWindow;
use App\Domain\Orders\OrderStatus;
use App\Domain\Referentials\ClientStatus;
use App\Domain\Referentials\ProductPhotos;
use App\Domain\Settings\Settings;
use App\Http\Problems\ErrorCode;
use App\Http\Resources\ProductPhotoResource;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductPrice;
use App\Support\BusinessClock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Journée du client (EF-CLT-01, EF-CLT-02 ; contrat du lot 5, §3) : état de
 * la prise de commande de sa région (OrderingWindow, I-43), possibilité
 * d'envoyer, ses commandes de la journée et le catalogue du jour.
 *
 * - Catalogue : produits commandables (OrderBook::availability, I-44), une
 *   fois la répartition envoyée, triés comme la matrice du lot 3 ; prix du
 *   jour (PriceRef, Money), photos, borne et disponible du client.
 * - RG-C09 : jamais Q, R, V(r,p), ΣB, les bornes des autres clients, ni
 *   `ean`, `next_price` ou `pending_deactivation_on`. Sans borne, `limit` et
 *   `available` valent null ; `sold_out` signale seulement un reste nul.
 *
 * Nombre de requêtes borné, quel que soit le nombre de produits.
 *
 * @mixin OrderingState
 */
class ClientTodayResource extends JsonResource
{
    /**
     * @param  Collection<int, Order>  $orders  commandes du client pour la journée, plus récentes d'abord
     * @param  Collection<int, Product>  $products  produits commandables, dans l'ordre d'affichage
     * @param  array<int, array{quota: int, region_validated: int, region_remaining: int, limit: ?int, engaged: int, available: int, sold_out: bool, price_ht: string}>  $availability  OrderBook::availability (seuls limit, engaged, available, sold_out et price_ht sont montrés)
     */
    public function __construct(
        OrderingState $state,
        private readonly Client $client,
        private readonly Collection $orders,
        private readonly Collection $products,
        private readonly array $availability,
        private readonly ClientOrderActions $actions,
        private readonly int $maxOrders,
        private readonly mixed $minAmount,
    ) {
        parent::__construct($state);
    }

    /**
     * Journée `$day` du client (état, commandes, catalogue). En modification,
     * E(c,p) exclut la commande modifiée tant qu'elle est envoyée (§5.1) :
     * le disponible montré est celui que contrôlera la modification.
     */
    public static function for(Client $client, CarbonImmutable $day, ?Order $editing = null): self
    {
        $state = app(OrderingWindow::class)->state($client, $day);
        $settings = app(Settings::class);

        $orders = $state->businessDay === null ? collect() : Order::query()
            ->where('client_id', $client->id)
            ->where('business_day_id', $state->businessDay->id)
            ->withCount('lines')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            ->each(fn (Order $order) => $order->setRelation('businessDay', $state->businessDay));

        // Catalogue seulement une fois la répartition envoyée (RG-B06) : commandes ouvertes, ou heure limite passée.
        $catalogue = in_array($state->status, [ClientDayStatus::OrderingOpen, ClientDayStatus::OrderingClosed], true)
            && $state->regionDay !== null
            && $state->regionDay->isDistributionPublished();
        $except = $editing !== null && $editing->status === OrderStatus::Submitted && $editing->business_day_id === $state->businessDay?->id
            ? $editing->id
            : null;
        $availability = $catalogue ? app(OrderBook::class)->availability($state->regionDay, $client->id, $except) : [];

        return new self(
            $state,
            $client,
            $orders,
            self::products($state->day, array_keys($availability)),
            $availability,
            ClientOrderActions::for($client)->remember($state),
            $settings->int('orders.max_per_day'),
            $settings->get('orders.min_amount'),
        );
    }

    /**
     * Produits listés, avec famille, photos et date d'effet du prix du jour,
     * en trois requêtes ; ordre de la matrice du lot 3 : famille (ordre,
     * code), puis ordre et code article.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private static function products(string $day, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Product::query()
            ->select([
                'products.id', 'products.sku', 'products.name', 'products.short_name', 'products.family_id', 'products.format',
                'products.sales_unit_label', 'products.units_per_sales_unit', 'products.vat_rate', 'products.is_new', 'products.is_featured',
            ])
            ->addSelect(['current_price_from' => ProductPrice::query()
                ->select('valid_from')
                ->whereColumn('product_id', 'products.id')
                ->where('valid_from', '<=', $day)
                ->orderByDesc('valid_from')
                ->limit(1),
            ])
            ->join('product_families', 'product_families.id', '=', 'products.family_id')
            ->with(['family:id,code,name,sort_order', 'media'])
            ->whereIn('products.id', $ids)
            ->orderBy('product_families.sort_order')
            ->orderBy('product_families.code')
            ->orderBy('products.sort_order')
            ->orderBy('products.sku')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderingState $state */
        $state = $this->resource;
        $businessDay = $state->businessDay;
        $counted = $this->orders->filter(fn (Order $order) => in_array($order->status, OrderStatus::counted(), true))->count();
        $blocker = $this->blocker($state->status, $counted);

        return [
            'server_time' => now()->utc()->toIso8601String(),
            'day' => $state->day,
            'delivery_date' => $businessDay?->delivery_date?->format('Y-m-d'),
            'status' => $state->status->value,
            // Heure limite de la région du client, prolongation comprise (RG-A05) ; null tant que la journée n'est pas ouverte.
            'ordering_cutoff' => $businessDay === null || $state->cutoffAt === null ? null : BusinessClock::toLocal($state->cutoffAt)->format('H:i'),
            'ordering_cutoff_at' => $businessDay === null || $state->cutoffAt === null ? null : CarbonImmutable::instance($state->cutoffAt)->utc()->toIso8601String(),
            'ordering_cutoff_extended' => $businessDay !== null && $state->extended,
            'client' => [
                'id' => $this->client->id,
                'code' => $this->client->code,
                'name' => $this->client->name,
                'status' => $this->client->status->value,
            ],
            'max_orders_per_day' => $this->maxOrders,
            'min_amount_ht' => $this->minAmount === null || $this->minAmount === '' ? null : Money::amount(Money::cents($this->minAmount)),
            'can_submit' => $blocker === null,
            'submit_blocker' => $blocker?->value,
            'orders' => $this->orders->map(fn (Order $order) => [
                ...OrderPresenter::summary($order),
                ...$this->actions->rights($order),
            ])->values()->all(),
            'families' => $this->products->pluck('family')->unique('id')->map(fn (ProductFamily $family) => [
                'id' => $family->id,
                'code' => $family->code,
                'name' => $family->name,
                'sort_order' => $family->sort_order,
            ])->values()->all(),
            'products' => $this->products->map(fn (Product $product) => $this->product($product))->values()->all(),
        ];
    }

    /**
     * Ce qui empêche d'envoyer une commande, par ordre de priorité, ou null.
     */
    private function blocker(ClientDayStatus $status, int $counted): ?ErrorCode
    {
        return match (true) {
            $this->client->status !== ClientStatus::Active => ErrorCode::ClientBlocked,
            $status === ClientDayStatus::WaitingQuotas, $status === ClientDayStatus::DayClosed => ErrorCode::DayNotOpen,
            $status === ClientDayStatus::OrderingClosed => ErrorCode::CutoffPassed,
            $counted >= $this->maxOrders => ErrorCode::MaxOrdersReached,
            default => null,
        };
    }

    /**
     * Produit du catalogue du jour : fiche réduite, prix du jour (PriceRef du
     * lot 2), photos (principale d'abord), quantités du client (RG-C09).
     *
     * @return array<string, mixed>
     */
    private function product(Product $product): array
    {
        $row = $this->availability[$product->id];
        $cents = Money::cents($row['price_ht']);
        $vat = Money::basisPoints($product->vat_rate);

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'short_name' => $product->short_name,
            'family_id' => $product->family_id,
            'format' => $product->format,
            'sales_unit_label' => $product->sales_unit_label,
            'units_per_sales_unit' => $product->units_per_sales_unit,
            'vat_rate' => (float) $product->vat_rate,
            'price' => [
                'price_ht' => Money::amount($cents),
                'price_ttc' => Money::amount(Money::ttcCents($cents, $vat)),
                'unit_price_ht' => Money::amount(Money::unitCents($cents, $product->units_per_sales_unit)),
                'valid_from' => substr((string) $product->getAttribute('current_price_from'), 0, 10),
            ],
            'photos' => ProductPhotoResource::ordered(app(ProductPhotos::class)->photos($product)),
            'is_new' => $product->is_new,
            'is_featured' => $product->is_featured,
            // Sans borne : rien n'est montré, ni borne ni disponible (RG-C09, I-45).
            'limit' => $row['limit'],
            'engaged' => $row['engaged'],
            'available' => $row['limit'] === null ? null : $row['available'],
            'sold_out' => $row['sold_out'],
        ];
    }
}
