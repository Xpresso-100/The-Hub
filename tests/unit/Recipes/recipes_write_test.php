<?php
declare(strict_types=1);

// M2-02 (SPEC s8.2): POST/PUT /api/recipes - create, edit (replaces lines), 409 on duplicate finished_sku,
// 422 validation, franchise 403, past batches unchanged after an edit.

require __DIR__ . '/../../lib/harness.php';

function m2_recipe_body(array $overrides = []): array
{
    return $overrides + [
        'finished_sku' => 'TST-F10',
        'name' => 'Test brownie',
        'unit' => 'each',
        'lines' => [['sku' => 'SMP001', 'qty' => 0.05], ['sku' => 'SMP005', 'qty' => 0.012]],
    ];
}

test('M2-02: create 201 with costed lines, update 200 replaces all lines, deactivate hides from the active list', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hub = $app->client('hub.user');

    $r = $hub->post('/api/recipes', m2_recipe_body());
    assert_success($r, 201, 'hub POST /api/recipes');
    $recipe = hub_object($r, 'recipe', 'created recipe');
    assert_true(is_int($recipe['id']), 'id is an integer');
    assert_same(false, $recipe['is_sample'], 'a new recipe is not SAMPLE');
    assert_same(true, $recipe['active'], 'a new recipe is active');
    assert_same(['SMP001', 'SMP005'], hub_column($recipe['lines'], 'sku'), 'lines in entry order');
    assert_money(2.55, $recipe['cost_per_unit'], '0.050 x 12.50 = 0.63 (0.625 half-up) + 0.012 x 160.00 = 1.92');

    $r = $hq->put('/api/recipes/' . $recipe['id'], m2_recipe_body(['name' => 'Test brownie v2', 'lines' => [['sku' => 'SMP004', 'qty' => 0.1]]]));
    assert_success($r, 200, 'hq PUT /api/recipes/{id}');
    $updated = hub_object($r, 'recipe', 'updated recipe');
    assert_same('Test brownie v2', $updated['name'], 'name updated');
    assert_same(['SMP004'], hub_column($updated['lines'], 'sku'), 'lines replaced');
    assert_money(12.0, $updated['cost_per_unit'], '0.100 x 120.00');

    $r = $hub->put('/api/recipes/' . $recipe['id'], m2_recipe_body(['active' => false]));
    assert_success($r, 200, 'deactivate');
    assert_same(false, $r->json['recipe']['active'], 'active false');
    $active = hub_column(hub_list($hub->get('/api/recipes'), 'recipes', 'list'), 'finished_sku');
    assert_true(!in_array('TST-F10', $active, true), 'inactive recipe not in the default list');
    $all = hub_column(hub_list($hub->get('/api/recipes?include_inactive=1'), 'recipes', 'list'), 'finished_sku');
    assert_true(in_array('TST-F10', $all, true), 'inactive recipe listed with include_inactive=1');
    assert_success($hub->get('/api/recipes/' . $recipe['id']), 200, 'inactive recipe detail still readable');
});

test('M2-02: 409 duplicate finished_sku, 404 unknown id, 403 franchise, 400 bad json', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    assert_error($hub->post('/api/recipes', m2_recipe_body(['finished_sku' => 'SMP-F01'])), 409, 'CONFLICT', 'POST duplicate finished_sku');
    $id = hub_object($hub->post('/api/recipes', m2_recipe_body()), 'recipe', 'create')['id'];
    assert_error($hub->put('/api/recipes/' . $id, m2_recipe_body(['finished_sku' => 'SMP-F02'])), 409, 'CONFLICT', 'PUT onto another recipe sku');
    assert_success($hub->put('/api/recipes/' . $id, m2_recipe_body()), 200, 'PUT keeping its own sku is fine');
    assert_error($hub->put('/api/recipes/9999', m2_recipe_body()), 404, 'NOT_FOUND', 'PUT unknown id');
    $fr = $app->client('franchise.user');
    assert_error($fr->post('/api/recipes', m2_recipe_body(['finished_sku' => 'FR-X'])), 403, 'FORBIDDEN', 'franchise POST');
    assert_error($fr->put('/api/recipes/' . $id, m2_recipe_body()), 403, 'FORBIDDEN', 'franchise PUT');
    $r = $app->request('POST', '/api/recipes', '{not json', $app->login('hub.user'));
    assert_error($r, 400, 'BAD_JSON', 'malformed body');
});

test('M2-02: 422 for empty lines, unknown/inactive sku, qty <= 0 or > 3 dp or > 100000, string qty, duplicate sku, bad fields', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->exec("INSERT INTO ingredients (sku, name, unit, cost_cents, is_sample, active, created_at, updated_at)
                VALUES ('OLD01', 'Retired item', 'kg', 100, 0, 0, '2026-10-06 00:00:00', '2026-10-06 00:00:00')");
    $cases = [
        'empty lines' => [['lines' => []], 'lines'],
        'missing lines' => [['lines' => null], 'lines'],
        'unknown sku' => [['lines' => [['sku' => 'NOPE01', 'qty' => 1]]], 'lines.0.sku'],
        'inactive sku' => [['lines' => [['sku' => 'OLD01', 'qty' => 1]]], 'lines.0.sku'],
        'qty zero' => [['lines' => [['sku' => 'SMP001', 'qty' => 0]]], 'lines.0.qty'],
        'qty negative' => [['lines' => [['sku' => 'SMP001', 'qty' => -1]]], 'lines.0.qty'],
        'qty 4 dp' => [['lines' => [['sku' => 'SMP001', 'qty' => 0.0001]]], 'lines.0.qty'],
        'qty too big' => [['lines' => [['sku' => 'SMP001', 'qty' => 100001]]], 'lines.0.qty'],
        'qty string' => [['lines' => [['sku' => 'SMP001', 'qty' => '1']]], 'lines.0.qty'],
        'duplicate sku' => [['lines' => [['sku' => 'SMP001', 'qty' => 1], ['sku' => 'SMP001', 'qty' => 2]]], 'lines.1.sku'],
        'bad finished_sku' => [['finished_sku' => 'lower-case'], 'finished_sku'],
        'empty name' => [['name' => '  '], 'name'],
        'long unit' => [['unit' => str_repeat('u', 21)], 'unit'],
    ];
    foreach ($cases as $what => [$override, $field]) {
        $r = $hub->post('/api/recipes', m2_recipe_body($override));
        assert_error($r, 422, 'VALIDATION_ERROR', $what);
        assert_true(isset($r->json['errors'][$field]), $what . ': error on ' . $field . '; ' . $r->describe());
    }
    $tooMany = [];
    foreach (range(1, 51) as $i) {
        $tooMany[] = ['sku' => 'SMP001', 'qty' => 1];
    }
    assert_error($hub->post('/api/recipes', m2_recipe_body(['lines' => $tooMany])), 422, 'VALIDATION_ERROR', '51 lines');
    $id = hub_object($hub->post('/api/recipes', m2_recipe_body()), 'recipe', 'create')['id'];
    assert_error($hub->put('/api/recipes/' . $id, m2_recipe_body(['active' => 'no'])), 422, 'VALIDATION_ERROR', 'active must be bool');
    $all = hub_column(hub_list($hub->get('/api/recipes?include_inactive=1'), 'recipes', 'list'), 'finished_sku');
    assert_same(['SMP-F01', 'SMP-F02', 'SMP-F03', 'TST-F10'], $all, 'rejected requests created nothing');
});

run_tests();
