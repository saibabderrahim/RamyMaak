<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\Response;
use LogicException;

/**
 * « Ma journée » du régional (EF-REG-01 ; contrat du lot 6, §3) :
 * `?day=AAAA-MM-JJ` (aujourd'hui par défaut) et `?region=` (une région).
 * Contrôlés avant la validation (403, 404, 422) : rôle Régional et
 * permission `dashboard.view` ; les régions sont celles du périmètre à la
 * date de la journée (titulaire ou suppléant), une région demandée hors
 * périmètre répond 404.
 */
class RegionalDashboardRequest extends RegionalOrderRequest
{
    /** @var list<int>|null */
    private ?array $resolvedRegionIds = null;

    protected function permission(): Permission
    {
        return Permission::DashboardView;
    }

    protected function authorizeTarget(User $user): Response
    {
        $day = $this->requestedDay();
        if ($day === null) {
            // Journée mal formée : la validation répond (422).
            return Response::allow();
        }

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
            'region' => ['nullable', 'integer'],
        ];
    }

    /** Journée demandée, validée ; aujourd'hui par défaut. */
    public function day(): CarbonImmutable
    {
        return $this->requestedDay() ?? throw new LogicException('Journée non validée.');
    }

    /**
     * Régions lues : périmètre du jour, réduit à la région demandée.
     *
     * @return list<int>
     */
    public function regionIds(): array
    {
        return $this->resolvedRegionIds ?? throw new LogicException('Régions non résolues.');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('orders.dashboard.attributes');
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

    /** `?region=`, s'il est bien formé (même lecture que la règle « integer ») ; null : la validation répond. */
    private function regionFilter(): ?int
    {
        $value = $this->query('region');
        $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $id === false ? null : $id;
    }
}
