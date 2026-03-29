<?php
try {
    $p = new PDO('pgsql:host=wificore-pgbouncer;port=6432;dbname=wms_testing', 'admin', 'secret');
    echo 'DB: ' . $p->query('SELECT current_database()')->fetchColumn() . PHP_EOL;
} catch (Exception $e) {
    echo 'ERR: ' . $e->getMessage() . PHP_EOL;
}
