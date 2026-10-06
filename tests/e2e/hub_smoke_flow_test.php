<?php
declare(strict_types=1);

// E2E smoke flow (SPEC s13). Starts the real app on a free port (php -S, fresh SQLite, migrate + seed
// --fixtures) and drives it only through its public HTTP API, as the browser pages do:
// hub.user logs in -> views stock -> places an ingredient order -> records a production batch ->
// opens the finished-goods report and sees that batch. Values are the hand-calculated ones in SPEC s5.3.
// Black-box: no src/ include, ids are looked up through the API.

require __DIR__ . '/../lib/harness.php';

/** Exact stock statuses the FinCon mock gives for the SAMPLE ingredients (SPEC s7.1). */
const E2E_EXPECTED_STOCK = [
    'SMP001' => 'in_stock',
    'SMP002' => 'low_stock',
    'SMP003' => 'out_of_stock',
    'SMP004' => 'in_stock',
    'SMP005' => 'in_stock',
    'SMP006' => 'in_stock',
];

/** sku => on_hand from GET /api/inventory. */
function e2e_inventory(TestApp $app, string $token): array
{
    $r = $app->request('GET', '/api/inventory', null, $token);
    assert_success($r, 200, 'hub.user GET /api/inventory');
    $out = [];
    foreach (hub_list($r, 'items', 'inventory items') as $item) {
        $out[$item['sku']] = $item['on_hand'];
    }
    return $out;
}

