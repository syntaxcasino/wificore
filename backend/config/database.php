<?php

use Illuminate\Support\Str;

$normalizeEnvNullable = static function (mixed $value): mixed {
    if (! is_string($value)) {
        return $value;
    }

    $trimmed = trim($value);

    if ($trimmed === '' || strcasecmp($trimmed, 'null') === 0) {
        return null;
    }

    return $value;
};

$redisUsername = $normalizeEnvNullable(env('REDIS_USERNAME'));
$redisPassword = $normalizeEnvNullable(env('REDIS_PASSWORD'));
$pgbouncerEmulatePrepares = env('DB_EMULATE_PREPARES', true);
$radiusPgbouncerEmulatePrepares = env('RADIUS_DB_EMULATE_PREPARES', $pgbouncerEmulatePrepares);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'wificore-pgbouncer'),
            'sticky' => true,
            'read' => [
                'host' => array_filter(
                    explode(',', env('DB_READ_HOST', env('DB_HOST', 'wificore-pgbouncer'))),
                    fn ($value) => $value !== ''
                ),
                'port' => env('DB_READ_PORT', env('DB_PORT', '6432')),
            ],
            'write' => [
                'host' => array_filter(
                    explode(',', env('DB_WRITE_HOST', env('DB_HOST', 'wificore-pgbouncer'))),
                    fn ($value) => $value !== ''
                ),
                'port' => env('DB_WRITE_PORT', env('DB_PORT', '6432')),
            ],
            'port' => env('DB_PORT', '6432'),
            'database' => env('DB_DATABASE', 'wifi_hotspot'),
            'username' => env('DB_USERNAME', 'admin'),
            'password' => env('DB_PASSWORD', 'secret'),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
            
            // Connection pooling and performance optimization
            'options' => [
                // PgBouncer owns pooling; persistent PDO connections can keep stale sockets alive.
                PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false),
                
                // Set connection timeout (P2 Security Fix)
                PDO::ATTR_TIMEOUT => env('DB_TIMEOUT', 5),
                
                // PgBouncer rotates backend connections, so server-side prepared
                // statements can disappear between requests/jobs and trigger
                // "prepared statement ... does not exist". Use client-side prepares
                // on pooled connections unless the app talks to Postgres directly.
                PDO::ATTR_EMULATE_PREPARES => $pgbouncerEmulatePrepares,
                
                // Set error mode to exceptions
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                
                // Disable autocommit for better transaction performance
                PDO::ATTR_AUTOCOMMIT => true,
                
                // Set default fetch mode
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ],
            
            // Statement timeout to prevent long-running queries (P2 Security Fix)
            'statement_timeout' => env('DB_STATEMENT_TIMEOUT', 30000), // 30 seconds in milliseconds
            'lock_timeout' => env('DB_LOCK_TIMEOUT', 10000), // 10 seconds in milliseconds
            
            // Pool configuration
            'pool' => [
                'min_connections' => env('DB_POOL_MIN', 2),
                'max_connections' => env('DB_POOL_MAX', 10),
                'idle_timeout' => env('DB_POOL_IDLE_TIMEOUT', 60),
                'wait_timeout' => env('DB_POOL_WAIT_TIMEOUT', 30),
            ],
        ],

        'pgsql_direct' => [
            'driver' => 'pgsql',
            'url' => env('DB_DIRECT_URL'),
            'host' => env('DB_DIRECT_HOST', env('DB_HOST', 'wificore-postgres')),
            'port' => env('DB_DIRECT_PORT', '5432'),
            'database' => env('DB_DATABASE', 'wifi_hotspot'),
            'username' => env('DB_USERNAME', 'admin'),
            'password' => env('DB_PASSWORD', 'secret'),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
            'options' => [
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => env('DB_TIMEOUT', 5),
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_AUTOCOMMIT => true,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ],
            'statement_timeout' => env('DB_STATEMENT_TIMEOUT', 30000),
            'lock_timeout' => env('DB_LOCK_TIMEOUT', 10000),
        ],

        'radius' => [
            'driver' => 'pgsql',
            'url' => env('RADIUS_DB_URL'),
            'host' => env('RADIUS_DB_HOST', env('DB_HOST', 'wificore-pgbouncer')),
            'port' => env('RADIUS_DB_PORT', env('DB_PORT', '6432')),
            'database' => env('RADIUS_DB_DATABASE', env('DB_DATABASE', 'wifi_hotspot')),
            'username' => env('RADIUS_DB_USERNAME', env('DB_USERNAME', 'admin')),
            'password' => env('RADIUS_DB_PASSWORD', env('DB_PASSWORD', 'secret')),
            'charset' => env('RADIUS_DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('RADIUS_DB_SCHEMA', 'public'),
            'sslmode' => env('RADIUS_DB_SSLMODE', 'prefer'),
            'options' => [
                PDO::ATTR_PERSISTENT => env('RADIUS_DB_PERSISTENT', false),
                PDO::ATTR_TIMEOUT => env('RADIUS_DB_TIMEOUT', 3), // Fast timeout for portal responsiveness
                PDO::ATTR_EMULATE_PREPARES => $radiusPgbouncerEmulatePrepares,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_AUTOCOMMIT => true,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ],
            'statement_timeout' => env('RADIUS_DB_STMT_TIMEOUT', 5000), // 5 seconds max per query
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
            'timeout' => env('REDIS_TIMEOUT', 2),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 2),
            'retry_interval' => env('REDIS_RETRY_INTERVAL', 100),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => $redisUsername,
            'password' => $redisPassword,
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 2),
            'timeout' => env('REDIS_TIMEOUT', 2),
            'retry_interval' => env('REDIS_RETRY_INTERVAL', 100),
        ],

        'sse' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => $redisUsername,
            'password' => $redisPassword,
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'persistent' => false,
            'read_timeout' => env('REDIS_SSE_READ_TIMEOUT', 0),
            'timeout' => env('REDIS_SSE_TIMEOUT', 5),
            'retry_interval' => env('REDIS_SSE_RETRY_INTERVAL', 100),
            'tcp_keepalive' => env('REDIS_SSE_TCP_KEEPALIVE', 60),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => $redisUsername,
            'password' => $redisPassword,
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'read_timeout' => env('REDIS_CACHE_TIMEOUT', env('REDIS_READ_TIMEOUT', 2)),
            'timeout' => env('REDIS_CACHE_TIMEOUT', env('REDIS_TIMEOUT', 2)),
            'retry_interval' => env('REDIS_RETRY_INTERVAL', 100),
        ],

    ],

];
