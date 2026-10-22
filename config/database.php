<?php

$db_username = env('DB_USERNAME', env("APP_ENV") === "testing" ? "" : "admin");
$dsn = "mongodb://";
if (empty($db_username)) {
    $dsn .= env('DB_HOST', 'localhost').":".env('DB_PORT', 27017)."/".env('DB_ADMIN', 'admin');
}
else {
    $dsn .= env('DB_USERNAME', 'admin').":".env('DB_PASSWORD', '')."@".env('DB_HOST', 'localhost').":".env('DB_PORT', 27017)."/".env('DB_ADMIN', 'admin');
}

return [
    'default' => env('DB_CONNECTION', 'mongodb'),

    'connections' => [
        'mongodb' => [
            'driver' => 'mongodb',
            'dsn'=> $dsn,
            'database' => env('DB_DATABASE', 'jikan'),
        ]
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'jikan_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 0),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_CACHE_DB', 1),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
