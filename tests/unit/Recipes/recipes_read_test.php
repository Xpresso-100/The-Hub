<?php
declare(strict_types=1);

// M2-01 (SPEC s8.1): GET /api/recipes and /api/recipes/{id} with cost per unit from current ingredient costs.

require __DIR__ . '/../../lib/harness.php';

test('M2-01: three SAMPLE recipes with cost_per_unit 5.59 / 4.38 / 22.66, per-line costs, sorted by finished_sku', function (): void {
    $app = TestApp::start();
    foreach (['hub.user', 'hq.admin'] as $who) {
        $r = $app->client($who)->get('/api/recipes');
        assert_success($r, 200, 'GET /api/recipes as ' . $who);
        $recipes = hub_list($r, 'recipes', 'recipes');
        assert_same(['SMP-F01', 'SMP-F02', 'SMP-F03'], hub_column($recipes, 'finished_sku'), 'sorted by finished_sku');
        assert_same([5.59, 4.38, 22.66], hub_column($recipes, 'cost_per_unit'), 'cost_per_unit (s5.2)');
        foreach ($recipes as $recipe) {
            assert_true($recipe['is_sample'] === true, $recipe['finished_sku'] . ' is marked SAMPLE');
            assert_true(str_starts_with($recipe['name'], 'SAMPLE '), $recipe['finished_sku'] . ' name starts with SAMPLE');
        }
    }

    $hub = $app->client('hub.user');
    $id = hub_find(hub_list($hub->get('/api/recipes'), 'recipes', 'recipes'), 'finished_sku', 'SMP-F01')['id'];
    $r = $hub->get('/api/recipes/' . $id);
    assert_success($r, 200, 'GET /api/recipes/{muffin}');
    $recipe = hub_object($r, 'recipe', 'recipe');
    assert_same(['id', 'finished_sku', 'name', 'unit', 'is_sample', 'active', 'cost_per_unit', 'lines'], array_keys($recipe), 'RECIPE keys');
    assert_same(['sku', 'name', 'unit', 'qty', 'unit_cost', 'line_cost'], array_keys($recipe['lines'][0]), 'RECIPE line keys');
    $flour = hub_find($recipe['lines'], 'sku', 'SMP001');
    assert_qty(0.06, $flour['qty'], 'flour qty per muffin');
    assert_money(12.5, $flour['unit_cost'], 'flour unit cost');
    assert_money(0.75, $flour['line_cost'], 'flour line cost 0.060 x 12.50');
    assert_money(0.54, hub_find($recipe['lines'], 'sku', 'SMP002')['line_cost'], 'sugar line cost 0.030 x 18.00');

    assert_error($hub->get('/api/recipes/9999'), 404, 'NOT_FOUND', 'unknown recipe id');
});

test('M2-01: franchise 403, anonymous 401; cost uses the CURRENT ingredient cost', function (): void {
    $app = TestApp::start();
    $fr = $app->client('franchise.user');
    assert_error($fr->get('/api/recipes'), 403, 'FORBIDDEN', 'franchise list');
    assert_error($fr->get('/api/recipes/1'), 403, 'FORBIDDEN', 'franchise detail');
    assert_error($app->anon()->get('/api/recipes'), 401, 'UNAUTHENTICATED', 'anonymous list');

    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->exec("UPDATE ingredients SET cost_cents = 1300 WHERE sku = 'SMP001'");
    $r = $app->client('hub.user')->get('/api/recipes');
    $muffin = hub_find(hub_list($r, 'recipes', 'recipes'), 'finished_sku', 'SMP-F01');
    assert_money(5.62, $muffin['cost_per_unit'], 'muffin at flour 13.00: 0.78 + 0.54 + 0.90 + 1.80 + 1.60');
});

run_tests();
