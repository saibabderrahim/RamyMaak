<?php

namespace App\Http\Requests\Days;

use App\Domain\Days\CutoffKind;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Support\BusinessClock;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Prolongation de l'heure limite de répartition, de commande ou de validation
 * d'une région (RG-A05, I-71) : `kind` (DISTRIBUTION, ORDERING ou VALIDATION)
 * et `until` (« HH:MM », fuseau métier), une heure à venir. DayCalendar::extendCutoff vérifie qu'elle suit
 * l'heure limite actuelle et ne dépasse pas la clôture.
 */
class ExtendCutoffRequest extends FormRequest
{
    // Contrôle des droits avant la validation : 403 sans droit, 404 pour une
    // région hors périmètre ce jour-là ou inactive.
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('extendCutoff', [$this->businessDay(), $this->region()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(array_map(fn (CutoffKind $kind) => $kind->value, CutoffKind::extendable()))],
            'until' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * Une prolongation qui serait déjà échue n'a pas de sens : l'heure demandée
     * est à venir. Une journée clôturée répond 409 DAY_NOT_OPEN (contrôleur).
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('until') || ! $this->businessDay()->isOpen()) {
                    return;
                }

                if (BusinessClock::at($this->businessDay()->day, $this->until())->lessThanOrEqualTo(now())) {
                    $validator->errors()->add('until', __('days.extension_in_past'));
                }
            },
        ];
    }

    public function kind(): CutoffKind
    {
        return CutoffKind::from($this->string('kind')->value());
    }

    public function until(): string
    {
        return $this->string('until')->value();
    }

    public function businessDay(): BusinessDay
    {
        /** @var BusinessDay */
        return $this->route('businessDay');
    }

    public function region(): Region
    {
        /** @var Region */
        return $this->route('region');
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
            'kind.in' => __('days.not_extendable'),
            'until.date_format' => __('days.time_format'),
        ];
    }
}
