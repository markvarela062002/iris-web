<?php

/*
|--------------------------------------------------------------------------
| School FTP Disks
|--------------------------------------------------------------------------
|
| Every school has three remote storage locations:
|
| uploads     - uploaded documents and journal uploads
| person_task - activity files, journal evidence and officer signatures
| esig        - student electronic signatures
|
*/

$schools = [
    'demo' => 'DEMO',
    'exact' => 'EXACT',
    'igcfi' => 'IGCFI',
    'mcl' => 'MCL',
    'mci' => 'MCI',
    'uph' => 'UPH',
    'uphsd' => 'UPHSD',
];

$schoolDisks = [];

foreach ($schools as $diskCode => $environmentPrefix) {
    $connection = [
        'driver' => 'ftp',

        'host' => env(
            "{$environmentPrefix}_FTP_HOST",
        ),

        'username' => env(
            "{$environmentPrefix}_FTP_USERNAME",
        ),

        'password' => env(
            "{$environmentPrefix}_FTP_PASSWORD",
        ),

        'port' => (int) env(
            "{$environmentPrefix}_FTP_PORT",
            21,
        ),

        'passive' => (bool) env(
            "{$environmentPrefix}_FTP_PASSIVE",
            true,
        ),

        'ssl' => (bool) env(
            "{$environmentPrefix}_FTP_SSL",
            false,
        ),

        'timeout' => (int) env(
            "{$environmentPrefix}_FTP_TIMEOUT",
            30,
        ),

        'throw' => true,
    ];

    /*
     * Documents and journal uploads.
     *
     * Examples:
     * admapro_demo_uploads
     * admapro_exact_uploads
     * admapro_igcfi_uploads
     */
    $schoolDisks[
        "admapro_{$diskCode}_uploads"
    ] = array_merge(
        $connection,
        [
            'root' => env(
                "{$environmentPrefix}_FTP_ROOT",
            ),
        ],
    );

    /*
     * Activity files and journal objective evidence.
     *
     * Examples:
     * admapro_demo_person_task
     * admapro_exact_person_task
     * admapro_igcfi_person_task
     */
    $schoolDisks[
        "admapro_{$diskCode}_person_task"
    ] = array_merge(
        $connection,
        [
            'root' => env(
                "{$environmentPrefix}_ACTIVITY_FTP_ROOT",
            ),
        ],
    );

    /*
     * Student electronic signatures.
     *
     * Examples:
     * admapro_demo_esig
     * admapro_exact_esig
     * admapro_igcfi_esig
     */
    $schoolDisks[
        "admapro_{$diskCode}_esig"
    ] = array_merge(
        $connection,
        [
            'root' => env(
                "{$environmentPrefix}_ESIG_ROOT",
            ),
        ],
    );
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env(
        'FILESYSTEM_DISK',
        'local',
    ),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    */

    'disks' => array_merge(
        [
            /*
            |--------------------------------------------------------------------------
            | Local Private Storage
            |--------------------------------------------------------------------------
            */

            'local' => [
                'driver' => 'local',

                'root' => storage_path(
                    'app/private',
                ),

                'serve' => true,
                'throw' => false,
                'report' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Local Public Storage
            |--------------------------------------------------------------------------
            */

            'public' => [
                'driver' => 'local',

                'root' => storage_path(
                    'app/public',
                ),

                'url' =>
                    rtrim(
                        env(
                            'APP_URL',
                            'http://localhost',
                        ),
                        '/',
                    ).
                    '/storage',

                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Amazon S3
            |--------------------------------------------------------------------------
            */

            's3' => [
                'driver' => 's3',

                'key' => env(
                    'AWS_ACCESS_KEY_ID',
                ),

                'secret' => env(
                    'AWS_SECRET_ACCESS_KEY',
                ),

                'region' => env(
                    'AWS_DEFAULT_REGION',
                ),

                'bucket' => env(
                    'AWS_BUCKET',
                ),

                'url' => env(
                    'AWS_URL',
                ),

                'endpoint' => env(
                    'AWS_ENDPOINT',
                ),

                'use_path_style_endpoint' =>
                    env(
                        'AWS_USE_PATH_STYLE_ENDPOINT',
                        false,
                    ),

                'throw' => false,
                'report' => false,
            ],
        ],

        /*
         * Add all school FTP disks.
         */
        $schoolDisks,
    ),

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    */

    'links' => [
        public_path('storage') =>
            storage_path('app/public'),
    ],

];