<?php
declare(strict_types=1);
namespace Config;

defined('BASEPATH') || exit('No direct script access allowed');

/*
| Database groups, similar to common MVC frameworks.
| Set persistent=true only when the PHP-FPM/Apache process and DB server
| are configured for persistent PDO connections.
|
| DB_* environment variables override the defaults below when set (used by
| docker/benchmark/ so benchmark containers can point at an isolated DB
| container instead of this file's local defaults). Unset env vars fall
| back to the values already configured here.
*/
$dbconfig = [
    'default' => [
        'driver'     => 'mysql',
        'hostname'   => getenv('DB_HOST') ?: '127.0.0.1',
        'port'       => (int) (getenv('DB_PORT') ?: 3306),
        'database'   => getenv('DB_NAME') ?: 'estock',
        'username'   => getenv('DB_USER') ?: 'root',
        'password'   => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
        'charset'    => 'utf8mb4',
        'persistent' => false,
        'options'    => [],
    ],
];

// Backward-compatible active connection config.
$dbconfig = $dbconfig['default'];
