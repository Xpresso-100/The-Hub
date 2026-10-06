<?php
declare(strict_types=1);

namespace Hub\Modules\Recipes;

use Hub\Clock;
use Hub\Db;
use Hub\Http\HttpError;
use Hub\Money;

/**
 * Hub ingredient ledger (SPEC s8.5-8.6). on_hand = SUM(inventory_movements.qty_delta_milli) per location + sku.
 * M2 writes reasons 'batch' and 'adjustment'; M1 writes 'order_delivered'.
 */
final class InventoryService
{
    public const MAX_DELTA_MILLI = 100000 * 1000;

    /**
     * The location an inventory call works on. hub: always its own (any location_id sent is ignored).
     * hq: $requested must be the id of a hub location, else 422 on location_id.
     */
    public static function locationFor(array $user, mixed $requested): int
    {
        if ($user['role'] === 'hub') {
            return (int) $user['location_id'];
        }
        $id = null;
        if (is_int($requested)) {
            $id = $requested;
        } elseif (is_string($requested) && preg_match('/^[1-9][0-9]{0,9}$/', $requested) === 1) {
            $id = (int) $requested;
        }
        if ($id === null || Db::value("SELECT id FROM locations WHERE id = ? AND type = 'hub'", [$id]) === null) {
            throw HttpError::validation(['location_id' => 'a hub location id is required']);
        }
        return $id;
    }

    /** Every active ingredient with its on_hand at $locationId, sorted by sku. */
    public static function items(int $locationId): array
    {
        $rows = Db::all(
            'SELECT i.sku, i.name, i.unit,
                    (SELECT COALESCE(SUM(m.qty_delta_milli), 0) FROM inventory_movements m
                      WHERE m.location_id = ? AND m.sku = i.sku) AS on_hand_milli
               FROM ingredients i
              WHERE i.active = 1
              ORDER BY i.sku',
            [$locationId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'unit' => (string) $row['unit'],
                'on_hand' => Money::toQty((int) $row['on_hand_milli']),
            ];
        }
        return $out;
    }

    /** on_hand in milli for one sku at one location. */
    public static function onHandMilli(int $locationId, string $sku): int
    {
        return (int) Db::value(
            'SELECT COALESCE(SUM(qty_delta_milli), 0) FROM inventory_movements WHERE location_id = ? AND sku = ?',
            [$locationId, $sku]
        );
    }

    /** Appends one ledger row and returns its id. */
    public static function record(int $locationId, string $sku, int $deltaMilli, string $reason, ?int $refId, ?string $note, int $userId): int
    {
        return Db::insert(
            'INSERT INTO inventory_movements (location_id, sku, qty_delta_milli, reason, ref_id, note, created_by_user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$locationId, $sku, $deltaMilli, $reason, $refId, $note, $userId, Clock::nowDb()]
        );
    }

    /** POST /api/inventory/adjustments (s8.6). Returns ['movement' => ..., 'on_hand' => float]. */
    public static function adjust(array $user, array $body): array
    {
        // JSON inputs must be numbers (SPEC s3): a string location_id in the body is not accepted.
        $requested = $body['location_id'] ?? null;
        $locationId = self::locationFor($user, is_int($requested) ? $requested : null);

        $errors = [];
        $sku = $body['sku'] ?? null;
        if (!is_string($sku) || preg_match(RecipeService::SKU_PATTERN, $sku) !== 1
            || Db::value('SELECT sku FROM ingredients WHERE sku = ? AND active = 1', [$sku]) === null) {
            $errors['sku'] = 'unknown or inactive ingredient';
        }
        $delta = Money::parseMilli($body['qty_delta'] ?? null);
        if ($delta === null || $delta === 0 || abs($delta) > self::MAX_DELTA_MILLI) {
            $errors['qty_delta'] = 'must be a non-zero number, at most 3 decimals, between -100000 and 100000';
        }
        $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
        $reasonLen = mb_strlen($reason, 'UTF-8');
        if ($reasonLen < 1 || $reasonLen > 200) {
            $errors['reason'] = 'required, 1-200 characters';
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }

        return Db::transaction(static function () use ($locationId, $sku, $delta, $reason, $user): array {
            $id = self::record($locationId, $sku, $delta, 'adjustment', null, $reason, (int) $user['id']);
            $row = Db::one('SELECT id, location_id, sku, qty_delta_milli, reason, note, created_at FROM inventory_movements WHERE id = ?', [$id]);
            return [
                'movement' => [
                    'id' => (int) $row['id'],
                    'location_id' => (int) $row['location_id'],
                    'sku' => (string) $row['sku'],
                    'qty_delta' => Money::toQty((int) $row['qty_delta_milli']),
                    'reason' => (string) $row['reason'],
                    'note' => $row['note'],
                    'created_at' => Clock::iso((string) $row['created_at']),
                ],
                'on_hand' => Money::toQty(self::onHandMilli($locationId, $sku)),
            ];
        });
    }
}
