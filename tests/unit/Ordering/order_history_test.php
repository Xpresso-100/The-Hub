<?php
declare(strict_types=1);

// M1-07 (SPEC s7.5-s7.6, A1): order history is location-scoped; hq sees all with filters.

require __DIR__ . '/../../lib/harness.php';

test('M1-07: hub sees only its own orders, newest first; location_id ignored; other location -> 404', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    $a = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]]])->json['order']['id'];
    $theirs = $hub2->post('/api/orders', ['lines' => [['sku' => 'SMP002', 'qty' => 1]]])->json['order']['id'];
    $b = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 2], ['sku' => 'SMP004', 'qty' => 1]]])->json['order']['id'];

    foreach (['/api/orders', '/api/orders?location_id=' . $hub2->locationId(), '/api/orders?location_id=abc', '/api/orders?status=bogus'] as $path) {
        $r = $hub->get($path);
        assert_success($r, 200, 'hub GET ' . $path);
        $orders = hub_list($r, 'orders', $path);
        assert_same([$b, $a], hub_column($orders, 'id'), 'own orders newest first via ' . $path);
    }
    $first = hub_list($hub->get('/api/orders'), 'orders', 'orders')[0];
    assert_same(['id', 'location_id', 'location_name', 'status', 'total', 'line_count', 'created_at'], array_keys($first), 'summary keys');
    assert_same(2, $first['line_count'], 'line_count');
    assert_money(145.00, $first['total'], 'summary total');

    assert_error($hub->get('/api/orders/' . $theirs), 404, 'NOT_FOUND', "hub GET hub2's order");
    assert_error($hub->get('/api/orders/999999'), 404, 'NOT_FOUND', 'unknown id');
    assert_success($hub2->get('/api/orders/' . $theirs), 200, 'hub2 reads its own order');
    assert_error($app->client('franchise.user')->get('/api/orders'), 403, 'FORBIDDEN', 'franchise list');
    assert_error($app->client('franchise.user')->get('/api/orders/' . $a), 403, 'FORBIDDEN', 'franchise show');
});

test('M1-07: hq sees all orders and filters by location_id and status; invalid filters 422', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    $hq = $app->client('hq.admin');
    $a = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]]])->json['order']['id'];
    $c = $hub2->post('/api/orders', ['lines' => [['sku' => 'SMP002', 'qty' => 1]]])->json['order']['id'];

    assert_same([$c, $a], hub_column(hub_list($hq->get('/api/orders'), 'orders', 'hq all'), 'id'), 'hq sees all, newest first');
    assert_same([$c], hub_column(hub_list($hq->get('/api/orders?location_id=' . $hub2->locationId()), 'orders', 'hq loc'), 'id'), 'location filter');
    assert_success($hq->patch('/api/orders/' . $a . '/status', ['status' => 'in_process']), 200, 'move order a');
    assert_same([$a], hub_column(hub_list($hq->get('/api/orders?status=in_process'), 'orders', 'hq status'), 'id'), 'status filter');
    assert_same([$c], hub_column(hub_list($hq->get('/api/orders?status=pending&location_id=' . $hub2->locationId()), 'orders', 'hq both'), 'id'), 'both filters');
    assert_success($hq->get('/api/orders/' . $c), 200, 'hq reads any order');

    $r = $hq->get('/api/orders?location_id=abc');
    assert_error($r, 422, 'VALIDATION_ERROR', 'bad location_id');
    assert_true(isset($r->json['errors']['location_id']), 'location_id error');
    $r = $hq->get('/api/orders?status=shipped');
    assert_error($r, 422, 'VALIDATION_ERROR', 'bad status');
    assert_true(isset($r->json['errors']['status']), 'status error');
});

run_tests();
