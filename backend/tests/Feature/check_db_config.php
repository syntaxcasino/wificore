<?php
putenv('APP_ENV=testing');
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$cfg = config('database.connections.pgsql');
echo "host="  . $cfg['host']     . "\n";
echo "port="  . $cfg['port']     . "\n";
echo "db="    . $cfg['database'] . "\n";
echo "user="  . $cfg['username'] . "\n";
echo "search_path=" . ($cfg['search_path'] ?? 'NOT SET') . "\n";
