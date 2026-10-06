<?php
declare(strict_types=1);

// M3-reports (SPEC s9). M2 is built in another worktree, so batches are inserted straight into the
// test SQLite DB with the s5.3 snapshot values (SPEC s2.4: "inserts rows directly ... using the schema in s4").

require __DIR__ . '/../../lib/harness.php';

function rep_db(TestApp $app): PDO
{
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/** Inserts one batch snapshot row. Returns its id. */
function rep_batch(TestApp $app, string $username, int $recipeId, int $quantity, int $totalCents, int $perItemCents, ?string $producedOn = null, ?string $name = null): int
{
    $pdo = rep_db($app);
    $user = $pdo->prepare('SELECT id, location_id FROM users WHERE username = ?');
    $user->execute([$username]);
    $u = $user->fetch(PDO::FETCH_ASSOC);
    $recipe = $pdo->prepare('SELECT finished_sku, name, unit FROM recipes WHERE id = ?');
    $recipe->execute([$recipeId]);
    $r = $recipe->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('INSERT INTO batches (location_id, recipe_id, created_by_user_id, finished_sku, name_snapshot, unit_snapshot,'
        . ' quantity, produced_on, total_cost_cents, cost_per_item_cents, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NULL,?)')
        ->execute([$u['location_id'], $recipeId, $u['id'], $r['finished_sku'], $name ?? $r['name'], $r['unit'],
            $quantity, $producedOn ?? hub_today(), $totalCents, $perItemCents, hub_today() . ' 08:00:00']);
    return (int) $pdo->lastInsertId();
}

/** The s5.3 report batches: hub.user muffin x24, scone x10, muffin x12; hub2.user custard x5. */
function rep_setup(): array
{
    $app = TestApp::start();
    $ids = [
        'm24' => rep_batch($app, 'hub.user', 1, 24, 13416, 559),
        's10' => rep_batch($app, 'hub.user', 2, 10, 4380, 438),
        'm12' => rep_batch($app, 'hub.user', 1, 12, 6708, 559),
        'c5' => rep_batch($app, 'hub2.user', 3, 5, 11330, 2266),
    ];
    return [$app, $ids];
}

test('finished-goods: hub sees own batches only, s5.3 totals 46 / 245.04 / 3', function (): void {
    [$app, $ids] = rep_setup();
    $hub = $app->client('hub.user');
    $r = $hub->get('/api/reports/finished-goods?location_id=' . $app->client('hub2.user')->locationId());
    assert_success($r, 200, 'hub report');
    assert_same($hub->locationId(), $r->json['location_id'], 'location_id is the hub\'s own');
    assert_same(['SMP-F01', 'SMP-F02'], hub_column($r->json['items'], 'finished_sku'), 'items sorted by sku');
    $muffin = $r->json['items'][0];
    assert_same(36, $muffin['quantity'], 'muffin qty');
    assert_money(201.24, $muffin['total_cost'], 'muffin total');
    assert_money(5.59, $muffin['avg_cost_per_item'], 'muffin avg');
    assert_same(2, $muffin['batch_count'], 'muffin batch_count');
    assert_same('each', $muffin['unit'], 'muffin unit');
    assert_same(['quantity' => 46, 'total_cost' => 245.04, 'batch_count' => 3], $r->json['totals'], 'totals');
    assert_same([$ids['m24'], $ids['s10'], $ids['m12']], hub_column($r->json['batches'], 'id'), 'batches by produced_on then id');
    assert_same('The Hub', $r->json['batches'][0]['location_name'], 'location_name');
    assert_money(5.59, $r->json['batches'][0]['cost_per_item'], 'batch cost_per_item');
    assert_same([], hub_keys_matching($r->json, '/_cents$/'), 'no internal *_cents keys');
});

test('finished-goods: hq sees all (51 / 358.34 / 4) and can filter by location_id', function (): void {
    [$app] = rep_setup();
    $hq = $app->client('hq.admin');
    $r = $hq->get('/api/reports/finished-goods');
    assert_success($r, 200, 'hq report');
    assert_same(null, $r->json['location_id'], 'location_id null = all');
    assert_same(['quantity' => 51, 'total_cost' => 358.34, 'batch_count' => 4], $r->json['totals'], 'hq totals');
    assert_money(22.66, hub_find($r->json['items'], 'finished_sku', 'SMP-F03')['avg_cost_per_item'], 'custard avg');

    $hub2 = $app->client('hub2.user')->locationId();
    $r = $hq->get('/api/reports/finished-goods?location_id=' . $hub2);
    assert_success($r, 200, 'hq report for hub2');
    assert_same($hub2, $r->json['location_id'], 'echoes location_id');
    assert_same(['quantity' => 5, 'total_cost' => 113.3, 'batch_count' => 1], $r->json['totals'], 'hub2 totals');

    assert_error($hq->get('/api/reports/finished-goods?location_id=abc'), 422, 'VALIDATION_ERROR', 'non-integer location_id');
    assert_error($hq->get('/api/reports/finished-goods?location_id=9999'), 422, 'VALIDATION_ERROR', 'unknown location_id');
});

test('finished-goods: date range inclusive on produced_on; defaults first-of-month..today; bad dates 422', function (): void {
    $app = TestApp::start();
    $today = hub_today();
    $old = '2020-01-15';
    rep_batch($app, 'hub.user', 1, 24, 13416, 559, $old);
    rep_batch($app, 'hub.user', 2, 10, 4380, 438, $today);
    $hub = $app->client('hub.user');

    $r = $hub->get('/api/reports/finished-goods');
    assert_success($r, 200, 'default range');
    assert_same(substr($today, 0, 8) . '01', $r->json['from'], 'default from = first of month');
    assert_same($today, $r->json['to'], 'default to = today');
    assert_same(10, $r->json['totals']['quantity'], 'old batch outside default range');

    $r = $hub->get('/api/reports/finished-goods?from=' . $old . '&to=' . $old);
    assert_success($r, 200, 'single-day range');
    assert_same(24, $r->json['totals']['quantity'], 'from = to = produced_on is included');

    $r = $hub->get('/api/reports/finished-goods?from=2020-01-01&to=' . $today);
    assert_same(2, $r->json['totals']['batch_count'] ?? null, 'wide range has both');

    foreach (['?from=2026-13-01', '?to=2026-02-30', '?from=20260101', '?from=' . $today . '&to=2020-01-01', '?format=xml'] as $q) {
        assert_error($hub->get('/api/reports/finished-goods' . $q), 422, 'VALIDATION_ERROR', 'query ' . $q);
    }
});

test('finished-goods CSV: header, item rows, TOTAL row; text cells guarded against formulas', function (): void {
    [$app] = rep_setup();
    rep_batch($app, 'hub.user', 3, 1, 2266, 2266, null, '=HYPERLINK("x")');
    $r = $app->client('hub.user')->get('/api/reports/finished-goods?format=csv');
    assert_status(200, $r, 'csv');
    assert_true(str_starts_with((string) $r->header('Content-Type'), 'text/csv'), 'text/csv');
    assert_true(str_contains((string) $r->header('Content-Disposition'), 'attachment'), 'attachment');
    $rows = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), array_values(array_filter(preg_split('/\r?\n/', $r->body) ?: [], 'strlen')));
    assert_same(['finished_sku', 'name', 'unit', 'quantity', 'total_cost', 'avg_cost_per_item', 'batch_count'], $rows[0], 'header');
    assert_same(['SMP-F01', 'SAMPLE Choc muffin', 'each', '36', '201.24', '5.59', '2'], $rows[1], 'muffin row');
    assert_same(['SMP-F02', 'SAMPLE Plain scone', 'each', '10', '43.80', '4.38', '1'], $rows[2], 'scone row');
    assert_same(['SMP-F03', '\'=HYPERLINK("x")', 'tub', '1', '22.66', '22.66', '1'], $rows[3], 'formula-guarded row');
    assert_same(['TOTAL', '', '', '47', '267.70', '', '4'], $rows[4], 'TOTAL row');
});

