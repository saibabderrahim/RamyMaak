<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Models\Order;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Validation\Validator;

/**
 * Journée du client (EF-CLT-01, EF-CLT-02) : `?day=AAAA-MM-JJ`, facultatif ;
 * sans journée, celle que propose OrderingWindow::defaultDay (I-42).
 * `order.view` et `limit.view` (ses bornes et disponibles).
 *
 * `?order={id}` (modification en cours, §5.1) : commande du client (404
 * sinon, avant la validation) ; sa journée par défaut, et le disponible
 * calculé hors de ses quantités tant qu'elle est envoyée.
 */
class ClientTodayRequest extends ClientOrderRequest
{
    private ?Order $editing = null;

    protected function permissions(): array
    {
        return [Permission::OrderView, Permission::LimitView];
    }

    public function authorize(): Response
    {
        $response = parent::authorize();
        $id = $this->query('order');
        if (! $response->allowed() || $id === null || $id === '') {
            return $response;
        }
        $order = is_string($id) && Order::isPublicId($id)
            ? Order::query()->with('businessDay:id,day')->where('client_id', $this->client()->id)->where('public_id', strtoupper($id))->first()
            : null;
        if ($order === null) {
            return Response::denyAsNotFound(__('orders.not_found'));
        }
        $this->editing = $order;

        return $response;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'day' => ['nullable', ...$this->dayRules()],
            'order' => ['nullable', 'string'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $day = $this->input('day');
            if ($this->editing !== null && is_string($day) && ! $validator->errors()->has('day') && $day !== $this->editing->businessDay->dayKey()) {
                $validator->errors()->add('order', __('orders.client.order_other_day'));
            }
        }];
    }

    /** Commande en cours de modification (`?order=`), ou null. */
    public function editing(): ?Order
    {
        return $this->editing;
    }

    /** Journée demandée, sinon celle de la commande modifiée, ou null : journée proposée. */
    public function day(): ?CarbonImmutable
    {
        $day = $this->validated('day') ?? $this->editing?->businessDay->dayKey();

        return is_string($day) ? BusinessClock::parseDay($day) : null;
    }
}
