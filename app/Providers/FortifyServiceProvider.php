<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Models\Student;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        /*
         * Use the custom login response to redirect:
         *
         * - Administrators to /dashboard
         * - Students to /student-dashboard
         */
        $this->app->singleton(
            LoginResponseContract::class,
            LoginResponse::class,
        );
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
     * using the code entered on the login form.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(
            function (Request $request): ?Authenticatable {
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
                 * Read the configured schools.
                 */
                $schools = config(
                    'schools.schools',
                    [],
                );

                if (! is_array($schools)) {
                    return null;
                }

                /*
                 * Select the school using the submitted
                 * school code. There is no default school.
                 */
                $school =
                    $schools[$submittedCode] ?? null;

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
                    $configuredCode === '' ||
                    ! hash_equals(
                        $configuredCode,
                        $submittedCode,
                    )
                ) {
                    return null;
                }

                /*
                 * Retrieve the configured database
                 * connection.
                 */
                $connection =
                    $school['connection'] ?? null;

                if (
                    ! is_string($connection) ||
                    trim($connection) === ''
                ) {
                    return null;
                }

                $connection = trim($connection);

                /*
                 * Confirm that the database connection
                 * exists in config/database.php.
                 */
                $connectionConfig = config(
                    "database.connections.{$connection}",
                );

                if (! is_array($connectionConfig)) {
                    return null;
                }

                /*
                 * Use the selected school database for this
                 * authentication request.
                 */
                config([
                    'database.default' => $connection,
                ]);

                DB::setDefaultConnection($connection);
                DB::purge($connection);

                /*
                 * First, attempt authentication against
                 * the administrator-side login table.
                 */
                $user = (new User())
                    ->setConnection($connection)
                    ->newQuery()
                    ->with('loginType')
                    ->whereRaw(
                        'TRIM(login_name) = ?',
                        [$loginName],
                    )
                    ->first();

                if (
                    $user &&
                    $user->isActive() &&
                    ! $this->isUserExpired($user) &&
                    $this->passwordMatches(
                        $user->login_pass,
                        $password,
                    )
                ) {
                    $this->storeAuthenticatedAccount(
                        request: $request,
                        schoolCode: $configuredCode,
                        connection: $connection,
                        accountType: 'administrator',
                        roleId: $user->login_type_id,
                    );

                    $user->setConnection($connection);

                    return $user;
                }

                /*
                 * If no valid login-table account matched,
                 * attempt authentication against person.
                 */
                $student = (new Student())
                    ->setConnection($connection)
                    ->newQuery()
                    ->with('loginType')
                    ->whereRaw(
                        'TRIM(login_name) = ?',
                        [$loginName],
                    )
                    ->first();

                if (
                    ! $student ||
                    ! $student->isActive() ||
                    ! $this->passwordMatches(
                        $student->login_pass,
                        $password,
                    )
                ) {
                    return null;
                }

                $this->storeAuthenticatedAccount(
                    request: $request,
                    schoolCode: $configuredCode,
                    connection: $connection,
                    accountType: 'student',
                    roleId: $student->login_id,
                );

                $student->setConnection($connection);

                return $student;
            },
        );
    }

    /**
     * Determine whether an administrator-side account
     * has expired.
     */
    private function isUserExpired(
        User $user,
    ): bool {
        if (! $user->expiration_date) {
            return false;
        }

        return now()
            ->startOfDay()
            ->greaterThan(
                $user->expiration_date,
            );
    }

    /**
     * Compare the submitted password with the legacy
     * plaintext password.
     */
    private function passwordMatches(
        mixed $storedPassword,
        string $submittedPassword,
    ): bool {
        $storedPassword = (string) $storedPassword;

        if (
            $storedPassword === '' ||
            $submittedPassword === ''
        ) {
            return false;
        }

        return hash_equals(
            $storedPassword,
            $submittedPassword,
        );
    }

    /**
     * Save the selected school and authenticated account
     * information in the session.
     */
    private function storeAuthenticatedAccount(
        Request $request,
        string $schoolCode,
        string $connection,
        string $accountType,
        mixed $roleId,
    ): void {
        $request->session()->put([
            'school_code' => $schoolCode,

            'database_connection' =>
                $connection,

            'account_type' => $accountType,

            'login_type_id' =>
                is_string($roleId)
                    ? $roleId
                    : null,
        ]);
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