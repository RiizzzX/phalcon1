<?php

/*
 * Modified: prepend directory path of current file, because of this file own different ENV under between Apache and command line.
 * NOTE: please remove this comment.
 */
defined('BASE_PATH') || define('BASE_PATH', getenv('BASE_PATH') ?: realpath(dirname(__FILE__) . '/../..'));
defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');

// Load .env if exists
if (file_exists(BASE_PATH . '/.env')) {
    $env = @parse_ini_file(BASE_PATH . '/.env');
    if ($env === false) {
        throw new \Exception("Failed to parse .env file at " . BASE_PATH . '/.env');
    }
    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
    }
}

return new \Phalcon\Config\Config([
    'database' => [
        'adapter'     => 'Mysql',
        'host'        => getenv('DB_HOST') ?: 'localhost',
        'username'    => getenv('DB_USERNAME') ?: 'root',
        'password'    => getenv('DB_PASSWORD') ?: '',
        'dbname'      => getenv('DB_NAME') ?: 'test',
        'charset'     => 'utf8',
    ],
    'application' => [
        'appDir'         => APP_PATH . '/',
        'controllersDir' => APP_PATH . '/controllers/',
        'modelsDir'      => APP_PATH . '/models/',
        'migrationsDir'  => APP_PATH . '/migrations/',
        'viewsDir'       => APP_PATH . '/views/',
        'pluginsDir'     => APP_PATH . '/plugins/',
        'libraryDir'     => APP_PATH . '/library/',
        'cacheDir'       => BASE_PATH . '/cache/',
        'baseUri'        => '/',
    ]
]);
