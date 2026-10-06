<?php
declare(strict_types=1);

namespace Hub\Modules\Recipes;

use Hub\Clock;
use Hub\Db;
use Hub\Http\HttpError;
use Hub\Money;

/**
 * Recipes (SPEC s8.1-8.2): one finished item -> ingredient lines, quantities per ONE finished unit.
 * Costs are always computed from the CURRENT ingredient cost (s3.1); batches keep their own snapshots.
 */
final class RecipeService
{
    public const SKU_PATTERN = '/^[A-Z0-9-]{2,20}$/';
    public const MAX_QTY_MILLI = 100000 * 1000;
    public const MAX_LINES = 50;

    /** @return list<array> RECIPE objects sorted by finished_sku. */
    public static function list(bool $includeInactive): array
    {
        $rows = Db::all(
            'SELECT id, finished_sku, name, unit, is_sample, active FROM recipes'
            . ($includeInactive ? '' : ' WHERE active = 1')
            . ' ORDER BY finished_sku'
        );
        return array_map([self::class, 'present'], $rows);
    }

    /** RECIPE object, or null when the id is unknown. */
    public static function find(int $id): ?array
    {
        $row = Db::one('SELECT id, finished_sku, name, unit, is_sample, active FROM recipes WHERE id = ?', [$id]);
        return $row === null ? null : self::present($row);
    }

    /**
     * Recipe lines joined to the current ingredient (name, unit, cost), in entry order.
     * @return list<array{sku:string,name:string,unit:string,qty_milli:int,unit_cost_cents:int}>
     */
    public static function lines(int $recipeId): array
    {
        $rows = Db::all(
            'SELECT rl.sku, i.name, i.unit, rl.qty_milli, i.cost_cents
               FROM recipe_lines rl JOIN ingredients i ON i.sku = rl.sku
              WHERE rl.recipe_id = ?
              ORDER BY rl.id',
            [$recipeId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'unit' => (string) $row['unit'],
                'qty_milli' => (int) $row['qty_milli'],
                'unit_cost_cents' => (int) $row['cost_cents'],
            ];
        }
        return $out;
    }

    /** Creates a recipe from a validated-on-entry body. Returns the RECIPE. */
    public static function create(array $body): array
    {
        $data = self::validate($body, false);
        $id = Db::transaction(static function () use ($data): int {
            self::assertSkuFree($data['finished_sku'], null);
            $now = Clock::nowDb();
            $id = Db::insert(
                'INSERT INTO recipes (finished_sku, name, unit, is_sample, active, created_at, updated_at)
                 VALUES (?, ?, ?, 0, 1, ?, ?)',
                [$data['finished_sku'], $data['name'], $data['unit'], $now, $now]
            );
            self::insertLines($id, $data['lines']);
            return $id;
        });
        return self::find($id);
    }

    /** Replaces a recipe's fields and ALL its lines. Unknown id -> 404. Returns the RECIPE. */
    public static function update(int $id, array $body): array
    {
        if (Db::value('SELECT id FROM recipes WHERE id = ?', [$id]) === null) {
            throw HttpError::notFound();
        }
        $data = self::validate($body, true);
        Db::transaction(static function () use ($id, $data): void {
            self::assertSkuFree($data['finished_sku'], $id);
            $sets = 'finished_sku = ?, name = ?, unit = ?, updated_at = ?';
            $params = [$data['finished_sku'], $data['name'], $data['unit'], Clock::nowDb()];
            if ($data['active'] !== null) {
                $sets .= ', active = ?';
                $params[] = $data['active'] ? 1 : 0;
            }
            $params[] = $id;
            Db::exec('UPDATE recipes SET ' . $sets . ' WHERE id = ?', $params);
            Db::exec('DELETE FROM recipe_lines WHERE recipe_id = ?', [$id]);
            self::insertLines($id, $data['lines']);
        });
        return self::find($id);
    }

