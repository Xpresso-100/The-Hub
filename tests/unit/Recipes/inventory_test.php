<?php
declare(strict_types=1);

// M2-05 (SPEC s8.5-8.6): GET /api/inventory and POST /api/inventory/adjustments.
// on_hand = sum of movements per sku; every active ingredient listed; hq must name a hub location;
// a hub user's location_id is ignored; franchise 403.

require __DIR__ . '/../../lib/harness.php';

test('M2-05: every active ingredient listed (0 when no movements), sorted by sku; on_hand = sum of movements', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $r = $hub->get('/api/inventory');
    assert_success($r, 200, 'GET /api/inventory');
    assert_same($hub->locationId(), $r->json['location_id'], 'own location');
    $items = hub_list($r, 'items', 'items');
    assert_same(['SMP001', 'SMP002', 'SMP003', 'SMP004', 'SMP005', 'SMP006'], hub_column($items, 'sku'), 'sorted by sku');
    assert_same(['sku', 'name', 'unit', 'on_hand'], array_keys($items[0]), 'item keys');
    assert_same([], hub_keys_matching($r->json, '/cost|price/i'), 'inventory carries no cost');
    foreach ($items as $item) {
        assert_qty(0, $item['on_hand'], $item['sku'] . ' starts at 0');
    }

    $r = $hub->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 5, 'reason' => 'opening balance']);
    assert_success($r, 201, 'adjust +5');
    $movement = hub_object($r, 'movement', 'movement');
    assert_same(['id', 'location_id', 'sku', 'qty_delta', 'reason', 'note', 'created_at'], array_keys($movement), 'movement keys');
    assert_same('adjustment', $movement['reason'], 'reason is adjustment');
    assert_same('opening balance', $movement['note'], 'note stores the given reason');
    assert_same($hub->locationId(), $movement['location_id'], 'movement location');
    assert_qty(5, $movement['qty_delta'], 'qty_delta');
    assert_qty(5, $r->json['on_hand'], 'on_hand after +5');

    $r = $hub->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => -1.255, 'reason' => 'stock count']);
    assert_success($r, 201, 'adjust -1.255');
    assert_qty(3.745, $r->json['on_hand'], 'on_hand after -1.255');
    assert_success($hub->post('/api/batches', ['recipe_id' => 1, 'quantity' => 24]), 201, 'muffin x24');

    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->exec("INSERT INTO ingredients (sku, name, unit, cost_cents, is_sample, active, created_at, updated_at)
                VALUES ('OLD01', 'Retired item', 'kg', 100, 0, 0, '2026-10-06 00:00:00', '2026-10-06 00:00:00')");
    $items = hub_list($hub->get('/api/inventory'), 'items', 'items');
    assert_true(hub_find($items, 'sku', 'OLD01') === null, 'inactive ingredient not listed');
    assert_qty(2.305, hub_find($items, 'sku', 'SMP001')['on_hand'], '5 - 1.255 - 1.440');
    assert_qty(-0.72, hub_find($items, 'sku', 'SMP002')['on_hand'], 'may be negative');
    assert_qty(0, hub_find($items, 'sku', 'SMP006')['on_hand'], 'SMP006 untouched');
});

