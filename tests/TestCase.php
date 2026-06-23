<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSafeTestingDatabase();
    }

    /**
     * Prevent RefreshDatabase / migrate:fresh from ever running against a non-testing database.
     */
    private function assertSafeTestingDatabase(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');

        $blocked = ['zaqa_portal', 'zaqa', 'production', 'prod'];

        if ($database === '' || in_array($database, $blocked, true) || ! str_ends_with($database, '_testing')) {
            $this->fail(
                'Unsafe test database configuration: tests must use a dedicated *_testing database, not ['.$database.']. '
                .'Run php artisan config:clear if config:cache was built with the dev database. '
                .'Check phpunit.xml / .env.testing before running php artisan test.'
            );
        }
    }
}
