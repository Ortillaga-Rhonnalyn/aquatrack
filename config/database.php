<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';
$host = aquatrackEnv('DB_HOST', aquatrackEnv('AQUATRACK_DB_HOST', '127.0.0.1'));
$port = aquatrackEnv('DB_PORT', aquatrackEnv('AQUATRACK_DB_PORT','3306'));
$name = aquatrackEnv('DB_NAME', aquatrackEnv('AQUATRACK_DB_NAME','aquatrack_db'));
$user = aquatrackEnv('DB_USER', aquatrackEnv('AQUATRACK_DB_USER','root'));
$pass = aquatrackEnv('DB_PASSWORD', aquatrackEnv('AQUATRACK_DB_PASSWORD',''));
return new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false]
);
