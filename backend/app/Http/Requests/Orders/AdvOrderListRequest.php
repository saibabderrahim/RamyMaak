<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Orders\OrderStatus;
use App\Domain\Referentials\AssignmentKind;
use App\Models\Depot;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * File des commandes validées (EF-ADV-04 ; contrat du lot 6, §5) :
 *
 * - `filter[status]` : liste séparée par des virgules, casse indifférente,
 *   parmi Validée et suivantes et Annulée (après validation) ; par défaut
 *   Validée, En préparation, Préparée (I-90) ;
 * - `filter[day]` (AAAA-MM-JJ), `filter[region]`, `filter[depot]` (identifiant
 *   ou `none`), `filter[client]`, `filter[product]`, `filter[search]`
 *   (numéro, code ou nom du client) ;
 * - `sort` : `decided_at` (défaut, croissant : premier validé, premier
 *   préparé), `-decided_at`, `number`, `-number`, `submitted_at`.
 *
 * Une région ou un dépôt hors périmètre répond 404 avant la validation ; le
 * périmètre est celui de la journée filtrée, à défaut celui de toutes les
 * affectations ADV de l'appelant, puisque la file court sur plusieurs
 * journées et que chaque commande est jugée à sa propre journée (I-90).
 * Autre filtre, statut inconnu ou tri → 422.
 */
class AdvOrderListRequest extends AdvOrderRequest
{
    public const DEFAULT_SORT = 'decided_at';

    public const SORTS = ['decided_at', '-decided_at', 'number', '-number', 'submitted_at'];

    public const FILTERS = ['status', 'day', 'region', 'depot', 'client', 'product', 'search'];

    protected function permission(): Permission
    {
        return Permission::OrderView;
    }

    protected function roles(): array
    {
        return [Role::Adv, Role::Admin];
    }

    protected function authorizeTarget(User $user): Response
    {
        $day = $this->requestedDay();
        $regionIds = $this->perimeterRegions($user, $day);

        $region = $this->integerFilter('region');
        if ($region !== null && ! ($regionIds === null ? Region::query()->whereKey($region)->exists() : in_array($region, $regionIds, true))) {
            return Response::denyAsNotFound(__('errors.NOT_FOUND'));
        }
        $depot = $this->requestedDepot();
        if (is_int($depot) && ! ($regionIds === null
            ? Depot::query()->whereKey($depot)->exists()
            : Region::query()->whereIn('id', $regionIds)->where('depot_id', $depot)->exists())) {
            return Response::denyAsNotFound(__('errors.NOT_FOUND'));
        }

        return Response::allow();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'array:'.implode(',', self::FILTERS)],
            'filter.status' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                $statuses = self::parseStatuses($value);
                if ($statuses === null) {
                    $fail(__('orders.client.unknown_status'));
                } elseif (array_diff(OrderStatus::values($statuses), self::allowedStatuses()) !== []) {
                    $fail(__('orders.adv_request.status_not_allowed'));
                }
            }],
            'filter.day' => ['nullable', 'bail', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || BusinessClock::parseDay($value) === null) {
                    $fail(__('orders.client.day_format'));
                }
            }],
            'filter.region' => ['nullable', 'integer', 'min:1'],
            'filter.depot' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value) || (strtolower((string) $value) !== 'none' && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
                    $fail(__('orders.adv_request.depot_format'));
                }
            }],
            'filter.client' => ['nullable', 'integer', 'min:1'],
            'filter.product' => ['nullable', 'integer', 'min:1'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
        ];
    }

    /**
     * Statuts de la file : Validée et suivantes, et Annulée après validation.
     *
     * @return list<string>
     */
    public static function allowedStatuses(): array
    {
        return OrderStatus::values([...OrderStatus::validated(), OrderStatus::Cancelled]);
    }

    /**
     * Statuts demandés (onglet) ; Validée, En préparation, Préparée par défaut.
     *
     * @return list<OrderStatus>
     */
    public function statuses(): array
    {
        $value = $this->validated('filter.status');
        if ($value === null) {
            return [OrderStatus::Validated, OrderStatus::InPreparation, OrderStatus::Prepared];
        }

        return self::parseStatuses($value) ?? throw new LogicException('Statuts non validés.');
    }

    public function day(): ?CarbonImmutable
    {
        $day = $this->validated('filter.day');

        return is_string($day) ? BusinessClock::parseDay($day) : null;
    }

    public function regionId(): ?int
    {
        $region = $this->validated('filter.region');

        return $region === null ? null : (int) $region;
    }

    /** Dépôt filtré : identifiant, `none` (régions sans dépôt) ou null. */
    public function depot(): int|string|null
    {
        $depot = $this->validated('filter.depot');
        if ($depot === null) {
            return null;
        }

        return strtolower((string) $depot) === 'none' ? 'none' : (int) $depot;
    }

    public function clientId(): ?int
    {
        $client = $this->validated('filter.client');

        return $client === null ? null : (int) $client;
    }

    public function productId(): ?int
    {
        $product = $this->validated('filter.product');

        return $product === null ? null : (int) $product;
    }

    public function search(): ?string
    {
        $search = $this->validated('filter.search');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? self::DEFAULT_SORT);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('orders.adv_request.attributes');
    }

    /**
     * Liste de statuts : texte séparé par des virgules ou liste de textes,
     * casse indifférente ; null si un statut est inconnu ou la liste vide.
     *
     * @return list<OrderStatus>|null
     */
    private static function parseStatuses(mixed $value): ?array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        $statuses = [];
        foreach ($values as $item) {
            if (! is_string($item)) {
                return null;
            }
            foreach (explode(',', $item) as $part) {
                $status = OrderStatus::tryFrom(mb_strtoupper(trim($part)));
                if ($status === null) {
                    return null;
                }
                $statuses[$status->value] = $status;
            }
        }

        return $statuses === [] ? null : array_values($statuses);
    }

    /**
     * Régions où l'appelant lit des commandes, null pour toutes : celles de
     * la journée filtrée ; sans journée, toutes les régions d'une
     * affectation ADV de l'appelant, quelle qu'en soit la date (RG-S05).
     *
     * @return list<int>|null
     */
    private function perimeterRegions(User $user, ?CarbonImmutable $day): ?array
    {
        $perimeter = app(Perimeter::class);
        $regionIds = $perimeter->regionIds($user, $day);
        if ($regionIds === null || $day !== null) {
            return $regionIds;
        }

        return RegionAssignment::query()
            ->where('user_id', $user->id)
            ->where('kind', AssignmentKind::Adv->value)
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('region_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** `filter[day]` lu avant la validation, s'il est bien formé ; null : la validation répond. */
    private function requestedDay(): ?CarbonImmutable
    {
        $filter = $this->query('filter');
        $value = is_array($filter) ? ($filter['day'] ?? null) : null;

        return is_string($value) ? BusinessClock::parseDay($value) : null;
    }

    /** `filter[depot]` lu avant la validation : identifiant, `none` ou null (absent ou mal formé). */
    private function requestedDepot(): int|string|null
    {
        $filter = $this->query('filter');
        $value = is_array($filter) ? ($filter['depot'] ?? null) : null;
        if (! is_scalar($value)) {
            return null;
        }
        if (strtolower((string) $value) === 'none') {
            return 'none';
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /** Filtre entier lu avant la validation (même lecture que la règle « integer ») ; null : absent ou mal formé. */
    private function integerFilter(string $name): ?int
    {
        $filter = $this->query('filter');
        $value = is_array($filter) ? ($filter[$name] ?? null) : null;
        $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return $id === false ? null : $id;
    }
}
