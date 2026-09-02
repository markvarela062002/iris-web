<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetSchoolDatabase
{
    /**
     * Restore the database selected during login.
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        /*
         * The school code is stored in the server-side
         * session after successful authentication.
         */
        $schoolCode = strtoupper(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        /*
         * Guests have not selected a school yet.
         */
        if ($schoolCode === '') {
            return $next($request);
        }

        /*
         * Resolve the selected school from trusted
         * server-side configuration.
         */
        $schools = config(
            'schools.schools',
            [],
        );

        abort_unless(
            is_array($schools),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'School configuration is unavailable.',
        );

        $school = $schools[$schoolCode] ?? null;

        abort_unless(
            is_array($school),
            Response::HTTP_FORBIDDEN,
            'The selected school is not configured.',
        );

        /*
         * Confirm that the configured school code matches
         * the code stored in the session.
         */
        $configuredCode = strtoupper(
            trim(
                (string) (
                    $school['code'] ??
                    $schoolCode
                ),
            ),
        );

        abort_unless(
            hash_equals(
                $configuredCode,
                $schoolCode,
            ),
            Response::HTTP_FORBIDDEN,
            'The selected school code is invalid.',
        );

        /*
         * Retrieve the selected database connection.
         */
        $connection = $school['connection'] ?? null;

        abort_unless(
            is_string($connection) &&
            $connection !== '',
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is missing.',
        );

        /*
         * Confirm that the connection exists in
         * config/database.php.
         */
        $connectionConfig = config(
            "database.connections.{$connection}",
        );

        abort_unless(
            is_array($connectionConfig),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The school database connection is not configured.',
        );

        /*
         * Switch connections before Laravel's route-level
         * auth middleware restores the authenticated user.
         */
        config([
            'database.default' => $connection,
        ]);

        DB::setDefaultConnection($connection);

        /*
         * Synchronize the connection stored in the session
         * with the trusted value from config/schools.php.
         */
        $request->session()->put([
            'school_code' => $configuredCode,
            'database_connection' => $connection,
        ]);

        return $next($request);
    }
}