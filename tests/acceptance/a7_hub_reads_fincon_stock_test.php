<?php
declare(strict_types=1);

// A7 (SPEC s12, locked rule): Hub users CAN read FinCon stock.
// hub.user GET /api/stock -> 200, fincon_status OK, statuses exactly as s7.1 for the mock and for the fake FinCon.

require __DIR__ . '/helpers.php';

function a7_assert_stock(HttpResult $r, string $who): void
{
    assert_success($r, 200, $who . ' GET /api/stock');
    assert_same('OK', $r->json['fincon_status'] ?? null, $who . ' fincon_status');
    assert_same('fincon', $r->json['source'] ?? null, $who . ' source');
    assert_same(false, $r->json['stale'] ?? null, $who . ' stale');
    assert_true(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/', (string) ($r->json['fetched_at'] ?? '')) === 1,
        $who . ' fetched_at is an ISO 8601 timestamp; ' . $r->describe()
    );
    $items = hub_list($r, 'items', $who . ' stock items');
    assert_same(array_keys(EXPECTED_STOCK), hub_column($items, 'sku'), $who . ': every active ingredient, sorted by sku');
    foreach ($items as $item) {
        assert_same(EXPECTED_STOCK[$item['sku']], $item['stock_status'] ?? null, $who . ' stock_status of ' . $item['sku']);
        assert_true(is_string($item['name'] ?? null) && is_string($item['unit'] ?? null), $who . ' item has name and unit: ' . hub_export($item));
    }
    assert_same([], hub_keys_matching($r->json, '/cost|price/i'), $who . ': keys matching /cost|price/i');
}

test('A7: hub.user reads FinCon stock through the mock - 200, fincon_status OK, statuses as SPEC s7.1', function (): void {
    $app = TestApp::start();
    a7_assert_stock($app->client('hub.user')->get('/api/stock'), 'hub.user (mock)');
    a7_assert_stock($app->client('hq.admin')->get('/api/stock'), 'hq.admin (mock)');
    assert_error($app->anon()->get('/api/stock'), 401, 'UNAUTHENTICATED', 'GET /api/stock without a token');
});

test('A7: hub.user reads FinCon stock through the real HTTP adapter and the fake FinCon tunnel', function (): void {
    $fake = FakeFincon::start();
    $app = TestApp::start($fake->appEnv());
    a7_assert_stock($app->client('hub.user')->get('/api/stock'), 'hub.user (fake FinCon)');
    assert_true(in_array('GetStockQuantitiesForOpt2', hub_column($fake->requests(), 'function'), true), 'stock was read from the fake FinCon');
});

test('A7: FinCon unreachable - still 200, every item fails open to in_stock, stale with the failure code', function (): void {
    $app = TestApp::start([
        'FINCON_ADAPTER' => 'http',
        'FINCON_BASE_URL' => 'http://127.0.0.1:' . hub_free_port(),
        'FINCON_CF_CLIENT_ID' => 'test-cf-client-id',
        'FINCON_CF_CLIENT_SECRET' => 'test-cf-client-secret',
        'FINCON_SRV_USER' => 'test-srv-user',
        'FINCON_SRV_PASS' => 'test-srv-pass',
        'FINCON_TIMEOUT_SECONDS' => '5',
    ]);
    $r = $app->client('hub.user')->get('/api/stock');
    assert_success($r, 200, 'hub.user GET /api/stock with FinCon down');
    assert_true(in_array($r->json['fincon_status'] ?? null, ['FINCON_UNREACHABLE', 'FINCON_MAINTENANCE'], true), 'fincon_status names the failure; ' . $r->describe());
    assert_same(true, $r->json['stale'] ?? null, 'stale when FinCon failed');
    assert_true(array_key_exists('fetched_at', $r->json) && $r->json['fetched_at'] === null, 'fetched_at is null when stock was never fetched; ' . $r->describe());
    $items = hub_list($r, 'items', 'stock items');
    assert_same(array_keys(EXPECTED_STOCK), hub_column($items, 'sku'), 'every active ingredient is still listed');
    foreach ($items as $item) {
        assert_same('in_stock', $item['stock_status'] ?? null, 'fail-open status of ' . $item['sku']);
    }
});

run_tests();
