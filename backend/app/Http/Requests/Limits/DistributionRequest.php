<?php

namespace App\Http\Requests\Limits;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Models\RegionDay;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Requêtes de la répartition (contrat du lot 4, §2). Contrôlés avant la
 * validation (403 avant 422) : le rôle et la permission, puis la région
 * désignée, qui doit exister, être active et appartenir au périmètre de
 * l'utilisateur à la date de la journée (titulaire ou suppléant, RG-S05
 * pour l'ADV) ; sinon 404. Une région absente ou mal formée est laissée à
 * la validation (422).
 */
abstract class DistributionRequest extends FormRequest
{
    private ?Region $resolvedRegion = null;

    /** Permission exigée : limit.view, limit.edit ou limit.publish. */
    abstract protected function permission(): Permission;

    /** Identifiant de la région désignée, s'il est bien formé ; null : la validation répond. */
    abstract protected function regionId(): ?int;

    /** Rôle exigé en plus de la permission : le régional ; l'ADV et l'Admin lisent par le back-office. */
    protected function allowsRole(?Role $role): bool
    {
        return $role === Role::Regional;
    }

    public function authorize(): Response
    {
        /** @var User $user */
        $user = $this->user();
        if (! $this->allowsRole($user->appRole()) || ! $user->can($this->permission()->value)) {
            return Response::deny();
        }

        $id = $this->regionId();
        if ($id === null) {
            return Response::allow();
        }

        $region = Region::query()->whereKey($id)->where('is_active', true)->first();
        if ($region === null || ! app(Perimeter::class)->canAccessRegion($user, $region->id, $this->businessDay()->day)) {
            return Response::denyAsNotFound(__('errors.NOT_FOUND'));
        }
        $this->resolvedRegion = $region;

        return Response::allow();
    }

    public function businessDay(): BusinessDay
    {
        /** @var BusinessDay */
        return $this->route('businessDay');
    }

    /** Région contrôlée par authorize() (après validation, toujours renseignée). */
    public function region(): Region
    {
        return $this->resolvedRegion ?? throw new LogicException('Région non résolue.');
    }

    /** Journée régionale, créée au besoin (région ajoutée depuis l'ouverture : QUOTA_DRAFT). */
    public function regionDay(): RegionDay
    {
        return app(DayCalendar::class)->regionDayFor($this->businessDay(), $this->region());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('limits.attributes');
    }
}
