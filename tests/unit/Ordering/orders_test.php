<?php
declare(strict_types=1);

// M1-05 (SPEC s7.4, A3): hub orders ingredients from HQ at cost; validation; roles.

require __DIR__ . '/../../lib/harness.php';
require HUB_TEST_ROOT . '/src/bootstrap.php';

use Hub\Db;
use Hub\Http\HttpError;
use Hub\Modules\Ordering\OrderService;

test('M1-05: unit price is the current cost; client prices ignored; s5.3 order = 425.00; snapshot survives a cost change', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $r = $hub->post('/api/orders', [
        'lines' => [['sku' => 'SMP001', 'qty' => 10, 'unit_price' => 0.01], ['sku' => 'SMP004', 'qty' => 2.5, 'price' => 0.01, 'line_total' => 0.01]],
        'total' => 0.01, 'price_basis' => 'franchise', 'location_id' => 1, 'notes' => '  Back door please  ',
        'requested_delivery_date' => hub_today(),
    ]);
    assert_success($r, 201, 'POST /api/orders');
    $o = hub_object($r, 'order', 'order');
    assert_same('cost', $o['price_basis'] ?? null, 'price_basis');
    assert_same('pending', $o['status'] ?? null, 'status');
    assert_same($hub->locationId(), $o['location_id'] ?? null, 'location from the token, not the body');
    assert_same('The Hub', $o['location_name'] ?? null, 'location_name');
    assert_same('hub.user', $o['created_by'] ?? null, 'created_by');
    assert_same('Back door please', $o['notes'] ?? null, 'notes trimmed');
    assert_same(hub_today(), $o['requested_delivery_date'] ?? null, 'today is a valid delivery date');
    assert_money(12.50, $o['lines'][0]['unit_price'] ?? null, 'SMP001 unit_price');
    assert_money(125.00, $o['lines'][0]['line_total'] ?? null, 'SMP001 line_total');
    assert_money(120.00, $o['lines'][1]['unit_price'] ?? null, 'SMP004 unit_price');
    assert_money(300.00, $o['lines'][1]['line_total'] ?? null, 'SMP004 line_total');
    assert_qty(2.5, $o['lines'][1]['qty'] ?? null, 'SMP004 qty');
    assert_money(425.00, $o['total'] ?? null, 'total');
    assert_true(is_string($o['created_at'] ?? null), 'created_at');

    assert_success($app->client('hq.admin')->put('/api/admin/ingredients/SMP001', ['name' => 'SAMPLE Cake flour', 'unit' => 'kg', 'cost_price' => 99.99, 'fincon_item_no' => 'F-SMP001']), 200, 'cost change');
    $stored = $hub->get('/api/orders/' . $o['id'])->json['order'] ?? [];
    assert_money(12.50, $stored['lines'][0]['unit_price'] ?? null, 'snapshot unit_price kept after cost change');
    assert_money(425.00, $stored['total'] ?? null, 'snapshot total kept');
});

test('M1-05: line cost rounds half up per SPEC s3.1', function (): void {
    $app = TestApp::start();
    // SMP002 18.00/kg x 0.125 = 2.25 exactly; SMP003 45.00/l x 0.011 = 0.495 -> 0.50 (half up)
    $o = hub_object($app->client('hub.user')->post('/api/orders', ['lines' => [['sku' => 'SMP002', 'qty' => 0.125], ['sku' => 'SMP003', 'qty' => 0.011]]]), 'order', 'order');
    assert_money(2.25, $o['lines'][0]['line_total'] ?? null, 'SMP002 line');
    assert_money(0.50, $o['lines'][1]['line_total'] ?? null, 'SMP003 line rounds half up');
    assert_money(2.75, $o['total'] ?? null, 'total');
});

test('M1-05: hq and franchise 403; anonymous 401; bad JSON 400 after the role check', function (): void {
    $app = TestApp::start();
    $body = ['lines' => [['sku' => 'SMP001', 'qty' => 1]]];
    assert_error($app->client('hq.admin')->post('/api/orders', $body), 403, 'FORBIDDEN', 'hq POST');
    assert_error($app->client('franchise.user')->post('/api/orders', $body), 403, 'FORBIDDEN', 'franchise POST');
    assert_error($app->anon()->post('/api/orders', $body), 401, 'UNAUTHENTICATED', 'anonymous POST');
    assert_error($app->request('POST', '/api/orders', '{not json', $app->client('franchise.user')->token), 403, 'FORBIDDEN', 'franchise bad JSON is still 403');
    assert_error($app->request('POST', '/api/orders', '{not json', $app->client('hub.user')->token), 400, 'BAD_JSON', 'hub bad JSON');
});

