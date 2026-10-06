<?php
declare(strict_types=1);

namespace Hub\Modules\Ordering;

use Hub\Auth\Auth;
use Hub\Clock;
use Hub\Db;
use Hub\Http\HttpError;
use Hub\Http\Request;
use Hub\Http\Response;
use Hub\Money;

/** The hub ingredient catalogue, priced at cost (SPEC s7.2, s7.3). Franchise users never reach it. */
final class IngredientController
{
    /** GET /api/ingredients - hq + hub. hq may add ?include_inactive=1. */
    public static function list(Request $req, array $params): Response
    {
        $user = Auth::requireRole($req, 'hq', 'hub');
        $includeInactive = $user['role'] === 'hq' && $req->query('include_inactive') === '1';
        $rows = Db::all(
            'SELECT sku, name, unit, cost_cents, fincon_item_no, is_sample, active FROM ingredients'
            . ($includeInactive ? '' : ' WHERE active = 1') . ' ORDER BY sku'
        );
        return Response::ok(['ingredients' => array_map([self::class, 'shape'], $rows)]);
    }

    /** PUT /api/admin/ingredients/{sku} - hq only. 201 created, 200 updated. */
    public static function put(Request $req, array $params): Response
    {
        Auth::requireRole($req, 'hq');
        $body = $req->json();
        $sku = (string) $params['sku'];

        $errors = [];
        $name = self::text($body['name'] ?? null, 150);
        if ($name === null) {
            $errors['name'] = 'required, 1-150 characters';
        }
        $unit = self::text($body['unit'] ?? null, 20);
        if ($unit === null) {
            $errors['unit'] = 'required, 1-20 characters';
        }
        $costCents = Money::parseCents($body['cost_price'] ?? null);
        if ($costCents === null) {
            $errors['cost_price'] = 'must be a number >= 0 with at most 2 decimals';
        }
        $finconItemNo = null;
        $rawItemNo = $body['fincon_item_no'] ?? null;
        if ($rawItemNo !== null) {
            if (!is_string($rawItemNo) || mb_strlen(trim($rawItemNo)) > 25) {
                $errors['fincon_item_no'] = 'must be text of at most 25 characters, or null';
            } elseif (trim($rawItemNo) !== '') {
                $finconItemNo = trim($rawItemNo);
            }
        }
        $active = $body['active'] ?? true;
        if (!is_bool($active)) {
            $errors['active'] = 'must be true or false';
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }

        $created = Db::transaction(static function () use ($sku, $name, $unit, $costCents, $finconItemNo, $active): bool {
            $now = Clock::nowDb();
            $exists = Db::value('SELECT COUNT(*) FROM ingredients WHERE sku = ?', [$sku]);
            if ((int) $exists > 0) {
                Db::exec(
                    'UPDATE ingredients SET name = ?, unit = ?, cost_cents = ?, fincon_item_no = ?, active = ?, updated_at = ? WHERE sku = ?',
                    [$name, $unit, $costCents, $finconItemNo, $active ? 1 : 0, $now, $sku]
                );
                return false;
            }
            Db::exec(
                'INSERT INTO ingredients (sku, name, unit, cost_cents, fincon_item_no, is_sample, active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)',
                [$sku, $name, $unit, $costCents, $finconItemNo, $active ? 1 : 0, $now, $now]
            );
            return true;
        });

        $row = Db::one('SELECT sku, name, unit, cost_cents, fincon_item_no, is_sample, active FROM ingredients WHERE sku = ?', [$sku]);
        return Response::ok(['ingredient' => self::shape($row ?? [])], $created ? 201 : 200);
    }

    /** One ingredient as SPEC s7.2. */
    public static function shape(array $row): array
    {
        return [
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'cost_price' => Money::toRands((int) $row['cost_cents']),
            'fincon_item_no' => $row['fincon_item_no'] === null ? null : (string) $row['fincon_item_no'],
            'is_sample' => (int) $row['is_sample'] === 1,
            'active' => (int) $row['active'] === 1,
        ];
    }

    /** Trimmed text of 1..$max characters, or null. */
    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $len = mb_strlen($value);
        return ($len >= 1 && $len <= $max && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) ? $value : null;
    }
}
