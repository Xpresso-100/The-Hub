<?php
declare(strict_types=1);

namespace Hub;

/**
 * Exact money and quantity math (SPEC s3.1). Money is stored in integer cents,
 * quantities in integer thousandths ("milli") of the unit. All modules use these helpers.
 */
final class Money
{
    /** round_half_up(a, b) = intdiv(2a + b, 2b) for a >= 0, b > 0. */
    public static function roundHalfUp(int $a, int $b): int
    {
        if ($a < 0 || $b <= 0) {
            throw new \InvalidArgumentException('roundHalfUp expects a >= 0 and b > 0');
        }
        return intdiv(2 * $a + $b, 2 * $b);
    }

    /** Line cost in cents: round_half_up(qty_milli * unit_cost_cents, 1000). */
    public static function lineCents(int $qtyMilli, int $unitCostCents): int
    {
        return self::roundHalfUp($qtyMilli * $unitCostCents, 1000);
    }

    /** Cents -> JSON money number (rands, 2 dp). */
    public static function toRands(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** Milli -> JSON quantity number (3 dp). */
    public static function toQty(int $milli): float
    {
        return round($milli / 1000, 3);
    }

    /** Cents -> "1234.50" (CSV, email). */
    public static function format(int $cents): string
    {
        $abs = abs($cents);
        return ($cents < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Milli -> "1.440" (email, CSV). */
    public static function formatQty(int $milli): string
    {
        $abs = abs($milli);
        return ($milli < 0 ? '-' : '') . intdiv($abs, 1000) . '.' . str_pad((string) ($abs % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** JSON money input -> cents. Null unless a JSON number (not string/bool), >= 0, at most 2 dp. */
    public static function parseCents(mixed $value): ?int
    {
        $cents = self::scaled($value, 2);
        return ($cents !== null && $cents >= 0) ? $cents : null;
    }

    /** JSON quantity input -> milli. Null unless a JSON number with at most 3 dp. Sign is NOT checked. */
    public static function parseMilli(mixed $value): ?int
    {
        return self::scaled($value, 3);
    }

    private static function scaled(mixed $value, int $decimals): ?int
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $factor = 10 ** $decimals;
        if (is_int($value)) {
            return abs($value) <= intdiv(PHP_INT_MAX, $factor) ? $value * $factor : null;
        }
        if (!is_finite($value) || abs($value) > 1e12) {
            return null;
        }
        $scaled = round($value * $factor);
        if (abs($value * $factor - $scaled) > 1e-6) {
            return null; // more decimals than allowed
        }
        return (int) $scaled;
    }
}
