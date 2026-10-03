<?php

namespace App\Providers;

use App\Importing\Definitions\DefinitionRegistry;
use App\Importing\Processing\ErrorRecorder;
use App\Importing\Processing\ErrorThreshold;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DefinitionRegistry::class, fn (Application $app) => new DefinitionRegistry(
            $app,
            config('importer.definitions'),
            config('importer.default_definition'),
        ));

        $this->app->bind(ErrorRecorder::class, fn () => new ErrorRecorder((int) config('importer.max_stored_errors')));

        $this->app->bind(ErrorThreshold::class, fn () => new ErrorThreshold(
            (float) config('importer.error_threshold_percent'),
            (int) config('importer.error_threshold_min_rows'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for('imports', fn (Request $request) => Limit::perMinute((int) config('importer.uploads_per_minute'))
            ->by((string) $request->user()?->getAuthIdentifier()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Model::shouldBeStrict(! app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
