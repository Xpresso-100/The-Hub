<?php
declare(strict_types=1);

// A5 (SPEC s12): Finished-goods report totals match the batches.
// Batches from s5.3 (hub.user: muffin x24, scone x10, muffin x12; hub2.user: custard x5, all today);
// hub.user report totals 46 / 245.04 / 3 and per-item rows as s5.3; hq.admin report totals 51 / 358.34 / 4;
// in every report `totals` equal the sums over `batches` and over `items`.

require __DIR__ . '/helpers.php';

/** Starts an app and records the s5.3 report batches. Returns [app, batch ids by key, batch totals by id]. */
function a5_setup(): array
{
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    $recorded = [
        'm24' => acc_record_batch($hub, MUFFIN, 24)[0],
        's10' => acc_record_batch($hub, SCONE, 10)[0],
        'm12' => acc_record_batch($hub, MUFFIN, 12)[0],
        'c5' => acc_record_batch($hub2, CUSTARD, 5)[0],
    ];
    $ids = array_map(static fn (array $b): int => $b['id'], $recorded);
    $totals = [];
    foreach ($recorded as $batch) {
        $totals[$batch['id']] = $batch['total_cost'];
    }
    return [$app, $ids, $totals];
}

function a5_report(ApiClient $c, string $query = ''): array
{
    $r = $c->get('/api/reports/finished-goods' . $query);
    assert_success($r, 200, $c->username . ' GET /api/reports/finished-goods' . $query);
    hub_list($r, 'items', 'report items');
    hub_list($r, 'batches', 'report batches');
    hub_object($r, 'totals', 'report totals');
    return $r->json;
}

/** totals == sums over items == sums over batches; batches carry the totals recorded at POST time. */
function a5_assert_consistent(array $report, array $recordedTotals, string $who): void
{
    $items = $report['items'];
    $batches = $report['batches'];
    $totals = $report['totals'];
    $sumInts = static fn (array $list, string $key): int => array_sum(array_map(
        static fn ($v): int => acc_int($v, $who . ' ' . $key),
        hub_column($list, $key)
    ));
    $totalQuantity = acc_int($totals['quantity'] ?? null, $who . ' totals.quantity');
    assert_same($totalQuantity, $sumInts($items, 'quantity'), $who . ': totals.quantity = sum(items.quantity)');
    assert_same($totalQuantity, $sumInts($batches, 'quantity'), $who . ': totals.quantity = sum(batches.quantity)');
    $totalCents = acc_cents([$totals['total_cost'] ?? null]);
    assert_same($totalCents, acc_cents(hub_column($items, 'total_cost')), $who . ': totals.total_cost = sum(items.total_cost) (cents)');
    assert_same($totalCents, acc_cents(hub_column($batches, 'total_cost')), $who . ': totals.total_cost = sum(batches.total_cost) (cents)');
    $batchCount = acc_int($totals['batch_count'] ?? null, $who . ' totals.batch_count');
    assert_same($batchCount, count($batches), $who . ': totals.batch_count = count(batches)');
    assert_same($batchCount, $sumInts($items, 'batch_count'), $who . ': totals.batch_count = sum(items.batch_count)');
    foreach ($batches as $batch) {
        assert_true(array_key_exists($batch['id'] ?? null, $recordedTotals), $who . ': report batch ' . hub_export($batch['id'] ?? null) . ' is one that was recorded');
        assert_money($recordedTotals[$batch['id']], $batch['total_cost'] ?? null, $who . ': batch ' . $batch['id'] . ' total_cost matches POST /api/batches');
    }
}

