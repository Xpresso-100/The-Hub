<?php
declare(strict_types=1);

namespace Hub\Modules\Reports;

use Hub\Db;
use Hub\Money;

/**
 * M3-reports (SPEC s9). Read only: every figure comes from the batches snapshots M2 wrote
 * (never recomputed from current ingredient costs), except the admin recipe list's
 * cost_per_unit, which SPEC s9.2/s8.1 defines on CURRENT costs.
 */
final class ReportService
{
    /**
     * Batch rows matching the filters, oldest first (produced_on, id).
     * @param array{from?: ?string, to?: ?string, location_id?: ?int, recipe_id?: ?int} $filters
     * @return list<array<string,mixed>> raw rows with integer columns cast
     */
    public static function batchRows(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['from'] ?? null) !== null) {
            $where[] = 'b.produced_on >= ?';
            $params[] = $filters['from'];
        }
        if (($filters['to'] ?? null) !== null) {
            $where[] = 'b.produced_on <= ?';
            $params[] = $filters['to'];
        }
        if (($filters['location_id'] ?? null) !== null) {
            $where[] = 'b.location_id = ?';
            $params[] = $filters['location_id'];
        }
        if (($filters['recipe_id'] ?? null) !== null) {
            $where[] = 'b.recipe_id = ?';
            $params[] = $filters['recipe_id'];
        }
        $sql = 'SELECT b.id, b.produced_on, b.location_id, l.name AS location_name, b.recipe_id, b.finished_sku,'
            . ' b.name_snapshot, b.unit_snapshot, b.quantity, b.cost_per_item_cents, b.total_cost_cents,'
            . ' u.username AS created_by'
            . ' FROM batches b'
            . ' JOIN locations l ON l.id = b.location_id'
            . ' LEFT JOIN users u ON u.id = b.created_by_user_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY b.produced_on, b.id';
        $rows = [];
        foreach (Db::all($sql, $params) as $row) {
            foreach (['id', 'location_id', 'recipe_id', 'quantity', 'cost_per_item_cents', 'total_cost_cents'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['produced_on'] = substr((string) $row['produced_on'], 0, 10);
            $rows[] = $row;
        }
        return $rows;
    }

    /** One batch row as the SPEC s9.1 `batches[]` element. */
    public static function batchJson(array $row): array
    {
        return [
            'id' => $row['id'],
            'produced_on' => $row['produced_on'],
            'location_id' => $row['location_id'],
            'location_name' => (string) $row['location_name'],
            'finished_sku' => (string) $row['finished_sku'],
            'name' => (string) $row['name_snapshot'],
            'quantity' => $row['quantity'],
            'cost_per_item' => Money::toRands($row['cost_per_item_cents']),
            'total_cost' => Money::toRands($row['total_cost_cents']),
        ];
    }

    /**
     * SPEC s9.1 finished-goods report body (without `success`).
     * Items grouped by finished_sku; name/unit come from the latest batch of that sku.
     */
    public static function finishedGoods(string $from, string $to, ?int $locationId): array
    {
        $rows = self::batchRows(['from' => $from, 'to' => $to, 'location_id' => $locationId]);
        $groups = [];
        $totalQty = 0;
        $totalCents = 0;
        foreach ($rows as $row) {
            $sku = (string) $row['finished_sku'];
            $groups[$sku] ??= ['name' => '', 'unit' => '', 'quantity' => 0, 'cents' => 0, 'batch_count' => 0];
            $groups[$sku]['name'] = (string) $row['name_snapshot'];
            $groups[$sku]['unit'] = (string) $row['unit_snapshot'];
            $groups[$sku]['quantity'] += $row['quantity'];
            $groups[$sku]['cents'] += $row['total_cost_cents'];
            $groups[$sku]['batch_count']++;
            $totalQty += $row['quantity'];
            $totalCents += $row['total_cost_cents'];
        }
        ksort($groups, SORT_STRING);

        $items = [];
        foreach ($groups as $sku => $g) {
            $items[] = [
                'finished_sku' => (string) $sku,
                'name' => $g['name'],
                'unit' => $g['unit'],
                'quantity' => $g['quantity'],
                'total_cost' => Money::toRands($g['cents']),
                'avg_cost_per_item' => Money::toRands(Money::roundHalfUp($g['cents'], $g['quantity'])),
                'batch_count' => $g['batch_count'],
                'total_cost_cents' => $g['cents'],
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'location_id' => $locationId,
            'items' => $items,
            'totals' => [
                'quantity' => $totalQty,
                'total_cost' => Money::toRands($totalCents),
                'batch_count' => count($rows),
                'total_cost_cents' => $totalCents,
            ],
            'batches' => array_map([self::class, 'batchJson'], $rows),
        ];
    }

    /** Drops the internal *_cents helpers from a finishedGoods() result before it goes out as JSON. */
    public static function publicReport(array $report): array
    {
        $report['items'] = array_map(static function (array $item): array {
            unset($item['total_cost_cents']);
            return $item;
        }, $report['items']);
        unset($report['totals']['total_cost_cents']);
        return $report;
    }

    /** SPEC s9.1 CSV: header, one row per item, TOTAL row. Money formatted 2 dp. */
    public static function finishedGoodsCsv(array $report): string
    {
        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            throw new \RuntimeException('cannot open temp stream');
        }
        fputcsv($out, ['finished_sku', 'name', 'unit', 'quantity', 'total_cost', 'avg_cost_per_item', 'batch_count'], ',', '"', '');
        foreach ($report['items'] as $item) {
            $avgCents = Money::roundHalfUp($item['total_cost_cents'], $item['quantity']);
            fputcsv($out, [
                self::csvText($item['finished_sku']),
                self::csvText($item['name']),
                self::csvText($item['unit']),
                (string) $item['quantity'],
                Money::format($item['total_cost_cents']),
                Money::format($avgCents),
                (string) $item['batch_count'],
            ], ',', '"', '');
        }
        $totals = $report['totals'];
        fputcsv($out, [
            'TOTAL', '', '',
            (string) $totals['quantity'],
            Money::format($totals['total_cost_cents']),
            '',
            (string) $totals['batch_count'],
        ], ',', '"', '');
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** Spreadsheet formula-injection guard: text starting with = + - @ (or tab/CR) gets a leading quote. */
    private static function csvText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /**
     * SPEC s9.2 admin recipe list, including inactive, sorted by finished_sku.
     * $locationId limits batch_count / last_produced_on to one location (hub); null = all (hq).
     */
    public static function adminRecipes(?int $locationId): array
    {
        $recipes = Db::all('SELECT id, finished_sku, name, unit, is_sample, active FROM recipes ORDER BY finished_sku');

        $lines = [];
        foreach (Db::all('SELECT rl.recipe_id, rl.qty_milli, i.cost_cents FROM recipe_lines rl JOIN ingredients i ON i.sku = rl.sku') as $line) {
            $id = (int) $line['recipe_id'];
            $lines[$id] ??= ['count' => 0, 'cents' => 0];
            $lines[$id]['count']++;
            $lines[$id]['cents'] += Money::lineCents((int) $line['qty_milli'], (int) $line['cost_cents']);
        }

        $sql = 'SELECT recipe_id, COUNT(*) AS batch_count, MAX(produced_on) AS last_produced_on FROM batches';
        $params = [];
        if ($locationId !== null) {
            $sql .= ' WHERE location_id = ?';
            $params[] = $locationId;
        }
        $stats = [];
        foreach (Db::all($sql . ' GROUP BY recipe_id', $params) as $row) {
            $stats[(int) $row['recipe_id']] = $row;
        }

        $out = [];
        foreach ($recipes as $r) {
            $id = (int) $r['id'];
            $last = $stats[$id]['last_produced_on'] ?? null;
            $out[] = [
                'id' => $id,
                'finished_sku' => (string) $r['finished_sku'],
                'name' => (string) $r['name'],
                'unit' => (string) $r['unit'],
                'is_sample' => (int) $r['is_sample'] === 1,
                'active' => (int) $r['active'] === 1,
                'line_count' => $lines[$id]['count'] ?? 0,
                'cost_per_unit' => Money::toRands($lines[$id]['cents'] ?? 0),
                'batch_count' => (int) ($stats[$id]['batch_count'] ?? 0),
                'last_produced_on' => $last === null ? null : substr((string) $last, 0, 10),
            ];
        }
        return $out;
    }

    /**
     * SPEC s9.3 admin batch list, newest first (produced_on desc, id desc).
     * @param array{from?: ?string, to?: ?string, location_id?: ?int, recipe_id?: ?int} $filters
     */
    public static function adminBatches(array $filters): array
    {
        $rows = array_reverse(self::batchRows($filters));
        return array_map(static fn (array $row): array => self::batchJson($row) + [
            'created_by' => $row['created_by'] === null ? null : (string) $row['created_by'],
        ], $rows);
    }

    /** True when a location with this id exists. */
    public static function locationExists(int $id): bool
    {
        return Db::value('SELECT COUNT(*) FROM locations WHERE id = ?', [$id]) > 0;
    }
}