test('M1-05: every 422 case names its field and saves nothing', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    assert_success($app->client('hq.admin')->put('/api/admin/ingredients/OFF-01', ['name' => 'Off', 'unit' => 'kg', 'cost_price' => 1, 'fincon_item_no' => null, 'active' => false]), 201, 'inactive ingredient');
    $tooMany = [];
    for ($i = 0; $i < 201; $i++) {
        $tooMany[] = ['sku' => 'SMP001', 'qty' => 1];
    }
    $cases = [
        'lines' => [[], ['lines' => []], ['lines' => 'SMP001'], ['lines' => $tooMany], ['lines' => ['a' => ['sku' => 'SMP001', 'qty' => 1]]]],
        'lines.0' => [['lines' => ['SMP001']]],
        'lines.0.sku' => [['lines' => [['sku' => 'NOPE', 'qty' => 1]]], ['lines' => [['sku' => 'OFF-01', 'qty' => 1]]], ['lines' => [['qty' => 1]]]],
        'lines.1.sku' => [['lines' => [['sku' => 'SMP001', 'qty' => 1], ['sku' => 'SMP001', 'qty' => 2]]]],
        'lines.0.qty' => [
            ['lines' => [['sku' => 'SMP001', 'qty' => '10']]], ['lines' => [['sku' => 'SMP001', 'qty' => 0]]],
            ['lines' => [['sku' => 'SMP001', 'qty' => -1]]], ['lines' => [['sku' => 'SMP001', 'qty' => 1.2345]]],
            ['lines' => [['sku' => 'SMP001', 'qty' => 100000.001]]], ['lines' => [['sku' => 'SMP001']]], ['lines' => [['sku' => 'SMP001', 'qty' => true]]],
        ],
        'requested_delivery_date' => [
            ['requested_delivery_date' => hub_date_plus(-1)], ['requested_delivery_date' => '2026-02-30'],
            ['requested_delivery_date' => '8/10/2026'], ['requested_delivery_date' => 20261008],
        ],
        'notes' => [['notes' => str_repeat('n', 501)], ['notes' => ['x']]],
    ];
    $valid = ['lines' => [['sku' => 'SMP001', 'qty' => 1]]];
    foreach ($cases as $field => $variants) {
        foreach ($variants as $variant) {
            $body = str_starts_with($field, 'lines') ? $variant : $variant + $valid;
            $r = $hub->post('/api/orders', $body);
            assert_error($r, 422, 'VALIDATION_ERROR', 'POST ' . substr(hub_export($variant), 0, 120));
            assert_true(isset($r->json['errors'][$field]), 'errors.' . $field . ' for ' . substr(hub_export($variant), 0, 120) . '; ' . $r->describe());
        }
    }
    assert_success($hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 100000]], 'notes' => str_repeat('n', 500)]), 201, 'qty 100000 and 500-char notes are allowed');
    assert_same(1, count(hub_list($hub->get('/api/orders'), 'orders', 'orders')), 'only the valid order was saved');
});

test('M1-05: SAMPLE ingredients are rejected when APP_ENV=production (white-box)', function (): void {
    $dir = hub_test_dir('prod');
    putenv('DB_DRIVER=sqlite');
    putenv('DB_PATH=' . $dir . '/x.sqlite');
    Db::reset();
    Db::execScript((string) file_get_contents(HUB_TEST_ROOT . '/db/schema.sqlite.sql'));
    Db::execScript((string) file_get_contents(HUB_TEST_ROOT . '/db/seed_samples.sql'));
    Db::exec("INSERT INTO locations (name, type, active, created_at) VALUES ('The Hub', 'hub', 1, '2026-10-06 08:00:00')");
    Db::exec("INSERT INTO users (location_id, username, email, password_hash, active, created_at) VALUES (1, 'u', 'u@example.test', 'x', 1, '2026-10-06 08:00:00')");
    Db::exec("INSERT INTO ingredients (sku, name, unit, cost_cents, fincon_item_no, is_sample, active, created_at, updated_at) VALUES ('REAL-1', 'Real flour', 'kg', 1000, NULL, 0, 1, '2026-10-06 08:00:00', '2026-10-06 08:00:00')");
    $user = ['id' => 1, 'role' => 'hub', 'location_id' => 1];

    putenv('APP_ENV=production');
    try {
        OrderService::place($user, ['lines' => [['sku' => 'REAL-1', 'qty' => 1], ['sku' => 'SMP001', 'qty' => 1]]]);
        hub_fail('a SAMPLE ingredient must be rejected in production');
    } catch (HttpError $e) {
        assert_same(422, $e->status, 'status');
        assert_same('sample ingredient', $e->errors['lines.1.sku'] ?? null, 'lines.1.sku reason');
    } finally {
        putenv('APP_ENV=test');
    }
    assert_same(0, (int) Db::value('SELECT COUNT(*) FROM orders'), 'nothing saved');
});

run_tests();