function a5_assert_items(array $report, array $expected, string $who): void
{
    assert_same(array_keys($expected), hub_column($report['items'], 'finished_sku'), $who . ': item rows (sorted by finished_sku)');
    foreach ($expected as $sku => [$quantity, $total, $avg, $count]) {
        $item = acc_pick($report['items'], 'finished_sku', $sku, $who . ' items');
        assert_same($quantity, acc_int($item['quantity'] ?? null, $who . ' ' . $sku . ' quantity'), $who . ' ' . $sku . ' quantity');
        assert_money($total, $item['total_cost'] ?? null, $who . ' ' . $sku . ' total_cost');
        assert_money($avg, $item['avg_cost_per_item'] ?? null, $who . ' ' . $sku . ' avg_cost_per_item');
        assert_same($count, acc_int($item['batch_count'] ?? null, $who . ' ' . $sku . ' batch_count'), $who . ' ' . $sku . ' batch_count');
    }
}

function a5_assert_totals(array $report, int $quantity, float $total, int $count, string $who): void
{
    assert_same($quantity, acc_int($report['totals']['quantity'] ?? null, $who . ' totals.quantity'), $who . ' totals.quantity');
    assert_money($total, $report['totals']['total_cost'] ?? null, $who . ' totals.total_cost');
    assert_same($count, acc_int($report['totals']['batch_count'] ?? null, $who . ' totals.batch_count'), $who . ' totals.batch_count');
}

const HUB_ITEMS = [MUFFIN => [36, 201.24, 5.59, 2], SCONE => [10, 43.80, 4.38, 1]];
const ALL_ITEMS = [MUFFIN => [36, 201.24, 5.59, 2], SCONE => [10, 43.80, 4.38, 1], CUSTARD => [5, 113.30, 22.66, 1]];

test('A5: hub.user report = own batches only: totals 46 / 245.04 / 3, per-item rows as s5.3', function (): void {
    [$app, $ids, $recorded] = a5_setup();
    $hub = $app->client('hub.user');
    $hub2Location = $app->client('hub2.user')->locationId();
    $today = hub_today();

    $report = a5_report($hub, '?from=' . $today . '&to=' . $today);
    assert_same($today, $report['from'] ?? null, 'report.from');
    assert_same($today, $report['to'] ?? null, 'report.to');
    a5_assert_items($report, HUB_ITEMS, 'hub.user');
    a5_assert_totals($report, 46, 245.04, 3, 'hub.user');
    a5_assert_consistent($report, $recorded, 'hub.user');
    $batchIds = hub_column($report['batches'], 'id');
    sort($batchIds);
    $expectedIds = [$ids['m24'], $ids['s10'], $ids['m12']];
    sort($expectedIds);
    assert_same($expectedIds, $batchIds, 'hub.user report batches are exactly its own three');

    foreach (['' => 'default range (first of month .. today)', '?location_id=' . $hub2Location => 'location_id ignored for a hub user'] as $query => $why) {
        $other = a5_report($hub, $query);
        a5_assert_totals($other, 46, 245.04, 3, 'hub.user, ' . $why);
        a5_assert_consistent($other, $recorded, 'hub.user, ' . $why);
    }
});

test('A5: hq.admin report = all locations: totals 51 / 358.34 / 4; location_id filter narrows to one hub', function (): void {
    [$app, , $recorded] = a5_setup();
    $hq = $app->client('hq.admin');
    $hubLocation = $app->client('hub.user')->locationId();
    $hub2Location = $app->client('hub2.user')->locationId();
    $today = hub_today();

    $all = a5_report($hq, '?from=' . $today . '&to=' . $today);
    a5_assert_items($all, ALL_ITEMS, 'hq.admin');
    a5_assert_totals($all, 51, 358.34, 4, 'hq.admin');
    a5_assert_consistent($all, $recorded, 'hq.admin');

    $hubOnly = a5_report($hq, '?from=' . $today . '&to=' . $today . '&location_id=' . $hubLocation);
    a5_assert_items($hubOnly, HUB_ITEMS, 'hq.admin location_id=The Hub');
    a5_assert_totals($hubOnly, 46, 245.04, 3, 'hq.admin location_id=The Hub');
    a5_assert_consistent($hubOnly, $recorded, 'hq.admin location_id=The Hub');

    $hub2Only = a5_report($hq, '?from=' . $today . '&to=' . $today . '&location_id=' . $hub2Location);
    a5_assert_items($hub2Only, [CUSTARD => [5, 113.30, 22.66, 1]], 'hq.admin location_id=Hub Two');
    a5_assert_totals($hub2Only, 5, 113.30, 1, 'hq.admin location_id=Hub Two');
    a5_assert_consistent($hub2Only, $recorded, 'hq.admin location_id=Hub Two');
});

