<?php
declare(strict_types=1);

// Shared helpers for the black-box acceptance tests (SPEC s12). HTTP API contract + fixtures only:
// ids are always looked up through the API, never assumed.

require_once __DIR__ . '/../lib/harness.php';

const MUFFIN = 'SMP-F01';
const SCONE = 'SMP-F02';
const CUSTARD = 'SMP-F03';
const SAMPLE_SKUS = ['SMP001', 'SMP002', 'SMP003', 'SMP004', 'SMP005', 'SMP006'];
const SAMPLE_RECIPE_NAMES = ['SAMPLE Choc muffin', 'SAMPLE Plain scone', 'SAMPLE Vanilla custard 1 l'];

/** SPEC s7.1 statuses for the s10.3 quantities (mock and fake FinCon). */
const EXPECTED_STOCK = [
    'SMP001' => 'in_stock',
    'SMP002' => 'low_stock',
    'SMP003' => 'out_of_stock',
    'SMP004' => 'in_stock',
    'SMP005' => 'in_stock',
    'SMP006' => 'in_stock',
];

const READ_ONLY_FINCON_FUNCTIONS = ['Login', 'GetStockQuantitiesForOpt2', 'GetStockQuantities', 'Logout'];

/** Recipe id for a finished_sku via GET /api/recipes. */
function acc_recipe_id(ApiClient $c, string $finishedSku): int
{
    $r = $c->get('/api/recipes');
    assert_success($r, 200, 'GET /api/recipes as ' . $c->username);
    $recipe = hub_find(hub_list($r, 'recipes', 'GET /api/recipes'), 'finished_sku', $finishedSku);
    assert_true($recipe !== null && is_int($recipe['id'] ?? null), 'recipe ' . $finishedSku . ' is listed with an integer id; ' . $r->describe());
    return $recipe['id'];
}

/** POST /api/batches (201 asserted). Returns [BATCH, warnings]. */
function acc_record_batch(ApiClient $c, string $finishedSku, int $quantity): array
{
    $r = $c->post('/api/batches', ['recipe_id' => acc_recipe_id($c, $finishedSku), 'quantity' => $quantity]);
    assert_success($r, 201, 'POST /api/batches ' . $finishedSku . ' x' . $quantity . ' as ' . $c->username);
    $batch = hub_object($r, 'batch', 'POST /api/batches');
    assert_true(is_int($batch['id'] ?? null), 'batch.id is an integer; ' . $r->describe());
    return [$batch, $r->json['warnings'] ?? null];
}

/** POST /api/orders (201 asserted). Returns ORDER. */
function acc_place_order(ApiClient $c, array $lines, array $extra = []): array
{
    $r = $c->post('/api/orders', ['lines' => $lines] + $extra);
    assert_success($r, 201, 'POST /api/orders as ' . $c->username);
    $order = hub_object($r, 'order', 'POST /api/orders');
    assert_true(is_int($order['id'] ?? null), 'order.id is an integer; ' . $r->describe());
    return $order;
}

/** GET /api/inventory[$query] as [sku => on_hand]. */
function acc_inventory(ApiClient $c, string $query = ''): array
{
    $r = $c->get('/api/inventory' . $query);
    assert_success($r, 200, 'GET /api/inventory' . $query . ' as ' . $c->username);
    $out = [];
    foreach (hub_list($r, 'items', 'GET /api/inventory') as $item) {
        $out[$item['sku'] ?? '?'] = $item['on_hand'] ?? null;
    }
    foreach (SAMPLE_SKUS as $sku) {
        assert_true(array_key_exists($sku, $out), 'inventory lists ' . $sku . '; ' . $r->describe());
    }
    return $out;
}

/** The element of $list with [$key] === $value (asserted). */
function acc_pick(array $list, string $key, mixed $value, string $what): array
{
    $item = hub_find($list, $key, $value);
    assert_true($item !== null, $what . ': no element with ' . $key . ' = ' . hub_export($value) . ' in ' . hub_export($list));
    return $item;
}

/** A whole JSON number (36 or 36.0) as int (asserted). */
function acc_int(mixed $value, string $what): int
{
    assert_true(is_int($value) || (is_float($value) && floor($value) === $value), $what . ': expected a whole JSON number, got ' . hub_export($value));
    return (int) $value;
}

/** Sum of money values as integer cents (avoids float drift). */
function acc_cents(array $values): int
{
    $sum = 0;
    foreach ($values as $v) {
        assert_true(is_int($v) || is_float($v), 'money value is a JSON number, got ' . hub_export($v));
        $sum += (int) round($v * 100);
    }
    return $sum;
}

/** Asserts a BATCH's lines against [sku => [qty_used, unit_cost, line_cost]]. */
function acc_assert_batch_lines(array $batch, array $expected, string $what): void
{
    $lines = $batch['lines'] ?? null;
    assert_true(is_array($lines) && array_is_list($lines), $what . ': batch.lines is a list');
    assert_same(count($expected), count($lines), $what . ': number of batch lines');
    foreach ($expected as $sku => [$qtyUsed, $unitCost, $lineCost]) {
        $line = acc_pick($lines, 'sku', $sku, $what . ' line');
        assert_qty($qtyUsed, $line['qty_used'] ?? null, $what . ' ' . $sku . ' qty_used');
        assert_money($unitCost, $line['unit_cost'] ?? null, $what . ' ' . $sku . ' unit_cost');
        assert_money($lineCost, $line['line_cost'] ?? null, $what . ' ' . $sku . ' line_cost');
    }
}