test('roles: franchise 403 and anonymous 401 on every M3 route', function (): void {
    $app = TestApp::start();
    $fr = $app->client('franchise.user');
    foreach (['/api/reports/finished-goods', '/api/reports/finished-goods?format=csv', '/api/admin/recipes', '/api/admin/batches'] as $path) {
        assert_error($fr->get($path), 403, 'FORBIDDEN', 'franchise ' . $path);
        assert_error($app->anon()->get($path), 401, 'UNAUTHENTICATED', 'anon ' . $path);
    }
    // 403 comes before 422 (SPEC s3 status order)
    assert_error($fr->get('/api/reports/finished-goods?from=bad'), 403, 'FORBIDDEN', 'franchise bad date');
});

test('admin recipes: inactive included, line_count, current cost_per_unit, batch stats scoped for hub', function (): void {
    [$app] = rep_setup();
    rep_batch($app, 'hub.user', 1, 6, 3354, 559, '2026-01-02');
    rep_db($app)->exec("INSERT INTO recipes (finished_sku, name, unit, is_sample, active, created_at, updated_at) VALUES ('AAA-OLD', 'Retired <b>item</b>', 'each', 0, 0, '2026-10-06 00:00:00', '2026-10-06 00:00:00')");

    $r = $app->client('hub.user')->get('/api/admin/recipes');
    assert_success($r, 200, 'hub admin recipes');
    $list = $r->json['recipes'];
    assert_same(['AAA-OLD', 'SMP-F01', 'SMP-F02', 'SMP-F03'], hub_column($list, 'finished_sku'), 'sorted, inactive included');
    assert_same(false, $list[0]['active'], 'inactive flag');
    assert_same(0, $list[0]['line_count'], 'no lines');
    assert_same(null, $list[0]['last_produced_on'], 'never produced');
    $muffin = hub_find($list, 'finished_sku', 'SMP-F01');
    assert_same(5, $muffin['line_count'], 'muffin lines');
    assert_money(5.59, $muffin['cost_per_unit'], 'muffin cost_per_unit');
    assert_same(true, $muffin['is_sample'], 'is_sample');
    assert_same(3, $muffin['batch_count'], 'hub muffin batches');
    assert_same(hub_today(), $muffin['last_produced_on'], 'last_produced_on');
    $custard = hub_find($list, 'finished_sku', 'SMP-F03');
    assert_same(0, $custard['batch_count'], 'hub does not count hub2 custard');
    assert_money(22.66, $custard['cost_per_unit'], 'custard cost_per_unit');

    $r = $app->client('hq.admin')->get('/api/admin/recipes');
    assert_same(1, hub_find($r->json['recipes'], 'finished_sku', 'SMP-F03')['batch_count'] ?? null, 'hq counts all locations');
});

