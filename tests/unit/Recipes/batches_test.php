<?php
declare(strict_types=1);

// M2-03 (SPEC s8.3, A4): POST /api/batches - snapshot lines at current cost, deduct ingredients, cost per item.
// M2-04 (SPEC s8.4): GET /api/batches/{id} - location-scoped batch detail.
// The ledger is read straight from the test SQLite file so these tests do not depend on GET /api/inventory.

require __DIR__ . '/../../lib/harness.php';

/** [sku => on_hand milli] from inventory_movements for one location. */
function m2_ledger(TestApp $app, int $locationId): array
{
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $st = $pdo->prepare('SELECT sku, SUM(qty_delta_milli) AS q FROM inventory_movements WHERE location_id = ? GROUP BY sku ORDER BY sku');
    $st->execute([$locationId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['sku']] = (int) $row['q'];
    }
    return $out;
}

test('M2-03: muffin x24 -> deductions 1.440/0.720/0.480/0.360/0.240, total 134.16, per item 5.59, s5.3 line costs, warnings', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $r = $hub->post('/api/batches', ['recipe_id' => 1, 'quantity' => 24, 'notes' => 'first run']);
    assert_success($r, 201, 'POST /api/batches muffin x24');
    $batch = hub_object($r, 'batch', 'batch');
    assert_same(
        ['id', 'location_id', 'recipe_id', 'finished_sku', 'name', 'unit', 'quantity', 'produced_on', 'total_cost', 'cost_per_item', 'notes', 'created_at', 'created_by', 'lines'],
        array_keys($batch),
        'BATCH keys (s8.3)'
    );
    assert_same($hub->locationId(), $batch['location_id'], 'own location');
    assert_same('SAMPLE Choc muffin', $batch['name'], 'name snapshot');
    assert_same('each', $batch['unit'], 'unit snapshot');
    assert_same(hub_today(), $batch['produced_on'], 'produced_on defaults to today');
    assert_same('first run', $batch['notes'], 'notes');
    assert_same('hub.user', $batch['created_by'], 'created_by');
    assert_true(is_string($batch['created_at']) && preg_match('/[+-]\d\d:\d\d$/', $batch['created_at']) === 1, 'created_at ISO with offset');
    assert_money(134.16, $batch['total_cost'], 'total_cost');
    assert_money(5.59, $batch['cost_per_item'], 'cost_per_item');
    $expected = ['SMP001' => [1.44, 12.5, 18.0], 'SMP002' => [0.72, 18.0, 12.96], 'SMP003' => [0.48, 45.0, 21.6], 'SMP004' => [0.36, 120.0, 43.2], 'SMP005' => [0.24, 160.0, 38.4]];
    assert_same(array_keys($expected), hub_column($batch['lines'], 'sku'), 'lines in recipe order');
    foreach ($batch['lines'] as $line) {
        [$qty, $unit, $cost] = $expected[$line['sku']];
        assert_qty($qty, $line['qty_used'], $line['sku'] . ' qty_used');
        assert_money($unit, $line['unit_cost'], $line['sku'] . ' unit_cost');
        assert_money($cost, $line['line_cost'], $line['sku'] . ' line_cost');
    }
    assert_same(['SMP001' => -1440, 'SMP002' => -720, 'SMP003' => -480, 'SMP004' => -360, 'SMP005' => -240], m2_ledger($app, $hub->locationId()), 'ledger deductions, SMP006 untouched');
    assert_same(['SMP001', 'SMP002', 'SMP003', 'SMP004', 'SMP005'], hub_column($r->json['warnings'], 'sku'), 'negative on-hand allowed and warned');
    assert_qty(-0.24, $r->json['warnings'][4]['on_hand_after'], 'SMP005 on_hand_after');

    $pdo = new PDO('sqlite:' . $app->dbPath());
    $reasons = $pdo->query("SELECT DISTINCT reason || ':' || ref_id FROM inventory_movements")->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['batch:' . $batch['id']], $reasons, 'movements carry reason batch and ref_id = batch id');
    assert_same([], m2_ledger($app, $app->client('hub2.user')->locationId()), 'Hub Two ledger untouched');
});

