<?php
declare(strict_types=1);

// Lane A tests 6 and 7 (build spec 2026-11-16 s6): no money keys in the queue; the route writes nothing.

require __DIR__ . '/../../lib/harness.php';
require __DIR__ . '/wh_helpers.php';

test('WH-6: no key in the queue JSON matches /cost|price|total/i', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');
    $a = wh_order($hub, [['sku' => 'SMP001', 'qty' => 10], ['sku' => 'SMP004', 'qty' => 2.5]], hub_date_plus(1), 'Note');
    wh_order($app->client('hub2.user'), [['sku' => 'SMP005', 'qty' => 1]]);
    $c = wh_order($hub, [['sku' => 'SMP002', 'qty' => 1]]);
    wh_move($hq, $a, 'in_process');
    wh_move($hq, $c, 'out_for_delivery');

    $r = wh_queue($hq);
    assert_same(3, count(hub_list($r, 'orders', 'orders')), 'three open orders to scan');
    assert_true(count(hub_list($r, 'picklist', 'picklist')) > 0, 'a pick list to scan');
    assert_same([], hub_keys_matching($r->json, '/cost|price|total/i'), 'money-like keys in the queue');
    foreach (['unit_cost_cents', 'line_total_cents', 'total_cents', 'unit_price', 'line_total', 'cost_price', 'price_basis'] as $needle) {
        assert_true(!str_contains($r->body, $needle), 'body does not contain ' . $needle);
    }
});

test('WH-7: the queue performs no writes (row counts unchanged) and the module source holds no write SQL', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');
    $a = wh_order($hub, [['sku' => 'SMP001', 'qty' => 1]]);
    $b = wh_order($hub, [['sku' => 'SMP004', 'qty' => 2]]);
    wh_order($app->client('hub2.user'), [['sku' => 'SMP002', 'qty' => 3]]);
    wh_move($hq, $a, 'delivered'); // so inventory_movements has rows too
    wh_move($hq, $b, 'in_process');

    $tables = ['orders', 'order_lines', 'inventory_movements', 'order_notifications', 'auth_tokens'];
    $before = [];
    foreach ($tables as $t) {
        $before[$t] = wh_count($app, $t);
    }
    assert_true($before['inventory_movements'] > 0, 'inventory_movements has rows before the call');
    wh_queue($hq);
    wh_queue($hq);
    assert_error($hub->get('/api/warehouse/queue'), 403, 'FORBIDDEN', 'hub GET (also must not write)');
    foreach ($tables as $t) {
        assert_same($before[$t], wh_count($app, $t), 'row count of ' . $t . ' after GET /api/warehouse/queue');
    }

    $dir = HUB_TEST_ROOT . '/src/Modules/Warehouse';
    $files = glob($dir . '/*.php') ?: [];
    assert_true(count($files) >= 3, 'routes.php, QueueController.php, QueueService.php exist: ' . hub_export(array_map('basename', $files)));
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        $hit = preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE|CREATE)\b/i', $src, $m);
        assert_true($hit !== 1, basename($file) . ' contains the write SQL keyword ' . hub_export($m[0] ?? null));
        assert_true(!str_contains($src, 'Db::exec') && !str_contains($src, 'Db::insert') && !str_contains($src, 'Db::transaction') && !str_contains($src, '->exec('),
            basename($file) . ' calls no Db write helper');
    }
    $routes = (string) file_get_contents($dir . '/routes.php');
    assert_same(1, preg_match_all("/\\[\\s*'(GET|POST|PUT|PATCH|DELETE)'/", $routes), 'routes.php declares exactly one route');
    assert_true(str_contains($routes, "['GET', '/api/warehouse/queue', [QueueController::class, 'show']]"), 'routes.php declares GET /api/warehouse/queue -> QueueController::show');
    assert_true(str_contains((string) file_get_contents($dir . '/QueueController.php'), "Auth::requireRole(\$req, 'hq')"), 'controller calls Auth::requireRole($req, \'hq\')');
});

run_tests();
