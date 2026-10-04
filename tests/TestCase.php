<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * Runs after the application boots and before the database traits wipe
     * anything. (RefreshDatabase's own beforeRefreshingDatabase() hook would
     * shadow an override here, because trait methods win over parent ones.)
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $uses = class_uses_recursive(static::class);

        if (array_intersect_key($uses, array_flip([RefreshDatabase::class, DatabaseMigrations::class, DatabaseTruncation::class])) !== []) {
            $this->ensureTestDatabase();
        }

        return parent::setUpTraits();
    }

    /**
     * The database traits drop or empty every table. Refuse unless the
     * connection is in-memory SQLite or a database named for tests, so a stray
     * DB_CONNECTION from .env can never wipe the development database.
     */
    private function ensureTestDatabase(): void
    {
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();

        $isInMemory = $connection->getDriverName() === 'sqlite' && $database === ':memory:';
        $isTestDatabase = (bool) preg_match('/(^|_)test(ing)?$/', $database);

        if (! $isInMemory && ! $isTestDatabase) {
            throw new RuntimeException(
                "Refusing to reset the [{$database}] database on [{$connection->getName()}]: it does not look like a test database. "
                .'Run the suite with `make test`, or set DB_CONNECTION=sqlite DB_DATABASE=:memory: (or use a database named *_testing).'
            );
        }
    }
}
