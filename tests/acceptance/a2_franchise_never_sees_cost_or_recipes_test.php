<?php
declare(strict_types=1);

// A2 (SPEC s12): Franchise users never see cost prices or recipes.
// franchise.user gets 403 on every route in s7-s9 except GET /api/stock. GET /api/me and GET /api/stock
// return 200 with no key matching /cost|price|recipe/i at any depth and no recipe name.

require __DIR__ . '/helpers.php';

test('A2: franchise.user gets 403 FORBIDDEN on every s7-s9 route except GET /api/stock, and changes nothing', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');
    $franchise = $app->client('franchise.user');

    // Real ids and valid bodies, so the role is the only reason left to refuse.
    $order = acc_place_order($hub, [['sku' => 'SMP001', 'qty' => 1]]);
    [$batch] = acc_record_batch($hub, MUFFIN, 1);
    $recipeId = acc_recipe_id($hub, MUFFIN);
    $hubLocation = $hub->locationId();

    $routes = [
        ['GET', '/api/ingredients', null],
        ['GET', '/api/ingredients?include_inactive=1', null],
        ['PUT', '/api/admin/ingredients/SMP001', ['name' => 'SAMPLE Cake flour', 'unit' => 'kg', 'cost_price' => 1.0, 'fincon_item_no' => 'F-SMP001']],
        ['POST', '/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]]]],
        ['GET', '/api/orders', null],
        ['GET', '/api/orders/' . $order['id'], null],
        ['PATCH', '/api/orders/' . $order['id'] . '/status', ['status' => 'cancelled']],
        ['GET', '/api/recipes', null],
        ['GET', '/api/recipes/' . $recipeId, null],
        ['POST', '/api/recipes', ['finished_sku' => 'FR-TEST', 'name' => 'Franchise test', 'unit' => 'each', 'lines' => [['sku' => 'SMP001', 'qty' => 1]]]],
        ['PUT', '/api/recipes/' . $recipeId, ['finished_sku' => MUFFIN, 'name' => 'Changed by franchise', 'unit' => 'each', 'lines' => [['sku' => 'SMP001', 'qty' => 1]]]],
        ['POST', '/api/batches', ['recipe_id' => $recipeId, 'quantity' => 1]],
        ['GET', '/api/batches/' . $batch['id'], null],
        ['GET', '/api/inventory', null],
        ['GET', '/api/inventory?location_id=' . $hubLocation, null],
        ['POST', '/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 1, 'reason' => 'franchise test', 'location_id' => $hubLocation]],
        ['GET', '/api/reports/finished-goods', null],
        ['GET', '/api/reports/finished-goods?format=csv', null],
        ['GET', '/api/admin/recipes', null],
        ['GET', '/api/admin/batches', null],
    ];
    foreach ($routes as [$method, $path, $body]) {
        $r = $app->request($method, $path, $body, $franchise->token);
        assert_error($r, 403, 'FORBIDDEN', 'franchise.user ' . $method . ' ' . $path);
        assert_same([], hub_keys_matching($r->json, '/cost|price|recipe/i'), 'no cost/price/recipe keys in the 403 body of ' . $path);
    }

    $ingredients = $hq->get('/api/ingredients');
    assert_success($ingredients, 200, 'hq.admin GET /api/ingredients');
    $flour = acc_pick(hub_list($ingredients, 'ingredients', 'ingredients'), 'sku', 'SMP001', 'ingredients');
    assert_money(12.50, $flour['cost_price'] ?? null, 'SMP001 cost unchanged after the refused franchise PUT');
    $r = $hq->get('/api/orders/' . $order['id']);
    assert_success($r, 200, 'hq.admin GET order');
    assert_same('pending', $r->json['order']['status'] ?? null, 'order status unchanged after the refused franchise PATCH');
    $r = $hub->get('/api/recipes/' . $recipeId);
    assert_success($r, 200, 'hub.user GET recipe');
    assert_same('SAMPLE Choc muffin', $r->json['recipe']['name'] ?? null, 'recipe unchanged after the refused franchise PUT');
    $r = $hq->get('/api/recipes?include_inactive=1');
    assert_success($r, 200, 'hq.admin GET /api/recipes?include_inactive=1');
    assert_true(hub_find(hub_list($r, 'recipes', 'recipes'), 'finished_sku', 'FR-TEST') === null, 'refused franchise POST /api/recipes created nothing');
});

test('A2: franchise.user GET /api/me and GET /api/stock are 200 with no cost/price/recipe key at any depth and no recipe name', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $franchise = $app->client('franchise.user');
    acc_record_batch($hub, MUFFIN, 2);                       // there is recipe/batch data that could leak
    acc_place_order($hub, [['sku' => 'SMP004', 'qty' => 1]]);  // and an at-cost order

    foreach (['/api/me', '/api/stock'] as $path) {
        $r = $franchise->get($path);
        assert_success($r, 200, 'franchise.user GET ' . $path);
        assert_same([], hub_keys_matching($r->json, '/cost|price|recipe/i'), 'keys matching /cost|price|recipe/i in ' . $path);
        foreach (array_merge(SAMPLE_RECIPE_NAMES, [MUFFIN, SCONE, CUSTARD]) as $needle) {
            assert_true(!str_contains($r->body, $needle), $path . ' must not mention recipe "' . $needle . '"; ' . $r->describe());
        }
    }

    $r = $franchise->get('/api/stock');
    $items = hub_list($r, 'items', 'franchise.user GET /api/stock');
    foreach (EXPECTED_STOCK as $sku => $status) {
        $item = acc_pick($items, 'sku', $sku, 'franchise stock');
        assert_same($status, $item['stock_status'] ?? null, 'franchise.user stock_status of ' . $sku . ' (same FinCon visibility as the hub)');
    }
});

run_tests();
