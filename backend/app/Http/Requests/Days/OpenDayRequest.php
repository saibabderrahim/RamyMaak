<?php

namespace App\Http\Requests\Days;

use App\Models\BusinessDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ouverture d'une journée (EF-ADV-01, §4.2 étape 1) : d'aujourd'hui à
 * aujourd'hui + 30 dans le fuseau métier. Un jour non ouvré peut être ouvert
 * (l'écran demande confirmation) ; une journée déjà ouverte répond 409
 * (DayCalendar::open).
 */
class OpenDayRequest extends FormRequest
{
    public const MAX_DAYS_AHEAD = 30;

    // Contrôle des droits avant la validation : sans day.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('create', BusinessDay::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'day' => ['bail', 'required', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                $today = BusinessClock::today();
                $max = $today->addDays(self::MAX_DAYS_AHEAD);

                if ((string) $value < $today->format('Y-m-d')) {
                    $fail(__('days.open_in_past'));
                } elseif ((string) $value > $max->format('Y-m-d')) {
                    $fail(__('days.open_too_far', ['days' => self::MAX_DAYS_AHEAD, 'max' => $max->format('d/m/Y')]));
                }
            }],
        ];
    }

    public function day(): CarbonImmutable
    {
        /** @var CarbonImmutable */
        return BusinessClock::parseDay($this->string('day')->value());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('days.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['day.date_format' => __('days.date_format')];
    }
}