    /** RECIPE JSON shape (s8.1) for a recipes row. */
    private static function present(array $row): array
    {
        $lines = [];
        $total = 0;
        foreach (self::lines((int) $row['id']) as $line) {
            $cost = Money::lineCents($line['qty_milli'], $line['unit_cost_cents']);
            $total += $cost;
            $lines[] = [
                'sku' => $line['sku'],
                'name' => $line['name'],
                'unit' => $line['unit'],
                'qty' => Money::toQty($line['qty_milli']),
                'unit_cost' => Money::toRands($line['unit_cost_cents']),
                'line_cost' => Money::toRands($cost),
            ];
        }
        return [
            'id' => (int) $row['id'],
            'finished_sku' => (string) $row['finished_sku'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'is_sample' => (int) $row['is_sample'] === 1,
            'active' => (int) $row['active'] === 1,
            'cost_per_unit' => Money::toRands($total),
            'lines' => $lines,
        ];
    }

    /**
     * Validates a POST/PUT body (s8.2). Throws 422 with every field error found.
     * @return array{finished_sku:string,name:string,unit:string,active:?bool,lines:list<array{sku:string,qty_milli:int}>}
     */
    private static function validate(array $body, bool $isUpdate): array
    {
        $errors = [];

        $sku = $body['finished_sku'] ?? null;
        if (!is_string($sku) || preg_match(self::SKU_PATTERN, $sku) !== 1) {
            $errors['finished_sku'] = 'must match ^[A-Z0-9-]{2,20}$';
        }
        $name = self::text($body['name'] ?? null, 150);
        if ($name === null) {
            $errors['name'] = 'required, 1-150 characters';
        }
        $unit = self::text($body['unit'] ?? null, 20);
        if ($unit === null) {
            $errors['unit'] = 'required, 1-20 characters';
        }
        $active = null;
        if ($isUpdate && array_key_exists('active', $body)) {
            if (!is_bool($body['active'])) {
                $errors['active'] = 'must be true or false';
            } else {
                $active = $body['active'];
            }
        }

        $lines = [];
        $raw = $body['lines'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            $errors['lines'] = 'at least one line is required';
        } elseif (count($raw) > self::MAX_LINES) {
            $errors['lines'] = 'at most ' . self::MAX_LINES . ' lines';
        } else {
            $seen = [];
            foreach ($raw as $n => $line) {
                if (!is_array($line)) {
                    $errors['lines.' . $n] = 'must be an object with sku and qty';
                    continue;
                }
                $lineSku = $line['sku'] ?? null;
                if (!is_string($lineSku) || preg_match(self::SKU_PATTERN, $lineSku) !== 1) {
                    $errors['lines.' . $n . '.sku'] = 'unknown ingredient';
                } elseif (isset($seen[$lineSku])) {
                    $errors['lines.' . $n . '.sku'] = 'duplicate ingredient';
                } elseif (Db::value('SELECT sku FROM ingredients WHERE sku = ? AND active = 1', [$lineSku]) === null) {
                    $errors['lines.' . $n . '.sku'] = 'unknown or inactive ingredient';
                }
                if (is_string($lineSku)) {
                    $seen[$lineSku] = true;
                }
                $milli = Money::parseMilli($line['qty'] ?? null);
                if ($milli === null || $milli <= 0 || $milli > self::MAX_QTY_MILLI) {
                    $errors['lines.' . $n . '.qty'] = 'must be a number > 0, <= 100000, at most 3 decimals';
                }
                if (!isset($errors['lines.' . $n . '.sku']) && !isset($errors['lines.' . $n . '.qty'])) {
                    $lines[] = ['sku' => $lineSku, 'qty_milli' => $milli];
                }
            }
        }

        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        return ['finished_sku' => $sku, 'name' => $name, 'unit' => $unit, 'active' => $active, 'lines' => $lines];
    }

    /** Trimmed non-empty string of at most $max characters, else null. */
    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $len = mb_strlen($value, 'UTF-8');
        return ($len >= 1 && $len <= $max) ? $value : null;
    }

    /** 409 when another recipe already uses $finishedSku. */
    private static function assertSkuFree(string $finishedSku, ?int $exceptId): void
    {
        $other = Db::value('SELECT id FROM recipes WHERE finished_sku = ?', [$finishedSku]);
        if ($other !== null && (int) $other !== $exceptId) {
            throw HttpError::conflict('A recipe for finished item ' . $finishedSku . ' already exists.');
        }
    }

    /** @param list<array{sku:string,qty_milli:int}> $lines */
    private static function insertLines(int $recipeId, array $lines): void
    {
        foreach ($lines as $line) {
            Db::insert('INSERT INTO recipe_lines (recipe_id, sku, qty_milli) VALUES (?, ?, ?)', [$recipeId, $line['sku'], $line['qty_milli']]);
        }
    }
}
