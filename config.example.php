<?php

/**
 * Copy this file to config.php and fill in Hostinger MySQL details from hPanel.
 *
 * Local development without MySQL: set driver to sqlite (see README).
 */
return [
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'name' => 'inspections',
        'user' => 'YOUR_DB_USER',
        'pass' => 'YOUR_DB_PASSWORD',
        'charset' => 'utf8mb4',
        'path' => __DIR__ . '/storage/app.sqlite',
    ],
    'app' => [
        'name' => 'Field Inspections',
        'base_url' => '',
        'session_name' => 'inspect_demo',
        'debug' => false,
    ],
];
