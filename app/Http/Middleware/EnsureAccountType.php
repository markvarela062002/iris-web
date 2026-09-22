<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountType
{
    /**
     * Ensure the authenticated account has one of the
     * permitted account types.
     *
     * @param string ...$types
     */
    public function handle(
        Request $request,
        Closure $next,
        string ...$types,
    ): Response {
        $account = $request->user();

        if (! $account) {
            return redirect()->route('login');
        }

        $accountType = (string) (
            $account->account_type ?? ''
        );

        if (
            in_array(
                $accountType,
                $types,
                true,
            )
        ) {
            return $next($request);
        }

        /*
         * API requests should receive a proper JSON
         * authorization error.
         */
        if ($request->expectsJson()) {
            abort(
                Response::HTTP_FORBIDDEN,
                'You are not authorized to access this resource.',
            );
        }

        /*
         * Redirect authenticated accounts to their
         * appropriate dashboard.
         */
        return $accountType === 'student'
            ? redirect()->route(
                'student.dashboard',
            )
            : redirect()->route(
                'dashboard',
            );
    }
}