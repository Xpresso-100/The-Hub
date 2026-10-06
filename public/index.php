<?php
declare(strict_types=1);

// Front controller (SPEC s2.4-2.5): every request that is not a real file lands here.
// Dev/test: php -S 127.0.0.1:<port> -t public public/index.php

if (PHP_SAPI === 'cli-server') {
    $uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    if ($uriPath !== '/' && preg_match('/\.(php|log|txt|sqlite|sql|md|ini)$/i', $uriPath) !== 1 && is_file(__DIR__ . $uriPath)) {
        return false; // the built-in server sends the static file
    }
}

ini_set('display_errors', '0');
require dirname(__DIR__) . '/src/bootstrap.php';

use Hub\Http\Request;
use Hub\Http\Response;
use Hub\Http\Router;

$request = Request::fromGlobals();

if ($request->method === 'GET' && ($request->path === '/' || $request->path === '/index.php')) {
    Response::html((string) file_get_contents(__DIR__ . '/index.html'))->send();
    return;
}

$router = new Router();
$router->loadFile(HUB_ROOT . '/src/Auth/routes.php');
$moduleRouteFiles = glob(HUB_ROOT . '/src/Modules/*/routes.php') ?: [];
sort($moduleRouteFiles, SORT_STRING);
foreach ($moduleRouteFiles as $routeFile) {
    $router->loadFile($routeFile);
}

$router->handle($request)->send();
