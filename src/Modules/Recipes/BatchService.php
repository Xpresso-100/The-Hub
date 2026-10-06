<?php
declare(strict_types=1);

namespace Hub\Modules\Recipes;

use Hub\Clock;
use Hub\Db;
use Hub\Http\HttpError;
use Hub\Money;

/**
 * Production batches (SPEC s8.3-8.4). Recording a batch snapshots every recipe line at the current
 * ingredient cost, stores batches + batch_lines and deducts the ingredients from the hub's ledger,
 * all in one transaction. Negative on-hand is allowed and reported as warnings.
 */
final class BatchService
{
    public const MAX_QUANTITY = 100000;

    /** Records a batch for a hub user. Returns ['batch' => BATCH, 'warnings' => list]. */
    public static function record(array $user, array $body): array
    {
        $errors = [];
        $recipe = null;
        $recipeId = $body['recipe_id'] ?? null;
        if (is_int($recipeId)) {
            $recipe = Db::one('SELECT id, finished_sku, name, unit, active FROM recipes WHERE id = ?', [$recipeId]);
        }
        if ($recipe === null) {
            $errors['recipe_id'] = 'unknown recipe';
        } elseif ((int) $recipe['active'] !== 1) {
            $errors['recipe_id'] = 'recipe is inactive';
        }
        $quantity = $body['quantity'] ?? null;
        if (!is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
            $errors['quantity'] = 'must be a whole number from 1 to ' . self::MAX_QUANTITY;
        }
        $producedOn = $body['produced_on'] ?? null;
        if ($producedOn === null) {
            $producedOn = Clock::today();
        } elseif (!Clock::isDate($producedOn)) {
            $errors['produced_on'] = 'must be a date YYYY-MM-DD';
        } elseif ($producedOn > Clock::today()) {
            $errors['produced_on'] = 'cannot be in the future';
        }
        $notes = $body['notes'] ?? null;
        if ($notes !== null) {
            if (!is_string($notes) || mb_strlen($notes, 'UTF-8') > 500) {
                $errors['notes'] = 'at most 500 characters';
            } else {
                $notes = trim($notes) === '' ? null : trim($notes);
            }
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }

        $locationId = (int) $user['location_id'];
        $userId = (int) $user['id'];
        [$batchId, $warnings] = Db::transaction(static function () use ($recipe, $quantity, $producedOn, $notes, $locationId, $userId): array {
            $lines = [];
            $total = 0;
            foreach (RecipeService::lines((int) $recipe['id']) as $line) {
                $used = $line['qty_milli'] * $quantity;
                $cost = Money::lineCents($used, $line['unit_cost_cents']);
                $total += $cost;
                $lines[] = $line + ['used_milli' => $used, 'line_cents' => $cost];
            }
            if ($lines === []) {
                throw HttpError::validation(['recipe_id' => 'recipe has no ingredient lines']);
            }
            $batchId = Db::insert(
                'INSERT INTO batches (location_id, recipe_id, created_by_user_id, finished_sku, name_snapshot, unit_snapshot,
                                      quantity, produced_on, total_cost_cents, cost_per_item_cents, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$locationId, (int) $recipe['id'], $userId, $recipe['finished_sku'], $recipe['name'], $recipe['unit'],
                 $quantity, $producedOn, $total, Money::roundHalfUp($total, $quantity), $notes, Clock::nowDb()]
            );
            $warnings = [];
            foreach ($lines as $line) {
                Db::insert(
                    'INSERT INTO batch_lines (batch_id, sku, name_snapshot, unit_snapshot, qty_used_milli, unit_cost_cents, line_cost_cents)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$batchId, $line['sku'], $line['name'], $line['unit'], $line['used_milli'], $line['unit_cost_cents'], $line['line_cents']]
                );
                InventoryService::record($locationId, $line['sku'], -$line['used_milli'], 'batch', $batchId, null, $userId);
                $after = InventoryService::onHandMilli($locationId, $line['sku']);
                if ($after < 0) {
                    $warnings[] = ['sku' => $line['sku'], 'on_hand_after' => Money::toQty($after)];
                }
            }
            return [$batchId, $warnings];
        });

        return ['batch' => self::find($batchId), 'warnings' => $warnings];
    }

    /** BATCH object (s8.3) or null when unknown. */
    public static function find(int $id): ?array
    {
        $row = Db::one(
            'SELECT b.*, u.username FROM batches b JOIN users u ON u.id = b.created_by_user_id WHERE b.id = ?',
            [$id]
        );
        if ($row === null) {
            return null;
        }
        $lines = [];
        foreach (Db::all('SELECT * FROM batch_lines WHERE batch_id = ? ORDER BY id', [$id]) as $line) {
            $lines[] = [
                'sku' => (string) $line['sku'],
                'name' => (string) $line['name_snapshot'],
                'unit' => (string) $line['unit_snapshot'],
                'qty_used' => Money::toQty((int) $line['qty_used_milli']),
                'unit_cost' => Money::toRands((int) $line['unit_cost_cents']),
                'line_cost' => Money::toRands((int) $line['line_cost_cents']),
            ];
        }
        return [
            'id' => (int) $row['id'],
            'location_id' => (int) $row['location_id'],
            'recipe_id' => (int) $row['recipe_id'],
            'finished_sku' => (string) $row['finished_sku'],
            'name' => (string) $row['name_snapshot'],
            'unit' => (string) $row['unit_snapshot'],
            'quantity' => (int) $row['quantity'],
            'produced_on' => (string) $row['produced_on'],
            'total_cost' => Money::toRands((int) $row['total_cost_cents']),
            'cost_per_item' => Money::toRands((int) $row['cost_per_item_cents']),
            'notes' => $row['notes'],
            'created_at' => Clock::iso((string) $row['created_at']),
            'created_by' => (string) $row['username'],
            'lines' => $lines,
        ];
    }

    /** BATCH visible to $user: hq any, hub own location only; otherwise 404 (SPEC s3 scope rule). */
    public static function findFor(array $user, int $id): array
    {
        $batch = self::find($id);
        if ($batch === null || ($user['role'] === 'hub' && $batch['location_id'] !== (int) $user['location_id'])) {
            throw HttpError::notFound();
        }
        return $batch;
    }
}
