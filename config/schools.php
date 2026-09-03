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

        'IGCFI' => [
            'code' => strtoupper(
                trim(
                    (string) env(
                        'IGCFI_DB_CODE',
                        'IGCFI',
                    ),
                ),
            ),

            'name' => env(
                'IGCFI_SCHOOL_NAME',
                'INTER-GLOBAL COLLEGE FOUNDATION, INC.',
            ),

            'logo' => env(
                'IGCFI_SCHOOL_LOGO',
                '/images/igcfi.png',
            ),

            'connection' => 'igcfi',
        ],

        'MCL' => [
            'code' => strtoupper(
                trim(
                    (string) env(
                        'MCL_DB_CODE',
                        'MCL',
                    ),
                ),
            ),

            'name' => env(
                'MCL_SCHOOL_NAME',
                'MAPUA MALAYAN COLLEGES',
            ),

            'logo' => env(
                'MCL_SCHOOL_LOGO',
                '/images/mcl.png',
            ),

            'connection' => 'mcl',
        ],

        'MCI' => [
            'code' => strtoupper(
                trim(
                    (string) env(
                        'MCI_DB_CODE',
                        'MCI',
                    ),
                ),
            ),

            'name' => env(
                'MCI_SCHOOL_NAME',
                'MIDWAY COLLEGES INC.',
            ),

            'logo' => env(
                'MCI_SCHOOL_LOGO',
                '/images/mci.png',
            ),

            'connection' => 'mci',
        ],

        'UPH' => [
            'code' => strtoupper(
                trim(
                    (string) env(
                        'UPH_DB_CODE',
                        'UPH',
                    ),
                ),
            ),

            'name' => env(
                'UPH_SCHOOL_NAME',
                'UNIVERSITY OF PERPETUAL HELP SYSTEM JONELTA',
            ),

            'logo' => env(
                'UPH_SCHOOL_LOGO',
                '/images/uph.png',
            ),

            'connection' => 'uph',
        ],

        'UPHSD' => [
            'code' => strtoupper(
                trim(
                    (string) env(
                        'UPHSD_DB_CODE',
                        'UPHSD',
                    ),
                ),
            ),

            'name' => env(
                'UPHSD_SCHOOL_NAME',
                'UNIVERSITY OF PERPETUAL HELP SYSTEM DALTA',
            ),

            'logo' => env(
                'UPHSD_SCHOOL_LOGO',
                '/images/uphsd.png',
            ),

            'connection' => 'uphsd',
        ],
    ],
];