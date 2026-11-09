<?php
require __DIR__.'/vendor/autoload.php';

use Kreait\Firebase\Factory;

// Menghubungkan ke kredensial dan URL Database Firebase Anda
$factory = (new Factory)
    ->withServiceAccount(__DIR__.'/firebase-credentials.json')
    ->withDatabaseUri('https://sim-presensi-8b713-default-rtdb.firebaseio.com');

$database = $factory->createDatabase();
?>