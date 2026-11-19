<?php
declare(strict_types=1);

// Lane A tests 4 and 5 (build spec 2026-11-16 s6): exact pick list sums; counts always has three keys.

require __DIR__ . '/../../lib/harness.php';
require __DIR__ . '/wh_helpers.php';

test('WH-4: 10 + 2.5 of one SKU sums to exactly 12.5; out_for_delivery is in orders but not in the pick list', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    $hq = $app->client('hq.admin');
    $a = wh_order($hub, [['sku' => 'SMP004', 'qty' => 1], ['sku' => 'SMP001', 'qty' => 10]]);
    $b = wh_order($hub2, [['sku' => 'SMP001', 'qty' => 2.5]]);
    $c = wh_order($hub, [['sku' => 'SMP002', 'qty' => 3], ['sku' => 'SMP001', 'qty' => 7]]);
    wh_move($hq, $b, 'in_process');
    wh_move($hq, $c, 'out_for_delivery');

    $r = wh_queue($hq);
    $ids = wh_ids($r);
    assert_true(in_array($c, $ids, true), 'out_for_delivery order is still in orders');
    $pick = hub_list($r, 'picklist', 'picklist');
    assert_same(['SMP001', 'SMP004'], hub_column($pick, 'sku'), 'pick list skus, sorted, SMP002 (out_for_delivery only) excluded');

    $flour = hub_find($pick, 'sku', 'SMP001');
    assert_same(12.5, $flour['qty'] ?? null, 'SMP001 pick qty is exactly 12.5 (pending 10 + in_process 2.5, not the out_for_delivery 7)');
    assert_same([$a, $b], $flour['order_ids'] ?? null, 'SMP001 order_ids');
    assert_same('SAMPLE Cake flour', $flour['name'] ?? null, 'SMP001 name');
    assert_same('kg', $flour['unit'] ?? null, 'SMP001 unit');

    $butter = hub_find($pick, 'sku', 'SMP004');
    assert_qty(1, $butter['qty'] ?? null, 'SMP004 pick qty');
    assert_same([$a], $butter['order_ids'] ?? null, 'SMP004 order_ids');
    assert_same(['pending' => 1, 'in_process' => 1, 'out_for_delivery' => 1], hub_object($r, 'counts', 'counts'), 'counts');
});

test('WH-4: thousandths sum exactly (0.001 x 3 + 0.1 = 0.103)', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    wh_order($hub, [['sku' => 'SMP003', 'qty' => 0.001]]);
    wh_order($hub2, [['sku' => 'SMP003', 'qty' => 0.001]]);
    wh_order($hub, [['sku' => 'SMP003', 'qty' => 0.001]]);
    wh_order($hub2, [['sku' => 'SMP003', 'qty' => 0.1]]);
    $pick = hub_list(wh_queue($app->client('hq.admin')), 'picklist', 'picklist');
    assert_same(1, count($pick), 'one pick row');
    assert_same(0.103, $pick[0]['qty'] ?? null, 'exact thousandths');
    assert_same(4, count($pick[0]['order_ids'] ?? []), 'four orders');
});

test('WH-5: counts has three keys at zero when nothing is open; orders and picklist empty', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');
    $r = wh_queue($hq);
    assert_same(['pending' => 0, 'in_process' => 0, 'out_for_delivery' => 0], hub_object($r, 'counts', 'counts'), 'counts on an empty database');
    assert_same([], hub_list($r, 'orders', 'orders'), 'no orders');
    assert_same([], hub_list($r, 'picklist', 'picklist'), 'no pick list');
    assert_true(str_contains($r->body, '"counts":{"pending":0,"in_process":0,"out_for_delivery":0}'), 'counts is a JSON object, never a list: ' . hub_snippet($r->body));

    $done = wh_order($hub, [['sku' => 'SMP001', 'qty' => 1]]);
    $gone = wh_order($hub, [['sku' => 'SMP001', 'qty' => 1]]);
    wh_move($hq, $done, 'delivered');
    wh_move($hq, $gone, 'cancelled');
    $r = wh_queue($hq);
    assert_same(['pending' => 0, 'in_process' => 0, 'out_for_delivery' => 0], hub_object($r, 'counts', 'counts'), 'counts with only closed orders');
    assert_same([], hub_list($r, 'orders', 'orders'), 'closed orders are not listed');
});

run_tests();
