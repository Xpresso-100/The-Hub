<?php
declare(strict_types=1);

namespace Hub;

/**
 * Time in Africa/Johannesburg (SPEC s2.1). DATETIME columns hold local time as 'Y-m-d H:i:s';
 * JSON timestamps are ISO 8601 with offset.
 */
final class Clock
{
    public const TZ = 'Africa/Johannesburg';

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(self::TZ);
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::tz());
    }

    /** Now as a DATETIME column value. */
    public static function nowDb(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    /** Today as YYYY-MM-DD. */
    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    /** First day of the current month as YYYY-MM-DD. */
    public static function firstOfMonth(): string
    {
        return self::now()->format('Y-m-01');
    }

    /** DATETIME column value -> ISO 8601 with offset; null stays null. */
    public static function iso(?string $dbDateTime): ?string
    {
        if ($dbDateTime === null || $dbDateTime === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($dbDateTime, 0, 19), self::tz());
        return $dt === false ? null : $dt->format(DATE_ATOM);
    }

    /** True for a real calendar date written exactly as YYYY-MM-DD. */
    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
