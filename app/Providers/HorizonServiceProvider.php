<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Outside the local environment only the listed accounts may open
        // the queue dashboard (comma-separated e-mail addresses).
        Gate::define('viewHorizon', function ($user = null) {
            $admins = array_filter(array_map('trim', explode(',', (string) config('importer.horizon_admins'))));

            return $user !== null && in_array($user->email, $admins, true);
        });
    }
}
