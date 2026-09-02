<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(
        Request $request,
    ): Response {
        return Inertia::render(
            'settings/Security',
            [
                'canManageTwoFactor' => false,
                'canManagePasskeys' => false,
                'passkeys' => [],
                'twoFactorEnabled' => false,
                'requiresConfirmation' => false,

                'passwordRules' => Password::defaults()
                    ->toPasswordRulesString(),
            ],
        );
    }

    /**
     * Update the user's legacy ADMAPro password.
     */
    public function update(
        PasswordUpdateRequest $request,
    ): RedirectResponse {
        $user = $request->user();

        /*
         * ADMAPro currently expects plaintext login_pass
         * values. Do not use Hash::make() unless ADMAPro is
         * also updated to support Laravel password hashes.
         */
        $user->login_pass = (string) $request->input(
            'password',
        );

        $user->save();

        Inertia::flash(
            'toast',
            [
                'type' => 'success',
                'message' => __(
                    'Password updated.',
                ),
            ],
        );

        return back();
    }
}