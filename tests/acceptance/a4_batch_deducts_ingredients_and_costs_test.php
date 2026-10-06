<?php
declare(strict_types=1);

// A4 (SPEC s12): A production batch deducts the right ingredient quantities; cost per item matches a
// hand-calculated sample. Read GET /api/inventory, POST /api/batches muffin x24, read again: deltas exactly
// -1.440, -0.720, -0.480, -0.360, -0.240 for SMP001..SMP005, SMP006 unchanged; total_cost 134.16;
// cost_per_item 5.59; line costs as s5.3. Plus the other s5.3 hand-calculated batches.

require __DIR__ . '/helpers.php';

/** s5.3: sku => [qty_used, unit_cost, line_cost] for 24 x SAMPLE Choc muffin. */
const MUFFIN_24 = [
    'SMP001' => [1.440, 12.50, 18.00],
    'SMP002' => [0.720, 18.00, 12.96],
    'SMP003' => [0.480, 45.00, 21.60],
    'SMP004' => [0.360, 120.00, 43.20],
    'SMP005' => [0.240, 160.00, 38.40],
];

function a4_assert_deltas(array $before, array $after, array $lines, string $what): void
{
    foreach (SAMPLE_SKUS as $sku) {
        $expected = isset($lines[$sku]) ? -$lines[$sku][0] : 0;
        assert_true(is_int($before[$sku]) || is_float($before[$sku]), $what . ': on_hand of ' . $sku . ' is a number');
        assert_qty($expected, $after[$sku] - $before[$sku], $what . ': inventory change of ' . $sku);
    }
}

test('A4: batch of 24 SAMPLE Choc muffin deducts exactly the recipe quantities and costs 134.16 total / 5.59 per item', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');
    $hubLocation = $hub->locationId();
    $hub2Location = $app->client('hub2.user')->locationId();

    $before = acc_inventory($hub);
    foreach (SAMPLE_SKUS as $sku) {
        assert_qty(0, $before[$sku], 'fixture inventory starts empty for ' . $sku);
    }
    $recipeId = acc_recipe_id($hub, MUFFIN);
    $r = $hub->get('/api/recipes/' . $recipeId);
    assert_success($r, 200, 'GET /api/recipes/{muffin}');
    assert_money(5.59, $r->json['recipe']['cost_per_unit'] ?? null, 'muffin recipe cost_per_unit (s5.2)');

    assert_error($hq->post('/api/batches', ['recipe_id' => $recipeId, 'quantity' => 24]), 403, 'FORBIDDEN', 'only hub users record batches');

    [$batch, $warnings] = acc_record_batch($hub, MUFFIN, 24);
    assert_same($recipeId, $batch['recipe_id'] ?? null, 'batch.recipe_id');
    assert_same(MUFFIN, $batch['finished_sku'] ?? null, 'batch.finished_sku');
    assert_same(24, acc_int($batch['quantity'] ?? null, 'batch.quantity'), 'batch.quantity');
    assert_same($hubLocation, $batch['location_id'] ?? null, 'batch.location_id');
    assert_same(hub_today(), $batch['produced_on'] ?? null, 'batch.produced_on defaults to today');
    assert_money(134.16, $batch['total_cost'] ?? null, 'batch.total_cost');
    assert_money(5.59, $batch['cost_per_item'] ?? null, 'batch.cost_per_item');
    acc_assert_batch_lines($batch, MUFFIN_24, 'muffin x24');

    $after = acc_inventory($hub);
    a4_assert_deltas($before, $after, MUFFIN_24, 'muffin x24');

    // Negative on-hand is allowed and every ingredient below 0 is warned about (SPEC s8.3).
    assert_true(is_array($warnings) && array_is_list($warnings), 'warnings is a list');
    $warned = hub_column($warnings, 'sku');
    sort($warned);
    assert_same(array_keys(MUFFIN_24), $warned, 'warned skus (all five go below 0 from an empty ledger)');
    assert_qty(-1.44, acc_pick($warnings, 'sku', 'SMP001', 'warnings')['on_hand_after'] ?? null, 'SMP001 on_hand_after');

    $r = $hub->get('/api/batches/' . $batch['id']);
    assert_success($r, 200, 'GET /api/batches/{id}');
    $stored = hub_object($r, 'batch', 'GET /api/batches/{id}');
    assert_money(134.16, $stored['total_cost'] ?? null, 'stored batch total_cost');
    assert_money(5.59, $stored['cost_per_item'] ?? null, 'stored batch cost_per_item');
    acc_assert_batch_lines($stored, MUFFIN_24, 'stored muffin x24');

    // Only the hub's own ledger moved.
    $hqView = acc_inventory($hq, '?location_id=' . $hubLocation);
    assert_qty(-1.44, $hqView['SMP001'], 'hq.admin sees the hub ledger SMP001');
    foreach (acc_inventory($hq, '?location_id=' . $hub2Location) as $sku => $onHand) {
        assert_qty(0, $onHand, 'Hub Two inventory untouched for ' . $sku);
    }
});

