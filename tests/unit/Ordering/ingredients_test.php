<?php
declare(strict_types=1);

// M1-02 / M1-03 (SPEC s7.2, s7.3): ingredient catalogue at cost; HQ sets cost prices.

require __DIR__ . '/../../lib/harness.php';

const ING_SKUS = ['SMP001', 'SMP002', 'SMP003', 'SMP004', 'SMP005', 'SMP006'];

test('M1-02: hub and hq list active ingredients sorted by sku with cost_price; franchise 403', function (): void {
    $app = TestApp::start();
    foreach (['hub.user', 'hq.admin'] as $who) {
        $r = $app->client($who)->get('/api/ingredients');
        assert_success($r, 200, $who . ' GET /api/ingredients');
        $list = hub_list($r, 'ingredients', 'ingredients');
        assert_same(ING_SKUS, hub_column($list, 'sku'), $who . ' sorted skus');
        $flour = hub_find($list, 'sku', 'SMP001');
        assert_money(12.50, $flour['cost_price'] ?? null, 'SMP001 cost_price');
        assert_same('F-SMP001', $flour['fincon_item_no'] ?? null, 'SMP001 fincon_item_no');
        $milk = hub_find($list, 'sku', 'SMP006') ?? [];
        assert_true(array_key_exists('fincon_item_no', $milk) && $milk['fincon_item_no'] === null, 'SMP006 unmapped: ' . hub_export($milk));
        assert_same(true, $flour['is_sample'] ?? null, 'is_sample');
        assert_same(true, $flour['active'] ?? null, 'active');
    }
    assert_error($app->client('franchise.user')->get('/api/ingredients'), 403, 'FORBIDDEN', 'franchise GET /api/ingredients');
    assert_error($app->anon()->get('/api/ingredients'), 401, 'UNAUTHENTICATED', 'anonymous GET /api/ingredients');
});

test('M1-02: inactive ingredients only appear for hq with include_inactive=1', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $r = $hq->put('/api/admin/ingredients/OLD-01', ['name' => 'Retired item', 'unit' => 'kg', 'cost_price' => 3, 'fincon_item_no' => null, 'active' => false]);
    assert_success($r, 201, 'create inactive ingredient');

    assert_true(hub_find(hub_list($hq->get('/api/ingredients'), 'ingredients', 'hq'), 'sku', 'OLD-01') === null, 'hq default list hides inactive');
    $all = hub_list($hq->get('/api/ingredients?include_inactive=1'), 'ingredients', 'hq inactive');
    assert_same(false, hub_find($all, 'sku', 'OLD-01')['active'] ?? null, 'hq include_inactive shows it');
    $hubList = hub_list($app->client('hub.user')->get('/api/ingredients?include_inactive=1'), 'ingredients', 'hub');
    assert_true(hub_find($hubList, 'sku', 'OLD-01') === null, 'hub cannot use include_inactive');
});

test('M1-03: hq creates (201) and updates (200); hub and franchise 403', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $body = ['name' => 'Brown sugar', 'unit' => 'kg', 'cost_price' => 21.35, 'fincon_item_no' => 'F-BS01'];

    assert_error($app->client('hub.user')->put('/api/admin/ingredients/BS-01', $body), 403, 'FORBIDDEN', 'hub PUT');
    assert_error($app->client('franchise.user')->put('/api/admin/ingredients/BS-01', $body), 403, 'FORBIDDEN', 'franchise PUT');

    $r = $hq->put('/api/admin/ingredients/BS-01', $body);
    assert_success($r, 201, 'create');
    $ing = hub_object($r, 'ingredient', 'create');
    assert_same('BS-01', $ing['sku'] ?? null, 'sku');
    assert_money(21.35, $ing['cost_price'] ?? null, 'cost_price');
    assert_same(false, $ing['is_sample'] ?? null, 'created ingredient is not a sample');

    $r = $hq->put('/api/admin/ingredients/BS-01', ['cost_price' => 22] + $body);
    assert_success($r, 200, 'update');
    assert_money(22.00, $r->json['ingredient']['cost_price'] ?? null, 'updated cost');
    assert_status(404, $hq->put('/api/admin/ingredients/bad-sku', $body), 'invalid sku path');
});

test('M1-03: validation 422 - negative cost, more than 2 dp, string cost, name and unit length, bad item no', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $ok = ['name' => 'Brown sugar', 'unit' => 'kg', 'cost_price' => 21.35, 'fincon_item_no' => 'F-BS01'];
    $cases = [
        'cost_price' => [['cost_price' => -1], ['cost_price' => 1.234], ['cost_price' => '12.50']],
        'name' => [['name' => ''], ['name' => str_repeat('x', 151)], ['name' => 5]],
        'unit' => [['unit' => ''], ['unit' => str_repeat('u', 21)]],
        'fincon_item_no' => [['fincon_item_no' => str_repeat('9', 26)], ['fincon_item_no' => 12]],
        'active' => [['active' => 'yes']],
    ];
    foreach ($cases as $field => $variants) {
        foreach ($variants as $variant) {
            $r = $hq->put('/api/admin/ingredients/BS-01', $variant + $ok);
            assert_error($r, 422, 'VALIDATION_ERROR', 'PUT with ' . hub_export($variant));
            assert_true(isset($r->json['errors'][$field]), 'error names field ' . $field . '; ' . $r->describe());
        }
    }
    assert_error($app->request('PUT', '/api/admin/ingredients/BS-01', null, $hq->token), 422, 'VALIDATION_ERROR', 'empty body');
    assert_error($app->request('PUT', '/api/admin/ingredients/BS-01', null, $app->client('hub.user')->token), 403, 'FORBIDDEN', 'role is checked before the body');
});

run_tests();
