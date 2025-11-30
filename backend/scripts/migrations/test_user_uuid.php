<?php

require '/var/www/html/vendor/autoload.php';

$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

$user = User::first();

echo "User Model Test:\n";
echo "================\n";
echo "ID: " . $user->id . "\n";
echo "ID Type: " . gettype($user->id) . "\n";
echo "Key Type: " . $user->getKeyType() . "\n";
echo "Incrementing: " . ($user->getIncrementing() ? 'yes' : 'no') . "\n";
echo "Casts: " . json_encode($user->getCasts()) . "\n";
