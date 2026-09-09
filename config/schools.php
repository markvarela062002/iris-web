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
        /*
        |--------------------------------------------------------------------------
        | DEMO
        |--------------------------------------------------------------------------
        */

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
                '/images/iris.png',
            ),

            'connection' => 'demo',

            'files' => [
                /*
                 * Uploaded documents and journal evidence.
                 */
                'uploads_url' => env(
                    'DEMO_JOURNAL_UPLOAD_URL',
                    '',
                ),

                /*
                 * Activity attachments.
                 */
                'activity_url' => env(
                    'DEMO_ACTIVITY_FILE_URL',
                    '',
                ),

                /*
                 * Student electronic signatures.
                 */
                'signature_url' => env(
                    'DEMO_JOURNAL_ESIG_URL',
                    '',
                ),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | EXACT
        |--------------------------------------------------------------------------
        */

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

            'files' => [
                'uploads_url' => env(
                    'EXACT_JOURNAL_UPLOAD_URL',
                    '',
                ),

                'activity_url' => env(
                    'EXACT_ACTIVITY_FILE_URL',
                    '',
                ),

                'signature_url' => env(
                    'EXACT_JOURNAL_ESIG_URL',
                    '',
                ),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | IGCFI
        |--------------------------------------------------------------------------
        */

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

            'files' => [
                'uploads_url' => env(
                    'IGCFI_JOURNAL_UPLOAD_URL',
                    '',
                ),

                'activity_url' => env(
                    'IGCFI_ACTIVITY_FILE_URL',
                    '',
                ),

                'signature_url' => env(
                    'IGCFI_JOURNAL_ESIG_URL',
                    '',
                ),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | MCL
        |--------------------------------------------------------------------------
        */

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

            'files' => [
                'uploads_url' => env(
                    'MCL_JOURNAL_UPLOAD_URL',
                    '',
                ),

                'activity_url' => env(
                    'MCL_ACTIVITY_FILE_URL',
                    '',
                ),

                'signature_url' => env(
                    'MCL_JOURNAL_ESIG_URL',
                    '',
                ),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | MCI
        |--------------------------------------------------------------------------
        */

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

            'files' => [
                'uploads_url' => env(
                    'MCI_JOURNAL_UPLOAD_URL',
                    '',
                ),

                'activity_url' => env(
                    'MCI_ACTIVITY_FILE_URL',
                    '',
                ),

                'signature_url' => env(
                    'MCI_JOURNAL_ESIG_URL',
                    '',
                ),
            ],
        ],
    ],
];