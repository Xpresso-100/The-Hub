<?php
declare(strict_types=1);

// Lane A tests 2 and 3 (build spec 2026-11-16 s6): only open orders; earliest need first, then oldest.

require __DIR__ . '/../../lib/harness.php';
require __DIR__ . '/wh_helpers.php';

test('WH-2: only pending, in_process and out_for_delivery appear; delivered and cancelled are absent', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');
    $line = [['sku' => 'SMP001', 'qty' => 1]];
    $pending = wh_order($hub, $line);
    $inProcess = wh_order($hub, $line);
    $outForDelivery = wh_order($hub, $line);
    $delivered = wh_order($hub, $line);
    $cancelled = wh_order($hub, $line);
    wh_move($hq, $inProcess, 'in_process');
    wh_move($hq, $outForDelivery, 'in_process');
    wh_move($hq, $outForDelivery, 'out_for_delivery');
    wh_move($hq, $delivered, 'in_process');
    wh_move($hq, $delivered, 'out_for_delivery');
    wh_move($hq, $delivered, 'delivered');
    wh_move($hq, $cancelled, 'cancelled');

    $r = wh_queue($hq);
    $orders = hub_list($r, 'orders', 'orders');
    $ids = wh_ids($r);
    $sorted = $ids;
    sort($sorted);
    assert_same([$pending, $inProcess, $outForDelivery], $sorted, 'open order ids');
    assert_true(!in_array($delivered, $ids, true), 'delivered order absent');
    assert_true(!in_array($cancelled, $ids, true), 'cancelled order absent');
    assert_same('pending', hub_find($orders, 'id', $pending)['status'] ?? null, 'pending status');
    assert_same('in_process', hub_find($orders, 'id', $inProcess)['status'] ?? null, 'in_process status');
    assert_same('out_for_delivery', hub_find($orders, 'id', $outForDelivery)['status'] ?? null, 'out_for_delivery status');
    assert_same(['pending' => 1, 'in_process' => 1, 'out_for_delivery' => 1], hub_object($r, 'counts', 'counts'), 'counts');
});

test('WH-2: each order carries location, creator, date, notes, email status and lines', function (): void {
    $app = TestApp::start();
    $id = wh_order($app->client('hub.user'), [['sku' => 'SMP001', 'qty' => 10], ['sku' => 'SMP004', 'qty' => 2.5]], hub_date_plus(1), 'Back door please');
    $other = wh_order($app->client('hub2.user'), [['sku' => 'SMP002', 'qty' => 1]]);
    $orders = hub_list(wh_queue($app->client('hq.admin')), 'orders', 'orders');

    $o = hub_find($orders, 'id', $id);
    assert_true($o !== null, 'order ' . $id . ' is in the queue');
    assert_same('The Hub', $o['location_name'] ?? null, 'location_name');
    assert_same('pending', $o['status'] ?? null, 'status');
    assert_same('hub.user', $o['created_by'] ?? null, 'created_by');
    assert_true(is_string($o['created_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $o['created_at']) === 1, 'created_at is ISO 8601: ' . hub_export($o['created_at'] ?? null));
    assert_same(hub_date_plus(1), $o['requested_delivery_date'] ?? null, 'requested_delivery_date');
    assert_same('Back door please', $o['notes'] ?? null, 'notes');
    assert_same('sent', $o['hq_notification'] ?? null, 'hq_notification (log mailer)');
    $lines = $o['lines'] ?? null;
    assert_true(is_array($lines) && count($lines) === 2, 'two lines: ' . hub_export($lines));
    assert_same('SMP001', $lines[0]['sku'] ?? null, 'line 0 sku');
    assert_same('SAMPLE Cake flour', $lines[0]['name'] ?? null, 'line 0 name');
    assert_same('kg', $lines[0]['unit'] ?? null, 'line 0 unit');
    assert_qty(10, $lines[0]['qty'] ?? null, 'line 0 qty');
    assert_same('SMP004', $lines[1]['sku'] ?? null, 'line 1 sku');
    assert_qty(2.5, $lines[1]['qty'] ?? null, 'line 1 qty');

    $o2 = hub_find($orders, 'id', $other);
    assert_true($o2 !== null, 'the other hub location is in the queue too');
    assert_same('Hub Two (TEST)', $o2['location_name'] ?? null, 'other location_name');
    assert_true(array_key_exists('requested_delivery_date', $o2) && $o2['requested_delivery_date'] === null, 'no delivery date is null');
    assert_true(array_key_exists('notes', $o2) && $o2['notes'] === null, 'no notes is null');
    assert_same(1, count($o2['lines'] ?? []), 'other order has its own single line');
});

test('WH-3: dated before undated; earlier date first; same date oldest first', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    $line = [['sku' => 'SMP001', 'qty' => 1]];
    $undatedOld = wh_order($hub, $line);
    $plus2 = wh_order($hub2, $line, hub_date_plus(2));
    $plus1Old = wh_order($hub, $line, hub_date_plus(1));
    $undatedNew = wh_order($hub2, $line);
    $plus1New = wh_order($hub2, $line, hub_date_plus(1));
    $today = wh_order($hub, $line, hub_today());

    $ids = wh_ids(wh_queue($app->client('hq.admin')));
    assert_same([$today, $plus1Old, $plus1New, $plus2, $undatedOld, $undatedNew], $ids, 'queue order');
});

run_tests();