test('A5: the report is built from batch snapshots, so a later cost change does not move it', function (): void {
    [$app, $ids, $recorded] = a5_setup();
    $hq = $app->client('hq.admin');
    $hub = $app->client('hub.user');
    $r = $hq->put('/api/admin/ingredients/SMP001', ['name' => 'SAMPLE Cake flour', 'unit' => 'kg', 'cost_price' => 13.00, 'fincon_item_no' => 'F-SMP001']);
    assert_success($r, 200, 'hq.admin raises SMP001 cost to 13.00');

    $report = a5_report($hub);
    a5_assert_items($report, HUB_ITEMS, 'hub.user after the cost change');
    a5_assert_totals($report, 46, 245.04, 3, 'hub.user after the cost change');
    a5_assert_consistent($report, $recorded, 'hub.user after the cost change');
    a5_assert_totals(a5_report($hq), 51, 358.34, 4, 'hq.admin after the cost change');

    $r = $hub->get('/api/batches/' . $ids['m24']);
    assert_success($r, 200, 'GET muffin x24 batch');
    assert_money(134.16, $r->json['batch']['total_cost'] ?? null, 'muffin x24 batch total_cost after the cost change');
});

test('A5: CSV export (format=csv) has the SPEC header, one row per item and a TOTAL row matching the batches', function (): void {
    [$app] = a5_setup();
    $hub = $app->client('hub.user');
    $r = $hub->get('/api/reports/finished-goods?format=csv');
    assert_status(200, $r, 'hub.user GET finished-goods CSV');
    assert_true(str_starts_with(strtolower((string) $r->header('Content-Type')), 'text/csv'), 'Content-Type text/csv, got ' . $r->header('Content-Type'));

    $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $r->body);
    $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(preg_split('/\r?\n/', $text) ?: [], static fn (string $l): bool => trim($l) !== '')));
    assert_same(['finished_sku', 'name', 'unit', 'quantity', 'total_cost', 'avg_cost_per_item', 'batch_count'], $rows[0] ?? null, 'CSV header row');
    assert_same(4, count($rows), 'CSV rows: header + 2 items + TOTAL; got ' . hub_export($rows));

    $expectedRows = [
        [MUFFIN, 'SAMPLE Choc muffin', 'each', 36, '201.24', '5.59', 2],
        [SCONE, 'SAMPLE Plain scone', 'each', 10, '43.80', '4.38', 1],
        ['TOTAL', '', '', 46, '245.04', '', 3],
    ];
    foreach ($expectedRows as $i => [$sku, $name, $unit, $quantity, $total, $avg, $count]) {
        $row = $rows[$i + 1];
        $what = 'CSV row ' . ($i + 1) . ' ' . hub_export($row);
        assert_same(7, count($row), $what . ': 7 columns');
        assert_same([$sku, $name, $unit], array_slice($row, 0, 3), $what . ': sku, name, unit');
        assert_true(is_numeric($row[3]) && (float) $row[3] === (float) $quantity, $what . ': quantity ' . $quantity);
        assert_same($total, $row[4], $what . ': total_cost formatted 2 dp');
        assert_same($avg, $row[5], $what . ': avg_cost_per_item formatted 2 dp');
        assert_true(is_numeric($row[6]) && (int) $row[6] === $count, $what . ': batch_count ' . $count);
    }
});

run_tests();
