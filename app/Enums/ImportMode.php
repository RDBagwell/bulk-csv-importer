<?php

namespace App\Enums;

enum ImportMode: string
{
    /** One job streams the whole file. */
    case Sequential = 'sequential';

    /** The file is split on record boundaries and the ranges run as a job batch. */
    case Parallel = 'parallel';
}
