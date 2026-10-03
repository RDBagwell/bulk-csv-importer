<?php

namespace App\Importing\Definitions;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class DefinitionRegistry
{
    /** @var array<string, ImportDefinition> */
    private array $resolved = [];

    /**
     * @param  array<string, class-string<ImportDefinition>>  $definitions
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $definitions,
        private readonly string $default,
    ) {}

    public function get(string $key): ImportDefinition
    {
        if (! isset($this->definitions[$key])) {
            throw new InvalidArgumentException("Unknown import definition [{$key}].");
        }

        return $this->resolved[$key] ??= $this->container->make($this->definitions[$key]);
    }

    public function default(): ImportDefinition
    {
        return $this->get($this->default);
    }
}
