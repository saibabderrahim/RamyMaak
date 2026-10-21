<?php

namespace App\Http\Requests\Documents;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Orders\OrderStatus;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Documents d'une journée (bon de préparation, export ; contrat du lot 6,
 * §2 et §6). Contrôlés avant la validation (403 avant 404 avant 422) : le
 * rôle et la permission du point d'accès, puis la région filtrée, qui doit
 * être dans le périmètre de l'appelant à la date de la journée (RG-S05),
 * sinon 404. La journée est liée par la route (404 si elle n'existe pas).
 */
abstract class DayDocumentRequest extends FormRequest
{
    /** Permission exigée par le point d'accès. */
    abstract protected function permission(): Permission;

    /**
     * Rôles admis.
     *
     * @return list<Role>
     */
    abstract protected function roles(): array;

    /** Région filtrée telle que reçue, avant validation. */
    abstract protected function requestedRegion(): mixed;

    public function authorize(): Response
    {
        /** @var User $user */
        $user = $this->user();
        $role = $user->appRole();
        if ($role === null || ! in_array($role, $this->roles(), true) || ! $user->can($this->permission()->value)) {
            return Response::deny();
        }

        $region = self::positiveInteger($this->requestedRegion());
        if ($region !== null) {
            $visible = $this->perimeter($user);
            if (! ($visible === null ? Region::query()->whereKey($region)->exists() : in_array($region, $visible, true))) {
                return Response::denyAsNotFound(__('errors.NOT_FOUND'));
            }
        }

        return Response::allow();
    }

    /**
     * Régions lues par le document : la région filtrée, sinon celles du
     * périmètre de l'appelant à la date de la journée ; null pour toutes.
     *
     * @return list<int>|null
     */
    public function regionIds(): ?array
    {
        /** @var User $user */
        $user = $this->user();
        $region = self::positiveInteger($this->requestedRegion());

        return $region !== null ? [$region] : $this->perimeter($user);
    }

    /** Journée liée par la route. */
    public function day(): BusinessDay
    {
        $day = $this->route('businessDay');

        return $day instanceof BusinessDay ? $day : throw new LogicException('Journée non liée.');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('documents.request.attributes');
    }

    /**
     * Liste de statuts : texte séparé par des virgules ou liste de textes,
     * casse indifférente ; null si un statut est inconnu ou la liste vide.
     *
     * @return list<OrderStatus>|null
     */
    protected static function parseStatuses(mixed $value): ?array
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

    /** Entier strictement positif lu avant la validation (même lecture que la règle « integer ») ; null : absent ou mal formé. */
    protected static function positiveInteger(mixed $value): ?int
    {
        $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return $id === false ? null : $id;
    }

    /** @return list<int>|null */
    private function perimeter(User $user): ?array
    {
        return app(Perimeter::class)->regionIds($user, $this->day()->day);
    }
}
