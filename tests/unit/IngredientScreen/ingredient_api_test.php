<?php
declare(strict_types=1);

// Lane B (BUILD-SPEC 2026-11-16 s7) tests 3 and 4: the cost round trip over HTTP, and role guards on the PUT.

require __DIR__ . '/../../lib/harness.php';

/** The ingredient with $sku from GET /api/ingredients?include_inactive=1 as hq, or null. */
function lb_find_ingredient(ApiClient $hq, string $sku): ?array
{
    $r = $hq->get('/api/ingredients?include_inactive=1');
    assert_success($r, 200, 'GET /api/ingredients?include_inactive=1');
    return hub_find(hub_list($r, 'ingredients', 'ingredients list'), 'sku', $sku);
}

test('LB-3: cost round trip as hq.admin: 12.5 and 0.1 come back unchanged', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $base = ['name' => 'Lane B test flour', 'unit' => 'kg', 'fincon_item_no' => null, 'active' => true];

    $r = $hq->put('/api/admin/ingredients/LBTEST1', $base + ['cost_price' => 12.5]);
    assert_success($r, 201, 'PUT create LBTEST1 at 12.5');
    $row = lb_find_ingredient($hq, 'LBTEST1');
    assert_true($row !== null, 'LBTEST1 is listed');
    assert_money(12.5, $row['cost_price'], 'cost after PUT 12.5');

    $r = $hq->put('/api/admin/ingredients/LBTEST1', $base + ['cost_price' => 0.1]);
    assert_success($r, 200, 'PUT update LBTEST1 at 0.1');
    $row = lb_find_ingredient($hq, 'LBTEST1');
    assert_money(0.1, $row['cost_price'] ?? null, 'cost after PUT 0.1');
});

test('LB-3: a string cost "12.50" is refused with 422 on cost_price, and the stored cost is unchanged', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $base = ['name' => 'Lane B test sugar', 'unit' => 'kg', 'fincon_item_no' => null, 'active' => true];
    assert_success($hq->put('/api/admin/ingredients/LBTEST2', $base + ['cost_price' => 3.25]), 201, 'PUT create LBTEST2');

    $r = $hq->put('/api/admin/ingredients/LBTEST2', $base + ['cost_price' => '12.50']);
    assert_error($r, 422, 'VALIDATION_ERROR', 'PUT with a string cost');
    assert_true(isset($r->json['errors']['cost_price']), '422 carries errors.cost_price: ' . $r->describe());

    $row = lb_find_ingredient($hq, 'LBTEST2');
    assert_money(3.25, $row['cost_price'] ?? null, 'cost is still 3.25 after the refused PUT');
});

test('LB-3: deactivate and reactivate by PUT with the full object; blank FinCon item is stored as null', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $obj = ['name' => 'Lane B test salt', 'unit' => 'kg', 'cost_price' => 1.5, 'fincon_item_no' => 'FC-1', 'active' => true];
    assert_success($hq->put('/api/admin/ingredients/LBTEST3', $obj), 201, 'PUT create LBTEST3');

    assert_success($hq->put('/api/admin/ingredients/LBTEST3', ['active' => false, 'fincon_item_no' => null] + $obj), 200, 'PUT deactivate');
    $listed = hub_list($hq->get('/api/ingredients'), 'ingredients', 'default list');
    assert_true(hub_find($listed, 'sku', 'LBTEST3') === null, 'inactive ingredient is hidden from the default list');
    $row = lb_find_ingredient($hq, 'LBTEST3');
    assert_same(false, $row['active'] ?? null, 'active is false with include_inactive=1');
    // `??` would turn a stored null into the fallback, so check the key and the value separately.
    assert_true($row !== null && array_key_exists('fincon_item_no', $row), 'the list returns the key fincon_item_no');
    assert_same(null, $row['fincon_item_no'], 'blank FinCon item stored as null');

    assert_success($hq->put('/api/admin/ingredients/LBTEST3', ['active' => true] + $obj), 200, 'PUT reactivate');
    $row = lb_find_ingredient($hq, 'LBTEST3');
    assert_same(true, $row['active'] ?? null, 'active is true again');
});

test('LB-4: hub.user and franchise.user get 403 on the PUT; no token gets 401', function (): void {
    $app = TestApp::start();
    $body = ['name' => 'Should not exist', 'unit' => 'kg', 'cost_price' => 1.0, 'fincon_item_no' => null, 'active' => true];
    foreach (['hub.user', 'franchise.user'] as $who) {
        $r = $app->client($who)->put('/api/admin/ingredients/LBTEST4', $body);
        assert_status(403, $r, 'PUT as ' . $who);
    }
    assert_status(401, $app->anon()->put('/api/admin/ingredients/LBTEST4', $body), 'PUT without a token');
    $hq = $app->client('hq.admin');
    assert_true(lb_find_ingredient($hq, 'LBTEST4') === null, 'LBTEST4 was not created by the refused calls');
});

run_tests();
