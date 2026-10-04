<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * RefreshDatabase drops every table. Refuse unless the connection is an
     * in-memory SQLite database or one whose name says it is for tests, so a
     * stray DB_CONNECTION from .env can never wipe the development database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();

        $isInMemory = $connection->getDriverName() === 'sqlite' && $database === ':memory:';
        $isTestDatabase = (bool) preg_match('/(^|_)test(ing)?$/', $database);

        if (! $isInMemory && ! $isTestDatabase) {
            throw new RuntimeException(
                "Refusing to refresh the [{$database}] database on [{$connection->getName()}]: it does not look like a test database. "
                .'Run the suite with `make test`, or set DB_CONNECTION=sqlite DB_DATABASE=:memory: (or a database named *_testing).'
            );
        }
    }
}
