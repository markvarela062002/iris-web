<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | SQLite is a neutral framework fallback. The school database is selected
    | from the code entered during login and restored from the session.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [
        /*
         * Neutral framework connection.
         */
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env(
                'DB_DATABASE',
                database_path('database.sqlite'),
            ),
            'prefix' => '',
            'foreign_key_constraints' => env(
                'DB_FOREIGN_KEYS',
                true,
            ),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        /*
         * DEMO school connection.
         */
        'demo' => [
            'driver' => 'mysql',
            'url' => env('DEMO_DB_URL'),
            'host' => env(
                'DEMO_DB_HOST',
                '127.0.0.1',
            ),
            'port' => env(
                'DEMO_DB_PORT',
                '3306',
            ),
            'database' => env(
                'DEMO_DB_DATABASE',
            ),
            'username' => env(
                'DEMO_DB_USERNAME',
            ),
            'password' => env(
                'DEMO_DB_PASSWORD',
                '',
            ),
            'unix_socket' => env(
                'DEMO_DB_SOCKET',
                '',
            ),
            'charset' => env(
                'DEMO_DB_CHARSET',
                'utf8mb4',
            ),
            'collation' => env(
                'DEMO_DB_COLLATION',
                'utf8mb4_unicode_ci',
            ),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql')
                ? array_filter([
                    Mysql::ATTR_SSL_CA => env(
                        'DEMO_MYSQL_ATTR_SSL_CA',
                    ),
                ])
                : [],
        ],

        /*
         * EXACT school connection.
         */
        'exact' => [
            'driver' => 'mysql',
            'url' => env('EXACT_DB_URL'),
            'host' => env(
                'EXACT_DB_HOST',
                '127.0.0.1',
            ),
            'port' => env(
                'EXACT_DB_PORT',
                '3306',
            ),
            'database' => env(
                'EXACT_DB_DATABASE',
            ),
            'username' => env(
                'EXACT_DB_USERNAME',
            ),
            'password' => env(
                'EXACT_DB_PASSWORD',
                '',
            ),
            'unix_socket' => env(
                'EXACT_DB_SOCKET',
                '',
            ),
            'charset' => env(
                'EXACT_DB_CHARSET',
                'utf8mb4',
            ),
            'collation' => env(
                'EXACT_DB_COLLATION',
                'utf8mb4_unicode_ci',
            ),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql')
                ? array_filter([
                    Mysql::ATTR_SSL_CA => env(
                        'EXACT_MYSQL_ATTR_SSL_CA',
                    ),
                ])
                : [],
        ],

        /*
         * Optional standard MariaDB connection.
         */
        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('MARIADB_URL'),
            'host' => env(
                'MARIADB_HOST',
                '127.0.0.1',
            ),
            'port' => env(
                'MARIADB_PORT',
                '3306',
            ),
            'database' => env(
                'MARIADB_DATABASE',
                'laravel',
            ),
            'username' => env(
                'MARIADB_USERNAME',
                'root',
            ),
            'password' => env(
                'MARIADB_PASSWORD',
                '',
            ),
            'unix_socket' => env(
                'MARIADB_SOCKET',
                '',
            ),
            'charset' => env(
                'MARIADB_CHARSET',
                'utf8mb4',
            ),
            'collation' => env(
                'MARIADB_COLLATION',
                'utf8mb4_unicode_ci',
            ),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql')
                ? array_filter([
                    Mysql::ATTR_SSL_CA => env(
                        'MARIADB_MYSQL_ATTR_SSL_CA',
                    ),
                ])
                : [],
        ],

        /*
         * Optional PostgreSQL connection.
         */
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('PGSQL_URL'),
            'host' => env(
                'PGSQL_HOST',
                '127.0.0.1',
            ),
            'port' => env(
                'PGSQL_PORT',
                '5432',
            ),
            'database' => env(
                'PGSQL_DATABASE',
                'laravel',
            ),
            'username' => env(
                'PGSQL_USERNAME',
                'root',
            ),
            'password' => env(
                'PGSQL_PASSWORD',
                '',
            ),
            'charset' => env(
                'PGSQL_CHARSET',
                'utf8',
            ),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env(
                'PGSQL_SSLMODE',
                'prefer',
            ),
        ],

        /*
         * Optional SQL Server connection.
         */
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('SQLSRV_URL'),
            'host' => env(
                'SQLSRV_HOST',
                'localhost',
            ),
            'port' => env(
                'SQLSRV_PORT',
                '1433',
            ),
            'database' => env(
                'SQLSRV_DATABASE',
                'laravel',
            ),
            'username' => env(
                'SQLSRV_USERNAME',
                'root',
            ),
            'password' => env(
                'SQLSRV_PASSWORD',
                '',
            ),
            'charset' => env(
                'SQLSRV_CHARSET',
                'utf8',
            ),
            'prefix' => '',
            'prefix_indexes' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    */

    'redis' => [
        'client' => env(
            'REDIS_CLIENT',
            'phpredis',
        ),

        'options' => [
            'cluster' => env(
                'REDIS_CLUSTER',
                'redis',
            ),

            'prefix' => env(
                'REDIS_PREFIX',
                Str::slug(
                    (string) env(
                        'APP_NAME',
                        'laravel',
                    ),
                ).'-database-',
            ),

            'persistent' => env(
                'REDIS_PERSISTENT',
                false,
            ),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env(
                'REDIS_HOST',
                '127.0.0.1',
            ),
            'username' => env(
                'REDIS_USERNAME',
            ),
            'password' => env(
                'REDIS_PASSWORD',
            ),
            'port' => env(
                'REDIS_PORT',
                '6379',
            ),
            'database' => env(
                'REDIS_DB',
                '0',
            ),
            'max_retries' => env(
                'REDIS_MAX_RETRIES',
                3,
            ),
            'backoff_algorithm' => env(
                'REDIS_BACKOFF_ALGORITHM',
                'decorrelated_jitter',
            ),
            'backoff_base' => env(
                'REDIS_BACKOFF_BASE',
                100,
            ),
            'backoff_cap' => env(
                'REDIS_BACKOFF_CAP',
                1000,
            ),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env(
                'REDIS_HOST',
                '127.0.0.1',
            ),
            'username' => env(
                'REDIS_USERNAME',
            ),
            'password' => env(
                'REDIS_PASSWORD',
            ),
            'port' => env(
                'REDIS_PORT',
                '6379',
            ),
            'database' => env(
                'REDIS_CACHE_DB',
                '1',
            ),
            'max_retries' => env(
                'REDIS_MAX_RETRIES',
                3,
            ),
            'backoff_algorithm' => env(
                'REDIS_BACKOFF_ALGORITHM',
                'decorrelated_jitter',
            ),
            'backoff_base' => env(
                'REDIS_BACKOFF_BASE',
                100,
            ),
            'backoff_cap' => env(
                'REDIS_BACKOFF_CAP',
                1000,
            ),
        ],
    ],
];