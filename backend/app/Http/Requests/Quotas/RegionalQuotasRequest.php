<?php

namespace App\Http\Requests\Quotas;

use App\Models\RegionQuota;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Quotas publiés lus par le régional (§7.6, EF-REG-01) : `?day=AAAA-MM-JJ`,
 * aujourd'hui par défaut (fuseau métier).
 */
class RegionalQuotasRequest extends FormRequest
{
    // Contrôle des droits avant la validation : réservé au régional (403 sinon).
    public function authorize(): bool
    {
        return $this->user()->can('viewPublished', RegionQuota::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'day' => ['bail', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || BusinessClock::parseDay($value) === null) {
                    $fail(__('quotas.invalid_day'));
                }
            }],
        ];
    }

    public function day(): CarbonImmutable
    {
        $day = $this->validated('day');

        return $day === null ? BusinessClock::today() : BusinessClock::parseDay($day);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('quotas.attributes');
    }
}
