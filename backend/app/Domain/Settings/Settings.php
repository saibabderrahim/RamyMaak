<?php

namespace App\Domain\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Paramètres généraux (EF-ADM-04) : valeurs par défaut de
 * config('ramymaak.settings') surchargées par la table `settings`.
 * Les clés sont plates (« cutoffs.ordering ») ; l'API les expose imbriquées.
 */
final class Settings
{
    private const CACHE_KEY = 'ramymaak.settings';

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->resolved ??= $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            $stored = Setting::query()->pluck('value', 'key')->all();

            return array_merge(self::defaults(), array_intersect_key($stored, self::defaults()));
        });
    }

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, self::defaults())) {
            throw new \InvalidArgumentException("Paramètre inconnu : {$key}");
        }

        return $this->all()[$key];
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /**
     * Valide puis enregistre une mise à jour partielle exprimée sous forme
     * imbriquée (ex. ['cutoffs' => ['ordering' => '13:30']]).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{old: mixed, new: mixed}> Changements effectifs
     *
     * @throws ValidationException
     */
    public function update(array $input, ?User $by): array
    {
        $values = $this->validate($input);
        $current = $this->all();

        $changes = [];
        foreach ($values as $key => $value) {
            if ($current[$key] !== $value) {
                $changes[$key] = ['old' => $current[$key], 'new' => $value];
            }
        }

        if ($changes === []) {
            return [];
        }

        DB::transaction(function () use ($changes, $by): void {
            foreach ($changes as $key => $change) {
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $change['new'], 'updated_by' => $by?->id],
                );
            }

            activity('settings')
                ->causedBy($by)
                ->event('updated')
                ->withProperties([
                    'old' => array_map(fn (array $c) => $c['old'], $changes),
                    'attributes' => array_map(fn (array $c) => $c['new'], $changes),
                ])
                ->log('Paramètres modifiés');
        });

        $this->flush();

        return $changes;
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return config('ramymaak.settings');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        $time = ['date_format:H:i'];

        return [
            'cutoffs.distribution' => $time,
            'cutoffs.ordering' => $time,
            'cutoffs.validation' => $time,
            'cutoffs.closing' => $time,
            'cutoffs.validation_blocking' => ['boolean'],
            'calendar.working_days' => ['array', 'min:1', 'max:7'],
            'calendar.working_days.*' => ['integer', 'between:0,6', 'distinct'],
            'limits.no_limit_policy' => ['in:FREE,REQUIRED'],
            'orders.max_per_day' => ['integer', 'between:1,10'],
            'orders.min_amount' => ['nullable', 'numeric', 'min:0'],
            'sessions.remember_days' => ['integer', 'between:1,365'],
            'sessions.staff_absolute_hours' => ['integer', 'between:1,24'],
            'sessions.staff_idle_minutes' => ['integer', 'between:5,720'],
            'passwords.min_length' => ['integer', 'between:8,64'],
            'passwords.min_length_staff' => ['integer', 'between:8,64'],
            'auth.max_failed_logins' => ['integer', 'between:3,20'],
            'auth.lockout_minutes' => ['integer', 'between:1,1440'],
            'notifications.email_enabled' => ['boolean'],
            'notifications.push_enabled' => ['boolean'],
            'notifications.quiet_start' => $time,
            'notifications.quiet_end' => $time,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> Valeurs validées, indexées par clé plate
     */
    private function validate(array $input): array
    {
        $defaults = self::defaults();

        $unknown = array_filter(
            array_keys(Arr::dot($input)),
            fn (string $path) => ! $this->isKnownPath($path, array_keys($defaults)),
        );
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                array_values($unknown),
                [__('settings.unknown')],
            ));
        }

        $rules = [];
        foreach (self::rules() as $key => $keyRules) {
            $presence = in_array('nullable', $keyRules, true) ? 'present' : 'required';
            $rules[$key] = str_ends_with($key, '.*') ? $keyRules : ['sometimes', $presence, ...$keyRules];
        }

        Validator::make($input, $rules, [], __('settings.attributes'))->validate();

        $values = [];
        foreach (array_keys($defaults) as $key) {
            if (Arr::has($input, $key)) {
                $values[$key] = $this->cast($key, Arr::get($input, $key));
            }
        }

        $this->validateCutoffOrder(array_merge($this->all(), $values));

        return $values;
    }

    /**
     * @param  list<string>  $keys
     */
    private function isKnownPath(string $path, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($path === $key || str_starts_with($path, $key.'.')) {
                return true;
            }
        }

        return false;
    }

    private function cast(string $key, mixed $value): mixed
    {
        $rules = self::rules()[$key];

        return match (true) {
            $value === null => null,
            in_array('boolean', $rules, true) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            in_array('integer', $rules, true) => (int) $value,
            in_array('numeric', $rules, true) => round((float) $value, 2),
            $key === 'calendar.working_days' => collect($value)->map(fn ($d) => (int) $d)->sort()->values()->all(),
            default => $value,
        };
    }

    /**
     * Les jalons de la journée doivent se succéder (§4.3).
     *
     * @param  array<string, mixed>  $values
     */
    private function validateCutoffOrder(array $values): void
    {
        $sequence = ['cutoffs.distribution', 'cutoffs.ordering', 'cutoffs.validation', 'cutoffs.closing'];

        for ($i = 1; $i < count($sequence); $i++) {
            if ($values[$sequence[$i]] <= $values[$sequence[$i - 1]]) {
                throw ValidationException::withMessages([
                    $sequence[$i] => [__('settings.cutoff_order')],
                ]);
            }
        }
    }
}
