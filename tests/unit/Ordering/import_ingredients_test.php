<?php
declare(strict_types=1);

// M1-10 (SPEC s2.3): php bin/import_ingredients.php <file.csv> - upsert; all or nothing with line numbers.

require __DIR__ . '/../../lib/harness.php';

function import_csv(TestApp $app, string $csv): array
{
    $file = $app->dir . '/import-' . bin2hex(random_bytes(3)) . '.csv';
    file_put_contents($file, $csv);
    return hub_php(['bin/import_ingredients.php', $file], $app->env);
}

function import_catalogue(TestApp $app): array
{
    $r = $app->client('hq.admin')->get('/api/ingredients?include_inactive=1');
    assert_success($r, 200, 'hq GET /api/ingredients');
    $out = [];
    foreach (hub_list($r, 'ingredients', 'ingredients') as $i) {
        $out[$i['sku']] = $i;
    }
    return $out;
}

test('M1-10: header, BOM, quoting and blank lines handled; creates new and updates existing skus', function (): void {
    $app = TestApp::start();
    assert_success($app->client('hq.admin')->put('/api/admin/ingredients/OLD-1', ['name' => 'Old', 'unit' => 'kg', 'cost_price' => 1, 'fincon_item_no' => null, 'active' => false]), 201, 'inactive ingredient');
    $csv = "\xEF\xBB\xBFsku,name,unit,cost_price,fincon_item_no\r\n"
        . "RL-001,\"Flour, cake (25 kg)\",kg,12.5,F-RL001\r\n"
        . "\r\n"
        . "SMP001,SAMPLE Cake flour,kg,13.00,F-SMP001\r\n"
        . "OLD-1,Old item back,each,0,\r\n"
        . "RL-002,Salt,kg,7\r\n";
    [$code, $out] = import_csv($app, $csv);
    assert_same(0, $code, 'exit code; output: ' . $out);
    assert_true(str_contains($out, '2 created, 2 updated'), 'summary: ' . $out);

    $cat = import_catalogue($app);
    assert_same('Flour, cake (25 kg)', $cat['RL-001']['name'] ?? null, 'quoted name');
    assert_money(12.50, $cat['RL-001']['cost_price'] ?? null, 'RL-001 cost');
    assert_same('F-RL001', $cat['RL-001']['fincon_item_no'] ?? null, 'RL-001 item no');
    assert_same(false, $cat['RL-001']['is_sample'] ?? null, 'imported row is not a sample');
    assert_money(13.00, $cat['SMP001']['cost_price'] ?? null, 'SMP001 updated cost');
    assert_same(true, $cat['OLD-1']['active'] ?? null, 'listed ingredient is active again');
    assert_money(0.0, $cat['OLD-1']['cost_price'] ?? null, 'zero cost allowed');
    assert_true(array_key_exists('fincon_item_no', $cat['RL-002'] ?? []) && $cat['RL-002']['fincon_item_no'] === null, 'missing item no = unmapped');
    assert_money(7.00, $cat['RL-002']['cost_price'] ?? null, 'whole-rand cost');
});

test('M1-10: any bad row -> every problem reported with its line number, exit 1, nothing imported', function (): void {
    $app = TestApp::start();
    $before = import_catalogue($app);
    $csv = "sku,name,unit,cost_price,fincon_item_no\n"
        . "RL-001,Good row,kg,1.00,\n"
        . "rl-002,Lower case sku,kg,1.00,\n"
        . "RL-003,Bad cost,kg,1.234,\n"
        . "RL-004,Negative,kg,-1,\n"
        . "RL-001,Duplicate,kg,2.00,\n"
        . "RL-005,,kg,1.00,\n"
        . "RL-006,Too few,kg\n"
        . "RL-007,Long item no,kg,1.00," . str_repeat('9', 26) . "\n"
        . "SMP001,SAMPLE Cake flour,kg,99.00,F-SMP001\n";
    [$code, $out] = import_csv($app, $csv);
    assert_same(1, $code, 'exit code');
    foreach (['line 3:', 'line 4:', 'line 5:', 'line 6: sku RL-001 already on line 2', 'line 7:', 'line 8:', 'line 9:', 'Nothing imported.'] as $needle) {
        assert_true(str_contains($out, $needle), 'output contains "' . $needle . '": ' . $out);
    }
    assert_true(!str_contains($out, 'line 2:') && !str_contains($out, 'line 10:'), 'good rows are not reported: ' . $out);
    assert_same($before, import_catalogue($app), 'catalogue unchanged');
});

test('M1-10: usage error without a file; empty file rejected', function (): void {
    $app = TestApp::start();
    [$code] = hub_php(['bin/import_ingredients.php'], $app->env);
    assert_same(2, $code, 'usage exit code');
    [$code, $out] = import_csv($app, "sku,name,unit,cost_price,fincon_item_no\n");
    assert_same(1, $code, 'header only: exit 1; ' . $out);
    [$code] = hub_php(['bin/import_ingredients.php', $app->dir . '/missing.csv'], $app->env);
    assert_same(1, $code, 'missing file exit code');
});

run_tests();