test('admin batches: newest first, created_by, filters, hub scoped', function (): void {
    [$app, $ids] = rep_setup();
    $older = rep_batch($app, 'hub.user', 2, 3, 1314, 438, '2026-01-02');
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');

    $r = $hub->get('/api/admin/batches?location_id=' . $app->client('hub2.user')->locationId());
    assert_success($r, 200, 'hub batches');
    assert_same([$ids['m12'], $ids['s10'], $ids['m24'], $older], hub_column($r->json['batches'], 'id'), 'own only, newest first');
    assert_same('hub.user', $r->json['batches'][0]['created_by'], 'created_by');

    $r = $hub->get('/api/admin/batches?recipe_id=2');
    assert_same([$ids['s10'], $older], hub_column($r->json['batches'], 'id'), 'recipe_id filter');
    $r = $hub->get('/api/admin/batches?from=2026-01-01&to=2026-01-31');
    assert_same([$older], hub_column($r->json['batches'], 'id'), 'date filter');

    $r = $hq->get('/api/admin/batches');
    assert_same(5, count($r->json['batches']), 'hq sees all');
    $r = $hq->get('/api/admin/batches?location_id=' . $app->client('hub2.user')->locationId());
    assert_same([$ids['c5']], hub_column($r->json['batches'], 'id'), 'hq location filter');
    assert_same('hub2.user', $r->json['batches'][0]['created_by'], 'hub2 created_by');

    foreach (['?recipe_id=x', '?recipe_id=0', '?from=bad', '?from=2026-02-01&to=2026-01-01'] as $q) {
        assert_error($hq->get('/api/admin/batches' . $q), 422, 'VALIDATION_ERROR', 'query ' . $q);
    }
});

run_tests();
