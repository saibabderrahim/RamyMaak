<?php

namespace App\Http\Requests\Days;

use App\Domain\Days\CutoffKind;
use App\Models\BusinessDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Heures limites et date de livraison d'une journée (§4.3), en modification
 * partielle : `cutoffs.{distribution,ordering,validation,closing}` en
 * « HH:MM » (fuseau métier), `delivery_date` au plus tôt le jour même.
 * L'ordre des heures, la clôture à venir et la journée ouverte sont
 * contrôlés par DayCalendar::updateSchedule.
 */
class UpdateDayRequest extends FormRequest
{
    // Contrôle des droits avant la validation : sans day.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->businessDay());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'cutoffs' => ['sometimes', 'array:'.implode(',', $this->cutoffKeys())],
            'delivery_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.$this->businessDay()->dayKey()],
        ];
        foreach ($this->cutoffKeys() as $key) {
            $rules["cutoffs.{$key}"] = ['sometimes', 'required', 'date_format:H:i'];
        }

        return $rules;
    }

    /**
     * Heures fournies, indexées par valeur de CutoffKind (DayCalendar::updateSchedule).
     *
     * @return array<string, string>
     */
    public function times(): array
    {
        $times = [];
        foreach ((array) $this->validated('cutoffs', []) as $key => $time) {
            $times[strtoupper((string) $key)] = (string) $time;
        }

        return $times;
    }

    public function deliveryDate(): ?CarbonImmutable
    {
        $value = $this->validated('delivery_date');

        return is_string($value) ? BusinessClock::parseDay($value) : null;
    }

    public function businessDay(): BusinessDay
    {
        /** @var BusinessDay */
        return $this->route('businessDay');
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
        $messages = [
            'cutoffs.array' => __('days.cutoffs_format'),
            'delivery_date.date_format' => __('days.date_format'),
            'delivery_date.after_or_equal' => __('days.delivery_before_day', ['day' => $this->businessDay()->day->format('d/m/Y')]),
        ];
        foreach ($this->cutoffKeys() as $key) {
            $messages["cutoffs.{$key}.date_format"] = __('days.time_format');
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function cutoffKeys(): array
    {
        return array_map(fn (CutoffKind $kind) => strtolower($kind->value), CutoffKind::chronological());
    }
}
