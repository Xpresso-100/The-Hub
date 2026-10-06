<?php
declare(strict_types=1);

// A1 (SPEC s12): Hub user cannot read other locations' orders.
// hub2.user places an order. hub.user: GET /api/orders/{that id} -> 404; GET /api/orders and
// GET /api/orders?location_id=<hub2 location> do not contain it; PATCH .../status -> 403.
// Same 404 for hub2's batch via GET /api/batches/{id}. hq.admin sees both orders.

require __DIR__ . '/helpers.php';

test("A1: hub.user cannot read hub2's order by id, in its list, or with a location_id filter; hq.admin sees both", function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    $hq = $app->client('hq.admin');
    $hubLocation = $hub->locationId();
    $hub2Location = $hub2->locationId();

    $theirs = acc_place_order($hub2, [['sku' => 'SMP002', 'qty' => 3]]);
    $mine = acc_place_order($hub, [['sku' => 'SMP001', 'qty' => 1]]);
    assert_same($hub2Location, $theirs['location_id'] ?? null, "hub2's order belongs to hub2's location");

    assert_error($hub->get('/api/orders/' . $theirs['id']), 404, 'NOT_FOUND', "hub.user GET /api/orders/{hub2's order}");
    assert_success($hub->get('/api/orders/' . $mine['id']), 200, 'hub.user GET own order');

    foreach (['/api/orders', '/api/orders?location_id=' . $hub2Location] as $path) {
        $r = $hub->get($path);
        assert_success($r, 200, 'hub.user GET ' . $path);
        $orders = hub_list($r, 'orders', 'hub.user GET ' . $path);
        $ids = hub_column($orders, 'id');
        assert_true(!in_array($theirs['id'], $ids, true), "hub.user GET $path must not contain hub2's order; got ids " . hub_export($ids));
        assert_true(in_array($mine['id'], $ids, true), "hub.user GET $path contains its own order; got ids " . hub_export($ids));
        foreach ($orders as $order) {
            assert_same($hubLocation, $order['location_id'] ?? null, "every order hub.user sees via $path is its own location's");
        }
    }

    assert_error(
        $hub->patch('/api/orders/' . $theirs['id'] . '/status', ['status' => 'cancelled']),
        403,
        'FORBIDDEN',
        "hub.user PATCH /api/orders/{hub2's order}/status"
    );
    $r = $hq->get('/api/orders/' . $theirs['id']);
    assert_success($r, 200, "hq.admin GET hub2's order");
    assert_same('pending', $r->json['order']['status'] ?? null, "hub2's order status is untouched");

    $r = $hq->get('/api/orders');
    assert_success($r, 200, 'hq.admin GET /api/orders');
    $ids = hub_column(hub_list($r, 'orders', 'hq.admin GET /api/orders'), 'id');
    assert_true(in_array($theirs['id'], $ids, true) && in_array($mine['id'], $ids, true), 'hq.admin sees both orders; got ids ' . hub_export($ids));
});

test("A1: hub.user cannot read hub2's batch (404), nor hub2's batches or inventory through a location_id parameter", function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hub2 = $app->client('hub2.user');
    $hq = $app->client('hq.admin');
    $hubLocation = $hub->locationId();
    $hub2Location = $hub2->locationId();

    [$theirs] = acc_record_batch($hub2, CUSTARD, 5);
    [$mine] = acc_record_batch($hub, MUFFIN, 12);

    assert_error($hub->get('/api/batches/' . $theirs['id']), 404, 'NOT_FOUND', "hub.user GET /api/batches/{hub2's batch}");
    assert_success($hub->get('/api/batches/' . $mine['id']), 200, 'hub.user GET own batch');
    assert_success($hq->get('/api/batches/' . $theirs['id']), 200, "hq.admin GET hub2's batch");

    $r = $hub->get('/api/admin/batches?location_id=' . $hub2Location);
    assert_success($r, 200, 'hub.user GET /api/admin/batches?location_id=<hub2>');
    $ids = hub_column(hub_list($r, 'batches', 'GET /api/admin/batches'), 'id');
    assert_true(!in_array($theirs['id'], $ids, true), "hub2's batch must not be listed for hub.user; got " . hub_export($ids));
    assert_true(in_array($mine['id'], $ids, true), 'own batch is listed (location_id param ignored for hub); got ' . hub_export($ids));

    $r = $hub->get('/api/inventory?location_id=' . $hub2Location);
    assert_success($r, 200, 'hub.user GET /api/inventory?location_id=<hub2>');
    assert_same($hubLocation, $r->json['location_id'] ?? null, 'a hub user always gets its own inventory');
    $custardMilk = acc_pick(hub_list($r, 'items', 'inventory'), 'sku', 'SMP006', 'inventory');
    assert_qty(0, $custardMilk['on_hand'] ?? null, "hub.user's SMP006 is untouched by hub2's custard batch");
});

run_tests();
