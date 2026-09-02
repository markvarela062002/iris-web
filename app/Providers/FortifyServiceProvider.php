<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Authenticate against the school database selected
     * by the code entered on the login form.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(
            function (Request $request): ?User {
                $request->validate([
                    'login_name' => [
                        'required',
                        'string',
                        'max:100',
                    ],

                    'password' => [
                        'required',
                        'string',
                        'max:255',
                    ],

                    'code' => [
                        'required',
                        'string',
                        'max:50',
                    ],
                ]);

                $loginName = trim(
                    (string) $request->input(
                        'login_name',
                    ),
                );

                $password = (string) $request->input(
                    'password',
                );

                $submittedCode = strtoupper(
                    trim(
                        (string) $request->input(
                            'code',
                        ),
                    ),
                );

                /*
                 * Read the available schools.
                 */
                $schools = config(
                    'schools.schools',
                    [],
                );

                if (! is_array($schools)) {
                    return null;
                }

                /*
                 * Select the school using only the submitted
                 * login code. There is no default school.
                 */
                $school = $schools[$submittedCode] ?? null;

                if (! is_array($school)) {
                    return null;
                }

                /*
                 * Validate the configured school code.
                 */
                $configuredCode = strtoupper(
                    trim(
                        (string) (
                            $school['code'] ??
                            $submittedCode
                        ),
                    ),
                );

                if (
                    ! hash_equals(
                        $configuredCode,
                        $submittedCode,
                    )
                ) {
                    return null;
                }

                /*
                 * Retrieve the configured connection name.
                 */
                $connection = $school['connection'] ?? null;

                if (
                    ! is_string($connection) ||
                    $connection === ''
                ) {
                    return null;
                }

                /*
                 * Confirm that the connection exists in
                 * config/database.php.
                 */
                $connectionConfig = config(
                    "database.connections.{$connection}",
                );

                if (! is_array($connectionConfig)) {
                    return null;
                }

                /*
                 * Query the selected school's login table.
                 */
                $user = (new User())
                    ->setConnection($connection)
                    ->newQuery()
                    ->whereRaw(
                        'TRIM(login_name) = ?',
                        [$loginName],
                    )
                    ->first();

                if (! $user) {
                    return null;
                }

                /*
                 * Only active accounts may log in.
                 */
                if (! $user->isActive()) {
                    return null;
                }

                /*
                 * Reject expired accounts.
                 */
                if (
                    $user->expiration_date &&
                    now()
                        ->startOfDay()
                        ->greaterThan(
                            $user->expiration_date,
                        )
                ) {
                    return null;
                }

                /*
                 * Legacy ADMAPro login_pass values are
                 * stored as plaintext.
                 */
                $storedPassword = (string) $user->login_pass;

                if (
                    ! hash_equals(
                        $storedPassword,
                        $password,
                    )
                ) {
                    return null;
                }

                /*
                 * Use the selected school database for the
                 * remainder of this request.
                 */
                config([
                    'database.default' => $connection,
                ]);

                DB::setDefaultConnection($connection);

                /*
                 * Save the selected school and connection
                 * in the session.
                 */
                $request->session()->put([
                    'school_code' => $configuredCode,
                    'database_connection' => $connection,
                ]);


                /*
                 * Preserve the selected connection on the
                 * authenticated model.
                 */
                $user->setConnection($connection);

                return $user;
            },
        );
    }

    /**
     * Configure the login page.
     */
    private function configureViews(): void
    {
        Fortify::loginView(
            fn (Request $request) => Inertia::render(
                'auth/Login',
                [
                    'canResetPassword' => false,

                    'status' => $request
                        ->session()
                        ->get('status'),
                ],
            ),
        );
    }

    /**
     * Configure login rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for(
            'login',
            function (Request $request): Limit {
                $loginName = Str::lower(
                    trim(
                        (string) $request->input(
                            'login_name',
                        ),
                    ),
                );

                $code = Str::upper(
                    trim(
                        (string) $request->input(
                            'code',
                        ),
                    ),
                );

                $throttleKey = Str::transliterate(
                    $loginName
                    .'|'
                    .$code
                    .'|'
                    .$request->ip(),
                );

                return Limit::perMinute(5)
                    ->by($throttleKey);
            },
        );
    }
}