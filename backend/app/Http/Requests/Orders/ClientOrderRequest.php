<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Support\BusinessClock;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Requêtes du client sur sa journée et ses commandes (contrat du lot 5, §2).
 * Contrôlés avant la validation (403 avant 422) : rôle Client, permissions
 * du point d'accès, compte rattaché à un client existant. L'ADV, l'Admin et
 * le régional reçoivent 403 : ils lisent les commandes par leurs propres
 * écrans.
 *
 * Une commande désignée par `{order}` (ULID non lié par la route) est
 * ensuite cherchée parmi celles du client, tous ses comptes confondus
 * (Q-11) : identifiant mal formé (404 sans requête) ou commande d'un autre
 * client → 404, toujours avant la validation du corps.
 */
abstract class ClientOrderRequest extends FormRequest
{
    private ?Client $resolvedClient = null;

    private ?Order $resolvedOrder = null;

    /**
     * Permissions exigées, toutes.
     *
     * @return list<Permission>
     */
    abstract protected function permissions(): array;

    public function authorize(): Response
    {
        /** @var User $user */
        $user = $this->user();
        if (! $user->hasAppRole(Role::Client) || $user->client_id === null) {
            return Response::deny();
        }
        foreach ($this->permissions() as $permission) {
            if (! $user->can($permission->value)) {
                return Response::deny();
            }
        }
        $client = Client::query()->find($user->client_id);
        if ($client === null) {
            return Response::deny();
        }
        $this->resolvedClient = $client;

        $id = $this->route('order');
        if ($id === null) {
            return Response::allow();
        }
        // I-56 : ULID en majuscules, recherche insensible à la casse.
        $order = is_string($id) && Order::isPublicId($id)
            ? Order::query()->where('client_id', $client->id)->where('public_id', strtoupper($id))->first()
            : null;
        if ($order === null) {
            return Response::denyAsNotFound(__('orders.not_found'));
        }
        $this->resolvedOrder = $order;

        return Response::allow();
    }

    /** Client du compte, contrôlé par authorize(). */
    public function client(): Client
    {
        return $this->resolvedClient ?? throw new LogicException('Client non résolu.');
    }

    /** Commande désignée par `{order}`, contrôlée par authorize(). */
    public function order(): Order
    {
        return $this->resolvedOrder ?? throw new LogicException('Commande non résolue.');
    }

    /**
     * Journée « AAAA-MM-JJ » valide (BusinessClock::parseDay).
     *
     * @return list<mixed>
     */
    protected function dayRules(): array
    {
        return ['bail', 'string', function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || BusinessClock::parseDay($value) === null) {
                $fail(__('orders.client.day_format'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('orders.client.attributes');
    }
}
