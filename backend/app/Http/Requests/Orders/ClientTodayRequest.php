<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

/**
 * Journée du client (EF-CLT-01, EF-CLT-02) : `?day=AAAA-MM-JJ`, facultatif ;
 * sans journée, celle que propose OrderingWindow::defaultDay (I-42).
 * `order.view` et `limit.view` (ses bornes et disponibles).
 */
class ClientTodayRequest extends ClientOrderRequest
{
    protected function permissions(): array
    {
        return [Permission::OrderView, Permission::LimitView];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'day' => ['nullable', ...$this->dayRules()],
        ];
    }

    /** Journée demandée, ou null : journée proposée. */
    public function day(): ?CarbonImmutable
    {
        $day = $this->validated('day');

        return is_string($day) ? BusinessClock::parseDay($day) : null;
    }
}
