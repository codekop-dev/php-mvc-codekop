<?php

declare(strict_types=1);
use System\Config;

/*
 * Satu sumber konfigurasi aplikasi.
 *
 * Default aman adalah production. Untuk development set environment variable
 * APP_ENV=development (atau ubah nilai 'env' di bawah). Jangan memakai
 * development di server publik.
 * Constant APP_ENV dibuat untuk menjaga kompatibilitas API lama, tetapi kode baru dapat membaca APP_CONFIG.
 */
$appConfig = [
    'env' => in_array(getenv('APP_ENV'), ['development', 'testing', 'production'], true)
        ? (string) getenv('APP_ENV')
        : 'production',
    'timezone' => 'Asia/Jakarta',
    'session' => [
        'name' => 'codekop_session',
        'path' => ROOTPATH . 'storage/sessions',
        'cookie_path' => '/',
        'same_site' => 'Lax',
        // Batas idle session dalam detik; middleware aplikasi dapat memakai aturan ini.
        'idle_timeout' => 3 * 60 * 60,
        // Opsional: true akan membersihkan file session stale saat session dimulai.
        'cleanup' => [
            'enabled' => false,
        ],
    ],
    'api' => [
        'jwt_secret' => '',
        'jwt_leeway' => 0,
    ],
    'queue' => [
        'driver' => 'file',
        'default' => 'default',
        'path' => ROOTPATH . 'storage/queue',
        'retry_after' => 90,
        'sleep' => 3,
        'max_attempts' => 3,
        'delay' => 0,
        'database' => [
            'table' => 'jobs',
            'failed_table' => 'failed_jobs',
            'auto_create' => true,
        ],
    ],
];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['SERVER_NAME'] ?? 'localhost';
$script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$script = ($script === '.' || $script === '/') ? '' : $script;
$appConfig['base_url'] = rtrim($scheme . '://' . $host . $script, '/') . '/';

Config::configure($appConfig);
if (!defined('APP_CONFIG')) {
    define('APP_CONFIG', Config::all());
}

$legacyConfig = [
    'APP_ENV' => $appConfig['env'],
    'APP_TIMEZONE' => $appConfig['timezone'],
    'SESSION_NAME' => $appConfig['session']['name'],
    'SESSION_PATH' => $appConfig['session']['path'],
    'SESSION_COOKIE_PATH' => $appConfig['session']['cookie_path'],
    'SESSION_SAMESITE' => $appConfig['session']['same_site'],
    'API_JWT_SECRET' => $appConfig['api']['jwt_secret'],
    'API_JWT_LEEWAY' => $appConfig['api']['jwt_leeway'],
];

foreach ($legacyConfig as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

date_default_timezone_set(APP_TIMEZONE);
ini_set('default_charset', 'UTF-8');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', SESSION_SAMESITE);

Config::$timeZone = APP_TIMEZONE;
Config::timeZone();
