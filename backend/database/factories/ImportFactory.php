<?php

namespace Database\Factories;

use App\Domain\Imports\ImportStatus;
use App\Domain\Imports\ImportType;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ImportType::Clients,
            'status' => ImportStatus::Pending,
            'original_name' => 'clients.xlsx',
            'file_path' => 'imports/'.fake()->uuid().'.xlsx',
            'user_id' => User::factory(),
        ];
    }
}
