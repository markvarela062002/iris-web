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

            /*
             * Base URL containing theoretical-exam.php.
             *
             * This is used for External examination
             * invitation emails.
             */
            'theoretical_external_exam_url' => env(
                'DEMO_THEORETICAL_EXTERNAL_EXAM_URL',
                '',
            ),

            'files' => [
                /*
                 * Uploaded documents from file_upload_d.
                 *
                 * Legacy public directory:
                 * /docs
                 */
                'documents_url' => env(
                    'DEMO_DOCUMENT_FILE_URL',
                    '',
                ),

                /*
                 * Journal uploads and assessment
                 * reference files.
                 *
                 * Legacy public directory:
                 * /uploads
                 */
                'uploads_url' => env(
                    'DEMO_JOURNAL_UPLOAD_URL',
                    '',
                ),

                /*
                 * Activity files, assessment evidence,
                 * and journal objective evidence.
                 *
                 * Legacy public directory:
                 * /person_task
                 */
                'activity_url' => env(
                    'DEMO_ACTIVITY_FILE_URL',
                    '',
                ),

                /*
                 * Student electronic signatures.
                 *
                 * Legacy public directory:
                 * /images
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

            'theoretical_external_exam_url' => env(
                'EXACT_THEORETICAL_EXTERNAL_EXAM_URL',
                '',
            ),

            'files' => [
                'documents_url' => env(
                    'EXACT_DOCUMENT_FILE_URL',
                    '',
                ),

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

            'theoretical_external_exam_url' => env(
                'IGCFI_THEORETICAL_EXTERNAL_EXAM_URL',
                '',
            ),

            'files' => [
                'documents_url' => env(
                    'IGCFI_DOCUMENT_FILE_URL',
                    '',
                ),

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

            'theoretical_external_exam_url' => env(
                'MCL_THEORETICAL_EXTERNAL_EXAM_URL',
                '',
            ),

            'files' => [
                'documents_url' => env(
                    'MCL_DOCUMENT_FILE_URL',
                    '',
                ),

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

            'theoretical_external_exam_url' => env(
                'MCI_THEORETICAL_EXTERNAL_EXAM_URL',
                '',
            ),

            'files' => [
                'documents_url' => env(
                    'MCI_DOCUMENT_FILE_URL',
                    '',
                ),

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

        /*
        |--------------------------------------------------------------------------
        | UPH
        |--------------------------------------------------------------------------
        */

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

            'theoretical_external_exam_url' => env(
                'UPH_THEORETICAL_EXTERNAL_EXAM_URL',
                '',
            ),

            'files' => [
                'documents_url' => env(
                    'UPH_DOCUMENT_FILE_URL',
                    '',
                ),

                'uploads_url' => env(
                    'UPH_JOURNAL_UPLOAD_URL',
                    '',
                ),

                'activity_url' => env(
                    'UPH_ACTIVITY_FILE_URL',
                    '',
                ),

                'signature_url' => env(
                    'UPH_JOURNAL_ESIG_URL',
                    '',
                ),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | UPHSD
        |--------------------------------------------------------------------------
        */

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

            'theoretical_external_exam_url' => env(
                'UPHSD_THEORETICAL_EXTERNAL_EXAM_URL',
                '',
            ),

            'files' => [
                'documents_url' => env(
                    'UPHSD_DOCUMENT_FILE_URL',
                    '',
                ),

                'uploads_url' => env(
                    'UPHSD_JOURNAL_UPLOAD_URL',
                    '',
                ),

                'activity_url' => env(
                    'UPHSD_ACTIVITY_FILE_URL',
                    '',
                ),

                'signature_url' => env(
                    'UPHSD_JOURNAL_ESIG_URL',
                    '',
                ),
            ],
        ],
    ],
];