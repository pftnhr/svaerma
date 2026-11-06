<?php

declare(strict_types=1);

// Front controller: every request that is not a static file ends up here.

// PHP's built-in dev server: let it serve existing static files itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require __DIR__ . '/../src/bootstrap.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

$config = App\Config::load();
if ($config->isDev()) {
    ini_set('display_errors', '1');
}
date_default_timezone_set('UTC');

$app = new App\App($config);
$proxies = $config->get('trusted_proxies', []);
$app->handle(App\Http\Request::fromGlobals(is_array($proxies) ? array_values(array_map('strval', $proxies)) : []))->send();
