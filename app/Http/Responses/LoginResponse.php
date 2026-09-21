<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Redirect the authenticated account after login.
     */
    public function toResponse(
        $request,
    ): JsonResponse|RedirectResponse {
        $accountType = (string) $request
            ->session()
            ->get('account_type', '');

        $destination = match ($accountType) {
            'student' => route(
                'student.dashboard',
            ),

            'administrator' => route(
                'dashboard',
            ),

            default => route('login'),
        };

        if ($request->wantsJson()) {
            return response()->json([
                'account_type' => $accountType,
                'redirect' => $destination,
            ]);
        }

        return redirect()->to($destination);
    }
}