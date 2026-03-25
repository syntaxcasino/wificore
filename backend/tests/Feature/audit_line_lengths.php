<?php
$files = [
    'app/Services/MikroTik/ZeroConfigPPPoEGenerator.php',
    'app/Services/MikroTik/ZeroConfigHotspotGenerator.php',
    'app/Services/MikroTik/ZeroConfigHybridGenerator.php',
];
$base = '/var/www/html/';
foreach ($files as $f) {
    $lines = file($base . $f);
    $over = 0;
    foreach ($lines as $n => $l) {
        $len = strlen(rtrim($l));
        if ($len > 150) {
            echo $f . ':' . ($n + 1) . ' len=' . $len . "\n";
            $over++;
        }
    }
    if (!$over) {
        echo $f . ': ALL LINES OK (<=150 chars)' . "\n";
    }
}
