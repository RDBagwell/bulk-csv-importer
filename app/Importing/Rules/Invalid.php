<?php

namespace App\Importing\Rules;

final readonly class Invalid
{
    public function __construct(public string $message) {}
}
