<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('importer:prune-files')->daily()->onOneServer()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
