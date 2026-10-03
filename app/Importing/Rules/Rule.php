<?php

namespace App\Importing\Rules;

/**
 * Validates and normalises one CSV cell.
 *
 * Rules run once per cell on millions of rows, so they are plain objects
 * rather than Laravel validator rules, and they signal failure by returning
 * an Invalid value instead of throwing.
 */
interface Rule
{
    /**
     * @return mixed The value to store, or an Invalid describing the problem.
     */
    public function apply(string $value): mixed;
}
