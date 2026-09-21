<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | The application uses the web session guard and the custom school-users
    | provider for both administrator and student accounts.
    |
    */

    'defaults' => [
        'guard' => env(
            'AUTH_GUARD',
            'web',
        ),

        'passwords' => env(
            'AUTH_PASSWORD_BROKER',
            'users',
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | The web guard stores the authenticated account identifier in the
    | session. The school-users provider restores either a User or Student.
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | school-users is registered in AppServiceProvider and supports:
    |
    | - App\Models\User using the login table
    | - App\Models\Student using the person table
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'school-users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | Password-reset features are currently disabled in Fortify. This
    | configuration remains available for framework compatibility.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',

            'table' => env(
                'AUTH_PASSWORD_RESET_TOKEN_TABLE',
                'password_reset_tokens',
            ),

            'expire' => 60,

            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => env(
        'AUTH_PASSWORD_TIMEOUT',
        10800,
    ),

];