test('M2-05: scope - hub location_id ignored, hq must give a hub location_id, franchise 403', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    $hq = $app->client('hq.admin');
    $hubLoc = $hub->locationId();
    $hub2Loc = $hub2->locationId();
    $hqLoc = $hq->locationId();

    $r = $hub->post('/api/inventory/adjustments', ['sku' => 'SMP002', 'qty_delta' => 2, 'reason' => 'count', 'location_id' => $hub2Loc]);
    assert_success($r, 201, 'hub adjustment with a foreign location_id');
    assert_same($hubLoc, $r->json['movement']['location_id'], 'hub location_id ignored on adjustments');
    $r = $hub->get('/api/inventory?location_id=' . $hub2Loc);
    assert_same($hubLoc, $r->json['location_id'], 'hub location_id ignored on GET');
    assert_qty(2, hub_find($r->json['items'], 'sku', 'SMP002')['on_hand'], 'own adjustment visible');
    assert_qty(0, hub_find(hub_list($hub2->get('/api/inventory'), 'items', 'hub2'), 'sku', 'SMP002')['on_hand'], 'Hub Two untouched');

    foreach (['', '?location_id=', '?location_id=abc', '?location_id=' . $hqLoc, '?location_id=9999', '?location_id[]=1'] as $q) {
        assert_error($hq->get('/api/inventory' . $q), 422, 'VALIDATION_ERROR', 'hq GET /api/inventory' . $q);
    }
    $r = $hq->get('/api/inventory?location_id=' . $hubLoc);
    assert_success($r, 200, 'hq reads a hub ledger');
    assert_qty(2, hub_find($r->json['items'], 'sku', 'SMP002')['on_hand'], 'hq sees the hub adjustment');

    assert_error($hq->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 1, 'reason' => 'x']), 422, 'VALIDATION_ERROR', 'hq adjustment without location_id');
    assert_error($hq->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 1, 'reason' => 'x', 'location_id' => (string) $hub2Loc]), 422, 'VALIDATION_ERROR', 'hq location_id as a string');
    assert_error($hq->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 1, 'reason' => 'x', 'location_id' => $hqLoc]), 422, 'VALIDATION_ERROR', 'hq location is not a hub');
    $r = $hq->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 4.5, 'reason' => 'opening balance', 'location_id' => $hub2Loc]);
    assert_success($r, 201, 'hq adjusts Hub Two');
    assert_same($hub2Loc, $r->json['movement']['location_id'], 'hq movement location');
    assert_qty(4.5, hub_find(hub_list($hub2->get('/api/inventory'), 'items', 'hub2'), 'sku', 'SMP001')['on_hand'], 'Hub Two sees it');

    $fr = $app->client('franchise.user');
    assert_error($fr->get('/api/inventory'), 403, 'FORBIDDEN', 'franchise GET');
    assert_error($fr->get('/api/inventory?location_id=' . $hubLoc), 403, 'FORBIDDEN', 'franchise GET with location');
    assert_error($fr->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 1, 'reason' => 'x', 'location_id' => $hubLoc]), 403, 'FORBIDDEN', 'franchise POST');
});

test('M2-05: adjustment 422 for unknown sku, zero / string / 4 dp / too large qty_delta, empty or long reason', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $cases = [
        'unknown sku' => [['sku' => 'NOPE01', 'qty_delta' => 1, 'reason' => 'x'], 'sku'],
        'missing sku' => [['qty_delta' => 1, 'reason' => 'x'], 'sku'],
        'zero delta' => [['sku' => 'SMP001', 'qty_delta' => 0, 'reason' => 'x'], 'qty_delta'],
        'string delta' => [['sku' => 'SMP001', 'qty_delta' => '1', 'reason' => 'x'], 'qty_delta'],
        '4 dp delta' => [['sku' => 'SMP001', 'qty_delta' => 0.0005, 'reason' => 'x'], 'qty_delta'],
        'too large delta' => [['sku' => 'SMP001', 'qty_delta' => -100000.001, 'reason' => 'x'], 'qty_delta'],
        'empty reason' => [['sku' => 'SMP001', 'qty_delta' => 1, 'reason' => ' '], 'reason'],
        'long reason' => [['sku' => 'SMP001', 'qty_delta' => 1, 'reason' => str_repeat('r', 201)], 'reason'],
    ];
    foreach ($cases as $what => [$body, $field]) {
        $r = $hub->post('/api/inventory/adjustments', $body);
        assert_error($r, 422, 'VALIDATION_ERROR', $what);
        assert_true(isset($r->json['errors'][$field]), $what . ': error on ' . $field . '; ' . $r->describe());
    }
    assert_success($hub->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => -100000, 'reason' => 'limit']), 201, '|x| = 100000 allowed');
    $pdo = new PDO('sqlite:' . $app->dbPath());
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn(), 'only the valid adjustment stored');
});

run_tests();
