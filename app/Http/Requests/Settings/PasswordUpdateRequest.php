<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class PasswordUpdateRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user may
     * update their password.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => [
                'required',
                'string',
                'max:255',
            ],

            'password' => [
                'required',
                'string',
                'confirmed',
                'different:current_password',
                'max:255',
                Password::min(8),
            ],
        ];
    }

    /**
     * Validate the current legacy ADMAPro password.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user) {
                    $validator->errors()->add(
                        'current_password',
                        __('Unable to identify the authenticated user.'),
                    );

                    return;
                }

                $storedPassword = (string) $user->login_pass;

                $submittedPassword = (string) $this->input(
                    'current_password',
                );

                if (
                    ! hash_equals(
                        $storedPassword,
                        $submittedPassword,
                    )
                ) {
                    $validator->errors()->add(
                        'current_password',
                        __('The current password is incorrect.'),
                    );
                }
            },
        ];
    }
}