<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user may
     * update their profile.
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
        $user = $this->user();

        $connection = $user?->getConnectionName()
            ?: DB::getDefaultConnection();

        return [
            'fname' => [
                'required',
                'string',
                'max:100',
            ],

            'mname' => [
                'nullable',
                'string',
                'max:100',
            ],

            'lname' => [
                'required',
                'string',
                'max:100',
            ],

            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',

                Rule::unique(
                    $connection.'.login',
                    'email',
                )->ignore(
                    $user?->getAuthIdentifier(),
                    'id',
                ),
            ],
        ];
    }

    /**
     * Normalize profile values before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'fname' => trim(
                (string) $this->input('fname'),
            ),

            'mname' => $this->filled('mname')
                ? trim(
                    (string) $this->input('mname'),
                )
                : null,

            'lname' => trim(
                (string) $this->input('lname'),
            ),

            'email' => $this->filled('email')
                ? trim(
                    (string) $this->input('email'),
                )
                : null,
        ]);
    }
}