test('M2-03: batch costs are snapshots - a later cost or recipe change does not move a recorded batch', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $batchId = hub_object($hub->post('/api/batches', ['recipe_id' => 1, 'quantity' => 24]), 'batch', 'muffin x24')['id'];
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->exec("UPDATE ingredients SET cost_cents = 9999, name = 'Renamed' WHERE sku = 'SMP001'");
    assert_success($hub->put('/api/recipes/1', ['finished_sku' => 'SMP-F01', 'name' => 'SAMPLE Choc muffin XL', 'unit' => 'each', 'lines' => [['sku' => 'SMP001', 'qty' => 0.5]]]), 200, 'edit muffin');
    $batch = hub_object($hub->get('/api/batches/' . $batchId), 'batch', 'stored batch');
    assert_same('SAMPLE Choc muffin', $batch['name'], 'name snapshot kept');
    assert_same(5, count($batch['lines']), 'five snapshot lines kept');
    assert_same('SAMPLE Cake flour', $batch['lines'][0]['name'], 'ingredient name snapshot kept');
    assert_money(12.5, $batch['lines'][0]['unit_cost'], 'unit cost snapshot kept');
    assert_money(134.16, $batch['total_cost'], 'total unchanged');

    $r = $hub->post('/api/batches', ['recipe_id' => 1, 'quantity' => 2]);
    assert_success($r, 201, 'new batch uses the new recipe and cost');
    assert_money(99.99, $r->json['batch']['total_cost'], '2 x 0.500 x 99.99');
    assert_money(50.0, $r->json['batch']['cost_per_item'], '9999 / 2 = 4999.5 -> 5000 half-up');
});

test('M2-03: cost_per_item rounds half-up, produced_on accepted, positive balance not warned', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->exec("INSERT INTO inventory_movements (location_id, sku, qty_delta_milli, reason, created_at) VALUES (" . $hub->locationId() . ", 'SMP006', 10000, 'adjustment', '2026-10-06 00:00:00')");
    $r = $hub->post('/api/batches', ['recipe_id' => 2, 'quantity' => 7, 'produced_on' => hub_date_plus(-3)]);
    assert_success($r, 201, 'scone x7 three days ago');
    // lines: SMP001 0.560 -> 7.00, SMP004 0.140 -> 16.80, SMP006 0.280 -> 5.60, SMP002 0.070 -> 1.26 = 30.66; /7 = 4.38
    assert_money(30.66, $r->json['batch']['total_cost'], 'scone x7 total');
    assert_money(4.38, $r->json['batch']['cost_per_item'], 'scone x7 per item');
    assert_same(hub_date_plus(-3), $r->json['batch']['produced_on'], 'produced_on kept');
    assert_same(['SMP001', 'SMP004', 'SMP002'], hub_column($r->json['warnings'], 'sku'), 'SMP006 stays positive so it is not warned');

    // 2 custard tubs: 22.66 x 2 = 45.32; 3 muffins: 3 x 5.59 = 16.77 (lines 2.25+1.62+2.70+5.40+4.80 = 16.77)
    $r = $hub->post('/api/batches', ['recipe_id' => 1, 'quantity' => 3]);
    assert_money(16.77, $r->json['batch']['total_cost'], 'muffin x3 total');
    assert_money(5.59, $r->json['batch']['cost_per_item'], 'muffin x3 per item');
});