test('E2E: hub user logs in, views stock, orders ingredients at cost, records a batch, sees it in the finished-goods report', function (): void {
    $app = TestApp::start();

    // 0. The app is up and serves the login page a hub user starts from.
    assert_success($app->request('GET', '/api/health'), 200, 'GET /api/health');
    $page = $app->request('GET', '/login.html');
    assert_status(200, $page, 'GET /login.html');
    assert_true(str_contains(strtolower($page->body), '<form'), 'login.html contains a login form');

    // 1. Log in with the same body login.html posts.
    $creds = HUB_FIXTURE_USERS['hub.user'];
    $r = $app->request('POST', '/api/login', ['username' => 'hub.user', 'email' => $creds['email'], 'password' => $creds['password']]);
    assert_success($r, 200, 'POST /api/login as hub.user');
    $token = $r->json['token'] ?? null;
    assert_true(is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1, 'login returns a 64-hex token');
    assert_same('hub', $r->json['user']['role'] ?? null, 'logged-in role');
    assert_same('hub', $r->json['user']['location']['type'] ?? null, 'logged-in location type');
    $hubLocation = $r->json['user']['location']['id'] ?? null;
    assert_true(is_int($hubLocation), 'login returns the hub location id');

    $r = $app->request('GET', '/api/me', null, $token);
    assert_success($r, 200, 'GET /api/me with the new token');
    assert_same('hub.user', $r->json['user']['username'] ?? null, '/api/me username');

    // 2. View stock: read-only FinCon view, statuses only, no cost or price anywhere.
    $r = $app->request('GET', '/api/stock', null, $token);
    assert_success($r, 200, 'hub.user GET /api/stock');
    assert_same('OK', $r->json['fincon_status'] ?? null, 'stock fincon_status');
    assert_same([], hub_keys_matching($r->json, '/cost|price/i'), 'cost/price keys in the stock view');
    $stock = [];
    foreach (hub_list($r, 'items', 'stock items') as $item) {
        $stock[$item['sku']] = $item['stock_status'];
    }
    foreach (E2E_EXPECTED_STOCK as $sku => $status) {
        assert_same($status, $stock[$sku] ?? null, 'stock status of ' . $sku);
    }

    // 3. Order ingredients from HQ at cost (client prices are ignored).
    $r = $app->request('GET', '/api/ingredients', null, $token);
    assert_success($r, 200, 'hub.user GET /api/ingredients');
    $cost = [];
    foreach (hub_list($r, 'ingredients', 'ingredients') as $ingredient) {
        $cost[$ingredient['sku']] = $ingredient['cost_price'];
    }
    assert_money(12.50, $cost['SMP001'] ?? null, 'SMP001 cost_price');
    assert_money(120.00, $cost['SMP004'] ?? null, 'SMP004 cost_price');

    $r = $app->request('POST', '/api/orders', [
        'lines' => [['sku' => 'SMP001', 'qty' => 10, 'unit_price' => 1.00], ['sku' => 'SMP004', 'qty' => 2.5, 'price' => 1.00]],
        'requested_delivery_date' => hub_date_plus(2),
        'notes' => 'E2E smoke order',
    ], $token);
    assert_success($r, 201, 'hub.user POST /api/orders');
    $order = hub_object($r, 'order', 'created order');
    $orderId = $order['id'] ?? null;
    assert_true(is_int($orderId), 'order id is an int');
    assert_same('pending', $order['status'] ?? null, 'new order status');
    assert_same('cost', $order['price_basis'] ?? null, 'order price_basis');
    assert_same($hubLocation, $order['location_id'] ?? null, 'order location');
    $lines = [];
    foreach ($order['lines'] ?? [] as $line) {
        $lines[$line['sku']] = $line;
    }
    assert_money($cost['SMP001'], $lines['SMP001']['unit_price'] ?? null, 'SMP001 unit_price = cost_price');
    assert_money($cost['SMP004'], $lines['SMP004']['unit_price'] ?? null, 'SMP004 unit_price = cost_price');
    assert_money(125.00, $lines['SMP001']['line_total'] ?? null, 'SMP001 line_total');
    assert_money(300.00, $lines['SMP004']['line_total'] ?? null, 'SMP004 line_total');
    assert_money(425.00, $order['total'] ?? null, 'order total');
    assert_same('sent', $order['hq_notification']['status'] ?? null, 'HQ notification status');

    $r = $app->request('GET', '/api/orders', null, $token);
    assert_success($r, 200, 'hub.user GET /api/orders');
    $listed = hub_find(hub_list($r, 'orders', 'order history'), 'id', $orderId);
    assert_true($listed !== null, 'order history lists the new order');
    assert_money(425.00, $listed['total'] ?? null, 'order history total');
    $r = $app->request('GET', '/api/orders/' . $orderId, null, $token);
    assert_success($r, 200, 'hub.user GET /api/orders/{id}');
    assert_money(425.00, $r->json['order']['total'] ?? null, 'order detail total');

    // 4. Record a production batch: 24 x SAMPLE Choc muffin (SPEC s5.3).
    $r = $app->request('GET', '/api/recipes', null, $token);
    assert_success($r, 200, 'hub.user GET /api/recipes');
    $recipe = hub_find(hub_list($r, 'recipes', 'recipes'), 'finished_sku', 'SMP-F01');
    assert_true($recipe !== null && is_int($recipe['id'] ?? null), 'recipe SMP-F01 is listed with an int id');
    assert_money(5.59, $recipe['cost_per_unit'] ?? null, 'muffin recipe cost_per_unit');

    $before = e2e_inventory($app, $token);
    $r = $app->request('POST', '/api/batches', ['recipe_id' => $recipe['id'], 'quantity' => 24, 'notes' => 'E2E smoke batch'], $token);
    assert_success($r, 201, 'hub.user POST /api/batches');
    $batch = hub_object($r, 'batch', 'created batch');
    $batchId = $batch['id'] ?? null;
    assert_true(is_int($batchId), 'batch id is an int');
    assert_same(24, $batch['quantity'] ?? null, 'batch quantity');
    assert_same(hub_today(), $batch['produced_on'] ?? null, 'batch produced_on defaults to today');
    assert_money(134.16, $batch['total_cost'] ?? null, 'batch total_cost');
    assert_money(5.59, $batch['cost_per_item'] ?? null, 'batch cost_per_item');

    $used = ['SMP001' => 1.440, 'SMP002' => 0.720, 'SMP003' => 0.480, 'SMP004' => 0.360, 'SMP005' => 0.240, 'SMP006' => 0.0];
    $after = e2e_inventory($app, $token);
    foreach ($used as $sku => $qty) {
        assert_qty(-$qty, ($after[$sku] ?? 0) - ($before[$sku] ?? 0), 'inventory change of ' . $sku);
    }

    // 5. Open the finished-goods report: the batch is there and the totals add up.
    $r = $app->request('GET', '/api/reports/finished-goods', null, $token);
    assert_success($r, 200, 'hub.user GET /api/reports/finished-goods');
    $reported = hub_find(hub_list($r, 'batches', 'report batches'), 'id', $batchId);
    assert_true($reported !== null, 'the report shows the batch just recorded');
    assert_same(24, $reported['quantity'] ?? null, 'reported batch quantity');
    assert_money(134.16, $reported['total_cost'] ?? null, 'reported batch total_cost');
    $item = hub_find(hub_list($r, 'items', 'report items'), 'finished_sku', 'SMP-F01');
    assert_true($item !== null, 'the report has an SMP-F01 row');
    assert_same(24, $item['quantity'] ?? null, 'SMP-F01 report quantity');
    assert_money(134.16, $item['total_cost'] ?? null, 'SMP-F01 report total_cost');
    assert_money(5.59, $item['avg_cost_per_item'] ?? null, 'SMP-F01 report avg_cost_per_item');
    $totals = hub_object($r, 'totals', 'report totals');
    assert_same(24, $totals['quantity'] ?? null, 'report totals quantity');
    assert_money(134.16, $totals['total_cost'] ?? null, 'report totals total_cost');
    assert_same(1, $totals['batch_count'] ?? null, 'report totals batch_count');

    $r = $app->request('GET', '/api/reports/finished-goods?format=csv', null, $token);
    assert_status(200, $r, 'hub.user GET /api/reports/finished-goods?format=csv');
    assert_true(str_starts_with((string) $r->header('content-type'), 'text/csv'), 'CSV content type; got ' . hub_export($r->header('content-type')));
    $rows = array_values(array_filter(preg_split('/\R/', $r->body) ?: [], static fn (string $l): bool => $l !== ''));
    assert_same('finished_sku,name,unit,quantity,total_cost,avg_cost_per_item,batch_count', $rows[0] ?? null, 'CSV header row');
    assert_same('TOTAL,,,24,134.16,,1', end($rows), 'CSV TOTAL row');

    // 6. Nothing in the flow wrote to FinCon; HQ was told about the order.
    $mock = hub_json_lines($app->mockLog());
    assert_true(count($mock) >= 1, 'the stock view read FinCon through the mock');
    foreach ($mock as $i => $line) {
        assert_same('read', $line['kind'] ?? null, 'FinCon mock log line ' . $i . ' kind');
    }
    assert_true(is_file($app->mailLog()) && filesize($app->mailLog()) > 0, 'the HQ order notification was written to the mail log');

    // 7. Log out: the token stops working.
    assert_success($app->request('POST', '/api/logout', [], $token), 200, 'POST /api/logout');
    assert_error($app->request('GET', '/api/me', null, $token), 401, 'UNAUTHENTICATED', 'GET /api/me after logout');
});

test('E2E: every page in the flow and every asset it loads is served by the real app', function (): void {
    $app = TestApp::start();
    foreach (['/login.html', '/index.html', '/ordering.html', '/recipes.html', '/reports.html'] as $path) {
        $page = $app->request('GET', $path);
        assert_status(200, $page, 'GET ' . $path);
        assert_true(str_contains(strtolower((string) $page->header('content-type')), 'text/html'), $path . ' is text/html; got ' . hub_export($page->header('content-type')));
        preg_match_all('/(?:src|href)="(assets\/[^"]+)"/', $page->body, $m);
        assert_true(count($m[1]) >= 2, $path . ' loads its stylesheet and scripts');
        foreach ($m[1] as $asset) {
            assert_status(200, $app->request('GET', '/' . $asset), $path . ' asset ' . $asset);
        }
    }
});

run_tests();
