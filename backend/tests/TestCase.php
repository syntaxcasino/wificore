<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Route through the production PgBouncer pool (write + read) so that tests
        // exercise the same database path used in production.
        //
        // Write connection : wificore-pgbouncer       (primary, port 6432)
        // Read  connection : wificore-pgbouncer-read  (replica, port 6432)
        // Database         : wms_770_ts               (production database)
        //
        // PDO::ATTR_PERSISTENT is forced OFF so that DB::purge()/reconnect() in
        // TenantTestHelper actually closes the PDO socket and lets PgBouncer issue
        // a server-side ROLLBACK before returning the connection to its pool.
        $vars = [
            'DB_CONNECTION'  => 'pgsql',
            'DB_HOST'        => 'wificore-pgbouncer',
            'DB_PORT'        => '6432',
            'DB_WRITE_HOST'  => 'wificore-pgbouncer',
            'DB_WRITE_PORT'  => '6432',
            'DB_READ_HOST'   => 'wificore-pgbouncer-read',
            'DB_READ_PORT'   => '6432',
            'DB_DATABASE'    => 'wms_770_ts',
            'DB_USERNAME'    => 'admin',
            'DB_PASSWORD'    => 'secret',
            'DB_PERSISTENT'  => 'false',
        ];

        foreach ($vars as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // Patch the already-resolved config to guarantee correct values even when
        // the config was partially cached before this bootstrap.
        $cfg = config('database.connections.pgsql');
        $cfg['database']                       = 'wms_770_ts';
        $cfg['username']                       = 'admin';
        $cfg['password']                       = 'secret';
        $cfg['sticky']                         = true;   // reads use write PDO after any write in same request
        $cfg['read']                           = ['host' => ['wificore-pgbouncer-read'], 'port' => 6432];
        $cfg['write']                          = ['host' => ['wificore-pgbouncer'],      'port' => 6432];
        $cfg['options'][\PDO::ATTR_PERSISTENT] = false;
        config(['database.connections.pgsql' => $cfg]);

        return $app;
    }
}
