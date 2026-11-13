<?php
/**
 * Application configuration.
 *
 * Values can be overridden WITHOUT editing this file:
 *   1. environment variables (DB_HOST, DB_NAME, DB_USER, DB_PASS), or
 *   2. a config.local.php file that returns an array (copy config.local.example.php).
 * config.local.php is git-ignored so real credentials never reach the repository.
 */
$config = [
    'db_host'  => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'  => getenv('DB_PORT') ?: '3306',
    'db_name'  => getenv('DB_NAME') ?: 'task_tracker',
    'db_user'  => getenv('DB_USER') ?: 'root',
    'db_pass'  => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'per_page' => 10,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}

return $config;
