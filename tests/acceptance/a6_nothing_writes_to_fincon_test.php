<?php
declare(strict_types=1);

// A6 (SPEC s12): Nothing writes to FinCon.
// App started with FINCON_ADAPTER=http against the fake FinCon (s10.4). Run the whole E2E flow plus an order
// status change and an inventory adjustment. Fake log: at least one request, every `function` in
// {Login, GetStockQuantitiesForOpt2, GetStockQuantities, Logout}, every request had CF-Access headers and
// Basic auth. Mock run: every FINCON_MOCK_LOG line has "kind":"read".

require __DIR__ . '/helpers.php';

/** SPEC s13 E2E flow + an order taken to delivered + an inventory adjustment, touching every module. */
function a6_flow(TestApp $app): void
{
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');

    assert_success($hub->get('/api/stock'), 200, 'hub.user GET /api/stock');
    $order = acc_place_order($hub, [['sku' => 'SMP001', 'qty' => 2]]);
    foreach (['in_process', 'out_for_delivery', 'delivered'] as $status) {
        $r = $hq->patch('/api/orders/' . $order['id'] . '/status', ['status' => $status]);
        assert_success($r, 200, 'hq.admin PATCH order status ' . $status);
        assert_same($status, $r->json['order']['status'] ?? null, 'order status after PATCH');
    }
    [$batch] = acc_record_batch($hub, MUFFIN, 24);
    $r = $hub->post('/api/inventory/adjustments', ['sku' => 'SMP002', 'qty_delta' => 1.5, 'reason' => 'stock count']);
    assert_success($r, 201, 'hub.user POST /api/inventory/adjustments');

    $r = $hub->get('/api/reports/finished-goods');
    assert_success($r, 200, 'hub.user GET /api/reports/finished-goods');
    assert_true(in_array($batch['id'], hub_column(hub_list($r, 'batches', 'report batches'), 'id'), true), 'the report shows the batch just recorded');

    assert_success($hq->get('/api/stock'), 200, 'hq.admin GET /api/stock');
    assert_success($app->client('franchise.user')->get('/api/stock'), 200, 'franchise.user GET /api/stock');
}

test('A6: real HTTP adapter against the fake FinCon - only read functions are called, each with CF-Access headers and Basic auth', function (): void {
    $fake = FakeFincon::start();
    $app = TestApp::start($fake->appEnv());
    a6_flow($app);

    $requests = $fake->requests();
    assert_true(count($requests) >= 1, 'the app called the fake FinCon at least once');
    $functions = hub_column($requests, 'function');
    foreach ($requests as $i => $request) {
        assert_true(in_array($request['function'] ?? null, READ_ONLY_FINCON_FUNCTIONS, true), 'request ' . $i . ' calls a read-only function: ' . hub_export($request));
        assert_true(($request['has_cf_id'] ?? false) === true && ($request['has_cf_secret'] ?? false) === true, 'request ' . $i . ' carries both CF-Access headers: ' . hub_export($request));
        assert_true(($request['has_basic'] ?? false) === true, 'request ' . $i . ' carries HTTP Basic auth: ' . hub_export($request));
    }
    $reads = count(array_intersect($functions, ['GetStockQuantitiesForOpt2', 'GetStockQuantities']));
    assert_true($reads >= 1, 'at least one stock read; functions called: ' . hub_export($functions));
    assert_same(
        count(array_keys($functions, 'Login', true)),
        count(array_keys($functions, 'Logout', true)),
        'every FinCon session is released (Login count = Logout count)'
    );
});

test('A6: mock adapter - every FinCon call in the flow is logged as kind=read; ledger moves stay in The Hub', function (): void {
    $app = TestApp::start();
    a6_flow($app);

    $lines = hub_json_lines($app->mockLog());
    assert_true(count($lines) >= 1, 'the flow read FinCon through the mock at least once');
    foreach ($lines as $i => $line) {
        assert_same('read', $line['kind'] ?? null, 'mock log line ' . $i . ' kind');
    }

    // The delivered order (+2.000 SMP001) and the adjustment (+1.500 SMP002) landed in The Hub's own ledger.
    $inventory = acc_inventory($app->client('hub.user'));
    assert_qty(0.56, $inventory['SMP001'], 'SMP001 = +2.000 delivered - 1.440 batch');
    assert_qty(0.78, $inventory['SMP002'], 'SMP002 = +1.500 adjustment - 0.720 batch');
});

run_tests();
