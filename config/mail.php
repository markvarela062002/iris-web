<?php

return [
    'default' => env('MAIL_MAILER', 'log'),

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
                    (string) env('APP_URL', 'http://localhost'),
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
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
        'array' => [
            'transport' => 'array',
        ],
        'failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
            'retry_after' => 60,
        ],
        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => ['ses', 'postmark'],
            'retry_after' => 60,
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env(
            'MAIL_FROM_NAME',
            env('APP_NAME', 'Laravel'),
        ),
    ],

    'iris' => [
        'web_url' => env('APP_URL', 'http://localhost'),
        'asset_url' => env(
            'IRIS_MAIL_ASSET_URL',
            'https://iris.trmfoundation.com',
        ),
        'logo' => env(
            'IRIS_MAIL_LOGO',
            '/images/iris.png',
        ),
        'elosoft_logo' => env(
            'IRIS_MAIL_ELOSOFT_LOGO',
            '/images/es.png',
        ),
        'android_url' => env(
            'IRIS_ANDROID_URL',
        ) ?: 'https://play.google.com/store/apps/details?id=com.elosoftbiz.iris',
        'app_store_url' => env(
            'IRIS_APP_STORE_URL',
        ) ?: 'https://apps.apple.com/ph/app/iris-sams/id6802582212',
        'bcc' => array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        (string) env('IRIS_MAIL_BCC', ''),
                    ),
                ),
            ),
        ),
    ],
];
