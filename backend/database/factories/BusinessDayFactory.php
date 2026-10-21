<?php

namespace Database\Factories;

use App\Models\BusinessDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Journée ouverte avec les heures limites par défaut (§4.3). Pour une journée
 * complète (régions comprises), passer par DayCalendar::open().
 *
 * @extends Factory<BusinessDay>
 */
class BusinessDayFactory extends Factory
{
    public function definition(): array
    {
        return self::schedule(BusinessClock::today());
    }

    /** Journée donnée (« AAAA-MM-JJ » ou date), heures limites par défaut. */
    public function on(string|CarbonImmutable $day): static
    {
        $day = is_string($day) ? BusinessClock::parseDay($day) : $day;

        return $this->state(fn () => self::schedule($day));
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'CLOSED', 'closed_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schedule(CarbonImmutable $day): array
    {
        return [
            'day' => $day->format('Y-m-d'),
            'delivery_date' => $day->addDay()->format('Y-m-d'),
            'distribution_cutoff_at' => BusinessClock::at($day, '10:00')->utc(),
            'ordering_cutoff_at' => BusinessClock::at($day, '13:00')->utc(),
            'validation_cutoff_at' => BusinessClock::at($day, '15:00')->utc(),
            'closing_at' => BusinessClock::at($day, '23:00')->utc(),
        ];
    }
}
