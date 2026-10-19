<?php

namespace App\Http\Requests\Orders;

use App\Domain\Orders\OrderStatus;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\Response;
use LogicException;

/**
 * Commandes reçues (EF-REG-04 ; contrat du lot 5, §4) :
 * `?day=AAAA-MM-JJ` (aujourd'hui par défaut), `filter[status]` (liste
 * séparée par des virgules, casse indifférente ; « À valider » = SUBMITTED
 * par défaut), `filter[region]` (une région). Les régions sont celles du
 * périmètre à la date de la journée ; une région filtrée hors périmètre
 * répond 404 avant la validation. Autre filtre ou tri → 422.
 */
class ListRegionalOrdersRequest extends RegionalOrderRequest
{
    /** @var list<int>|null */
    private ?array $resolvedRegionIds = null;

    protected function authorizeTarget(User $user): Response
    {
        $day = $this->requestedDay();
        if ($day === null) {
            // Journée mal formée : la validation répond (422).
            return Response::allow();
        }

        // Régional : ses affectations actives ce jour-là, titulaire ou suppléant (§3.3, §3.4).
        $regionIds = $this->perimeter()->regionIds($user, $day) ?? [];
        $region = $this->regionFilter();
        if ($region !== null) {
            if (! in_array($region, $regionIds, true)) {
                return Response::denyAsNotFound(__('errors.NOT_FOUND'));
            }
            $regionIds = [$region];
        }
        $this->resolvedRegionIds = $regionIds;

        return Response::allow();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'day' => ['nullable', 'bail', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || BusinessClock::parseDay($value) === null) {
                    $fail(__('orders.client.day_format'));
                }
            }],
            'filter' => ['nullable', 'array:status,region'],
            'filter.status' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (self::parseStatuses($value) === null) {
                    $fail(__('orders.client.unknown_status'));
                }
            }],
            'filter.region' => ['nullable', 'integer'],
            // Ordre fixe : heure d'envoi croissante (RG-V01).
            'sort' => ['prohibited'],
        ];
    }

    /** Journée demandée, validée ; aujourd'hui par défaut. */
    public function day(): CarbonImmutable
    {
        return $this->requestedDay() ?? throw new LogicException('Journée non validée.');
    }

    /**
     * Régions lues : périmètre du jour, réduit à la région filtrée.
     *
     * @return list<int>
     */
    public function regionIds(): array
    {
        return $this->resolvedRegionIds ?? throw new LogicException('Régions non résolues.');
    }

    /**
     * Statuts demandés (onglet), SUBMITTED par défaut.
     *
     * @return list<OrderStatus>
     */
    public function statuses(): array
    {
        $filter = $this->query('filter');
        $value = is_array($filter) ? ($filter['status'] ?? null) : null;
        if ($value === null) {
            return OrderStatus::pending();
        }

        return self::parseStatuses($value) ?? throw new LogicException('Statuts non validés.');
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

    /** `?day=` lu avant la validation : aujourd'hui s'il est absent, null s'il est mal formé. */
    private function requestedDay(): ?CarbonImmutable
    {
        $value = $this->query('day');
        if ($value === null) {
            return BusinessClock::today();
        }

        return is_string($value) ? BusinessClock::parseDay($value) : null;
    }

    /** `filter[region]`, s'il est bien formé (même lecture que la règle « integer ») ; null : la validation répond. */
    private function regionFilter(): ?int
    {
        $filter = $this->query('filter');
        $value = is_array($filter) ? ($filter['region'] ?? null) : null;
        $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $id === false ? null : $id;
    }
}
