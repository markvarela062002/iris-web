<?php

namespace App\Providers;

use App\Auth\SchoolUserProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        /*
         * Register the custom authentication provider used
         * by config/auth.php.
         *
         * This provider restores either:
         *
         * - App\Models\User from the login table
         * - App\Models\Student from the person table
         */
        Auth::provider(
            'school-users',
            fn (): SchoolUserProvider =>
                new SchoolUserProvider(),
        );

        $this->configureDefaults();
    }

    /**
     * Configure default application behavior.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password =>
                app()->isProduction()
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