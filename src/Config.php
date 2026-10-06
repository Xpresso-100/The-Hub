<?php
declare(strict_types=1);

namespace Hub;

/**
 * Configuration (SPEC s2.2): real environment variable > config.local.php at the repo root > default.
 * The app never reads .env.
 */
final class Config
{
    private const DEFAULTS = [
        'APP_ENV' => 'production',
        'DB_DRIVER' => 'mysql',
        'DB_HOST' => 'localhost',
        'DB_PORT' => '3306',
        'DB_NAME' => '',
        'DB_USER' => '',
        'DB_PASS' => '',
        'DB_PATH' => 'var/hub.sqlite',
        'AUTH_TOKEN_TTL_HOURS' => '8',
        'FINCON_ADAPTER' => 'http',
        'FINCON_BASE_URL' => '',
        'FINCON_CF_CLIENT_ID' => '',
        'FINCON_CF_CLIENT_SECRET' => '',
        'FINCON_SRV_USER' => '',
        'FINCON_SRV_PASS' => '',
        'FINCON_DATA_ID' => '',
        'FINCON_USER' => '',
        'FINCON_PASS' => '',
        'FINCON_LOC_NO' => '0',
        'FINCON_TIMEOUT_SECONDS' => '15',
        'FINCON_MOCK_LOG' => '',
        'STOCK_CACHE_TTL_SECONDS' => '900',
        'MAIL_TRANSPORT' => '',          // resolved in defaultFor(): mail in production, else log
        'MAIL_LOG_PATH' => 'var/mail.log',
        'MAIL_FROM' => 'noreply@example.invalid',
        'HQ_ORDERS_EMAIL' => '',
    ];

    private static ?array $local = null;

    public static function get(string $key): string
    {
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        $local = self::local();
        if (array_key_exists($key, $local) && $local[$key] !== null) {
            return (string) $local[$key];
        }
        return self::defaultFor($key);
    }

    /** Integer value; a non-integer setting falls back to the default. */
    public static function int(string $key): int
    {
        $value = trim(self::get($key));
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        return (int) self::defaultFor($key);
    }

    public static function env(): string
    {
        return strtolower(trim(self::get('APP_ENV')));
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }

    /** A path setting resolved against the repo root when relative. */
    public static function path(string $key): string
    {
        return self::resolvePath(self::get($key));
    }

    public static function resolvePath(string $path): string
    {
        if ($path === '' || preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $path) === 1) {
            return $path;
        }
        return HUB_ROOT . '/' . $path;
    }

    /** Drop the cached config.local.php (tests that rewrite it). */
    public static function reset(): void
    {
        self::$local = null;
    }

    private static function defaultFor(string $key): string
    {
        if ($key === 'MAIL_TRANSPORT') {
            return self::isProduction() ? 'mail' : 'log';
        }
        return self::DEFAULTS[$key] ?? '';
    }

    private static function local(): array
    {
        if (self::$local === null) {
            $file = HUB_ROOT . '/config.local.php';
            $data = is_file($file) ? require $file : [];
            self::$local = is_array($data) ? $data : [];
        }
        return self::$local;
    }
}
