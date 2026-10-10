<?php

namespace Database\Factories;

use App\Models\BusinessDay;
use App\Models\Region;
use App\Models\RegionDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegionDay>
 */
class RegionDayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_day_id' => BusinessDay::factory(),
            'region_id' => Region::factory(),
        ];
    }

    /** Quotas déjà publiés une fois (version 1). */
    public function published(): static
    {
        return $this->state(fn () => [
            'status' => 'QUOTA_PUBLISHED',
            'quota_version' => 1,
            'quota_published_at' => now(),
            'quota_last_published_at' => now(),
        ]);
    }
}
