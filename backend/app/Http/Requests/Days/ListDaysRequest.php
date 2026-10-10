<?php

namespace App\Http\Requests\Days;

use App\Models\BusinessDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Intervalle du calendrier des journées (EF-ADV-01) : `from` et `to`
 * « AAAA-MM-JJ », par défaut d'aujourd'hui − 7 à aujourd'hui + 14 (fuseau
 * métier), écart d'au plus 62 jours. Une seule borne fournie : l'autre garde
 * la largeur de la fenêtre par défaut (21 jours).
 */
class ListDaysRequest extends FormRequest
{
    public const DAYS_BEFORE = 7;

    public const DAYS_AFTER = 14;

    public const MAX_SPAN_DAYS = 62;

    // Contrôle des droits avant la validation : sans droit, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', BusinessDay::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = $this->from();
                $to = $this->to();
                if ($to->lessThan($from)) {
                    $validator->errors()->add('to', __('days.range_order'));
                } elseif ($to->greaterThan($from->addDays(self::MAX_SPAN_DAYS))) {
                    $validator->errors()->add('to', __('days.range_too_long', ['days' => self::MAX_SPAN_DAYS]));
                }
            },
        ];
    }

    public function from(): CarbonImmutable
    {
        return $this->bound('from')
            ?? $this->bound('to')?->subDays(self::DAYS_BEFORE + self::DAYS_AFTER)
            ?? BusinessClock::today()->subDays(self::DAYS_BEFORE);
    }

    public function to(): CarbonImmutable
    {
        return $this->bound('to')
            ?? $this->bound('from')?->addDays(self::DAYS_BEFORE + self::DAYS_AFTER)
            ?? BusinessClock::today()->addDays(self::DAYS_AFTER);
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
        return [
            'from.date_format' => __('days.date_format'),
            'to.date_format' => __('days.date_format'),
        ];
    }

    private function bound(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        return is_string($value) ? BusinessClock::parseDay($value) : null;
    }
}