test('A4: a positive opening balance is reduced exactly, and only ingredients that go below 0 are warned', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');

    $r = $hub->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 5, 'reason' => 'opening balance']);
    assert_success($r, 201, 'POST /api/inventory/adjustments +5 SMP001');
    assert_qty(5, $r->json['on_hand'] ?? null, 'on_hand after the adjustment');

    $before = acc_inventory($hub);
    assert_qty(5, $before['SMP001'], 'SMP001 opening balance');
    [, $warnings] = acc_record_batch($hub, MUFFIN, 24);
    $after = acc_inventory($hub);
    a4_assert_deltas($before, $after, MUFFIN_24, 'muffin x24 from an opening balance');
    assert_qty(3.56, $after['SMP001'], 'SMP001 on hand after the batch');

    assert_true(is_array($warnings), 'warnings is a list');
    $warned = hub_column($warnings, 'sku');
    sort($warned);
    assert_same(['SMP002', 'SMP003', 'SMP004', 'SMP005'], $warned, 'SMP001 stays positive, so it is not warned');
});

test('A4: the other s5.3 hand-calculated batches: muffin x12 = 67.08, scone x10 = 43.80, custard x5 = 113.30', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');

    $r = $hub->get('/api/recipes');
    assert_success($r, 200, 'GET /api/recipes');
    $recipes = hub_list($r, 'recipes', 'GET /api/recipes');
    foreach ([MUFFIN => 5.59, SCONE => 4.38, CUSTARD => 22.66] as $sku => $cost) {
        assert_money($cost, acc_pick($recipes, 'finished_sku', $sku, 'recipes')['cost_per_unit'] ?? null, $sku . ' cost_per_unit (s5.2)');
    }

    $cases = [
        [MUFFIN, 12, 67.08, 5.59, [
            'SMP001' => [0.720, 12.50, 9.00], 'SMP002' => [0.360, 18.00, 6.48], 'SMP003' => [0.240, 45.00, 10.80],
            'SMP004' => [0.180, 120.00, 21.60], 'SMP005' => [0.120, 160.00, 19.20],
        ]],
        [SCONE, 10, 43.80, 4.38, [
            'SMP001' => [0.800, 12.50, 10.00], 'SMP004' => [0.200, 120.00, 24.00], 'SMP006' => [0.400, 20.00, 8.00],
            'SMP002' => [0.100, 18.00, 1.80],
        ]],
        [CUSTARD, 5, 113.30, 22.66, [
            'SMP006' => [4.000, 20.00, 80.00], 'SMP003' => [0.500, 45.00, 22.50], 'SMP002' => [0.600, 18.00, 10.80],
        ]],
    ];
    foreach ($cases as [$sku, $quantity, $total, $perItem, $lines]) {
        $what = $sku . ' x' . $quantity;
        $before = acc_inventory($hub);
        [$batch] = acc_record_batch($hub, $sku, $quantity);
        assert_money($total, $batch['total_cost'] ?? null, $what . ' total_cost');
        assert_money($perItem, $batch['cost_per_item'] ?? null, $what . ' cost_per_item');
        acc_assert_batch_lines($batch, $lines, $what);
        a4_assert_deltas($before, acc_inventory($hub), $lines, $what);
    }
});

run_tests();