test('M2-03: hq and franchise 403; 422 for unknown/inactive recipe, non-integer quantity, bad or future date, long notes', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    assert_error($app->client('hq.admin')->post('/api/batches', ['recipe_id' => 1, 'quantity' => 1]), 403, 'FORBIDDEN', 'hq cannot record');
    assert_error($app->client('franchise.user')->post('/api/batches', ['recipe_id' => 1, 'quantity' => 1]), 403, 'FORBIDDEN', 'franchise cannot record');
    assert_error($app->anon()->post('/api/batches', ['recipe_id' => 1, 'quantity' => 1]), 401, 'UNAUTHENTICATED', 'anonymous');

    assert_success($hub->put('/api/recipes/3', ['finished_sku' => 'SMP-F03', 'name' => 'SAMPLE Vanilla custard 1 l', 'unit' => 'tub', 'active' => false,
        'lines' => [['sku' => 'SMP006', 'qty' => 0.8], ['sku' => 'SMP003', 'qty' => 0.1], ['sku' => 'SMP002', 'qty' => 0.12]]]), 200, 'deactivate custard');
    $cases = [
        'unknown recipe' => [['recipe_id' => 9999, 'quantity' => 1], 'recipe_id'],
        'string recipe id' => [['recipe_id' => '1', 'quantity' => 1], 'recipe_id'],
        'inactive recipe' => [['recipe_id' => 3, 'quantity' => 1], 'recipe_id'],
        'quantity 0' => [['recipe_id' => 1, 'quantity' => 0], 'quantity'],
        'quantity 1.5' => [['recipe_id' => 1, 'quantity' => 1.5], 'quantity'],
        'quantity string' => [['recipe_id' => 1, 'quantity' => '2'], 'quantity'],
        'quantity too big' => [['recipe_id' => 1, 'quantity' => 100001], 'quantity'],
        'missing quantity' => [['recipe_id' => 1], 'quantity'],
        'future date' => [['recipe_id' => 1, 'quantity' => 1, 'produced_on' => hub_date_plus(1)], 'produced_on'],
        'bad date' => [['recipe_id' => 1, 'quantity' => 1, 'produced_on' => '2026-02-30'], 'produced_on'],
        'long notes' => [['recipe_id' => 1, 'quantity' => 1, 'notes' => str_repeat('n', 501)], 'notes'],
    ];
    foreach ($cases as $what => [$body, $field]) {
        $r = $hub->post('/api/batches', $body);
        assert_error($r, 422, 'VALIDATION_ERROR', $what);
        assert_true(isset($r->json['errors'][$field]), $what . ': error on ' . $field . '; ' . $r->describe());
    }
    $pdo = new PDO('sqlite:' . $app->dbPath());
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM batches')->fetchColumn(), 'no batch stored by rejected requests');
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn(), 'no movement stored by rejected requests');
});

test('M2-04: GET /api/batches/{id} - hub own 200, other location 404, hq any, franchise 403, unknown 404', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    $hq = $app->client('hq.admin');
    $mine = hub_object($hub->post('/api/batches', ['recipe_id' => 1, 'quantity' => 12]), 'batch', 'mine')['id'];
    $theirs = hub_object($hub2->post('/api/batches', ['recipe_id' => 3, 'quantity' => 5]), 'batch', 'theirs')['id'];

    $r = $hub->get('/api/batches/' . $mine);
    assert_success($r, 200, 'own batch');
    assert_money(67.08, $r->json['batch']['total_cost'], 'muffin x12 total');
    assert_same([9.0, 6.48, 10.8, 21.6, 19.2], hub_column($r->json['batch']['lines'], 'line_cost'), 'muffin x12 snapshot lines');
    assert_error($hub->get('/api/batches/' . $theirs), 404, 'NOT_FOUND', "hub.user reading hub2's batch");
    assert_error($hub2->get('/api/batches/' . $mine), 404, 'NOT_FOUND', "hub2.user reading hub's batch");
    assert_error($hub->get('/api/batches/' . $theirs . '?location_id=' . $hub2->locationId()), 404, 'NOT_FOUND', 'location_id param ignored');
    $r = $hq->get('/api/batches/' . $theirs);
    assert_success($r, 200, 'hq reads any batch');
    assert_money(113.3, $r->json['batch']['total_cost'], 'custard x5 total');
    assert_error($app->client('franchise.user')->get('/api/batches/' . $mine), 403, 'FORBIDDEN', 'franchise');
    assert_error($hq->get('/api/batches/9999'), 404, 'NOT_FOUND', 'unknown id');
});

run_tests();
