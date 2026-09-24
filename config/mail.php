<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env(
                'MAIL_EHLO_DOMAIN',
                parse_url(
                    (string) env(
                        'APP_URL',
                        'http://localhost',
                    ),
                    PHP_URL_HOST,
                ),
            ),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env(
                'MAIL_SENDMAIL_PATH',
                '/usr/sbin/sendmail -bs -i',
            ),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env(
                'MAIL_LOG_CHANNEL',
            ),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    */

    'from' => [
        'address' => env(
            'MAIL_FROM_ADDRESS',
            'hello@example.com',
        ),
        'name' => env(
            'MAIL_FROM_NAME',
            env(
                'APP_NAME',
                'Laravel',
            ),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | IRIS-SAM Student Credential Email
    |--------------------------------------------------------------------------
    |
    | APP_URL is the one central web address for every school.
    | The selected school is differentiated by the CODE placed in the email.
    |
    */

    'iris' => [

        'web_url' => env(
            'APP_URL',
            'http://localhost',
        ),

        'android_url' => env(
            'IRIS_ANDROID_URL',
            'https://play.google.com/store/apps/details?id=com.elosoftbiz.iris',
        ),

        /*
         * Parked for now. Leave blank until the App Store listing is ready.
         */
        'app_store_url' => env(
            'IRIS_APP_STORE_URL',
            '',
        ),

        /*
         * Comma-separated list, e.g.
         * IRIS_MAIL_BCC=trmf.iris.sam@gmail.com,it@company.com
         */
        'bcc' => array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        (string) env(
                            'IRIS_MAIL_BCC',
                            '',
                        ),
                    ),
                ),
            ),
        ),

    ],

];
