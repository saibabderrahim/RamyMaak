<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Requêtes émises par la PWA : Sanctum les traite en mode SPA (session).
        $this->withHeaders([
            'Accept' => 'application/json',
            'Referer' => 'http://localhost/',
        ]);
    }
}
