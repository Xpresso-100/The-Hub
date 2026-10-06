<?php
declare(strict_types=1);

// Shared bootstrap for the front controller, bin/ scripts and white-box tests (SPEC s2.4).

if (!defined('HUB_ROOT')) {
    define('HUB_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Hub\\', 4) !== 0) {
        return;
    }
    $file = HUB_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('Africa/Johannesburg');

// Warnings and notices become exceptions, so a half-done write never answers 200.
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// Last resort. Routes are wrapped by Router::handle(); this catches bootstrap/route-loading failures.
set_exception_handler(static function (\Throwable $e): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    error_log('HUB-ERROR ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        \Hub\Http\Response::error(500, 'SERVER_ERROR', 'Something went wrong. Please try again.')->send();
    }
});
