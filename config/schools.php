<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Available Schools
    |--------------------------------------------------------------------------
    |
    | There is intentionally no active or default school.
    | The school is selected using the code entered during login.
    |
    */

    'schools' => [
        'DEMO' => [
            'code' => strtoupper(
                trim(
                    (string) env(
                        'DEMO_DB_CODE',
                        'DEMO',
                    ),
                ),
            ),

            'name' => env(
                'DEMO_SCHOOL_NAME',
                'DEMO MARINA UNIVERSITY',
            ),

            'logo' => env(
                'DEMO_SCHOOL_LOGO',
                '/images/mci.png',
            ),

            'connection' => 'demo',
        ],

        'EXACT' => [
            'code' => strtoupper(
                trim(
                    (string) env(
                        'EXACT_DB_CODE',
                        'EXACT',
                    ),
                ),
            ),

            'name' => env(
                'EXACT_SCHOOL_NAME',
                'EXACT COLLEGES OF ASIA',
            ),

            'logo' => env(
                'EXACT_SCHOOL_LOGO',
                '/images/exact.png',
            ),

            'connection' => 'exact',
        ],
    ],
];