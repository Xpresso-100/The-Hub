<?php
declare(strict_types=1);

// M1-08 (SPEC s7.7): HQ fulfilment. Forward-only transitions, cancel from pending/in_process,
// delivered credits the hub's own inventory ledger exactly once.

require __DIR__ . '/../../lib/harness.php';
require HUB_TEST_ROOT . '/src/bootstrap.php';

use Hub\Modules\Ordering\OrderService;

function ledger(TestApp $app, int $orderId): array
{
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $stmt = $pdo->prepare('SELECT location_id, sku, qty_delta_milli, reason, ref_id FROM inventory_movements WHERE ref_id = ? ORDER BY id');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

test('M1-08: transition table', function (): void {
    $allowed = [
        'pending' => ['in_process', 'out_for_delivery', 'delivered', 'cancelled'],
        'in_process' => ['out_for_delivery', 'delivered', 'cancelled'],
        'out_for_delivery' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];
    foreach ($allowed as $from => $targets) {
        foreach (OrderService::STATUSES as $to) {
            assert_same(in_array($to, $targets, true), OrderService::canMove($from, $to), $from . ' -> ' . $to);
        }
    }
});

test('M1-08: hq moves an order to delivered; hub inventory credited once; further changes 409', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');
    $o = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 10], ['sku' => 'SMP004', 'qty' => 2.5]]])->json['order'];

    assert_error($hub->patch('/api/orders/' . $o['id'] . '/status', ['status' => 'delivered']), 403, 'FORBIDDEN', 'hub PATCH');
    assert_error($app->client('franchise.user')->patch('/api/orders/' . $o['id'] . '/status', ['status' => 'delivered']), 403, 'FORBIDDEN', 'franchise PATCH');

    $r = $hq->patch('/api/orders/' . $o['id'] . '/status', ['status' => 'out_for_delivery']);
    assert_success($r, 200, 'skip forward to out_for_delivery');
    assert_same('out_for_delivery', $r->json['order']['status'] ?? null, 'status');
    assert_true(array_key_exists('delivered_at', $r->json['order'] ?? []) && $r->json['order']['delivered_at'] === null, 'not delivered yet');
    assert_same([], ledger($app, $o['id']), 'no ledger moves before delivery');

    assert_error($hq->patch('/api/orders/' . $o['id'] . '/status', ['status' => 'in_process']), 409, 'CONFLICT', 'backwards');
    assert_error($hq->patch('/api/orders/' . $o['id'] . '/status', ['status' => 'out_for_delivery']), 409, 'CONFLICT', 'same status');
    assert_error($hq->patch('/api/orders/' . $o['id'] . '/status', ['status' => 'cancelled']), 409, 'CONFLICT', 'cancel from out_for_delivery');

    $r = $hq->patch('/api/orders/' . $o['id'] . '/status', ['status' => 'delivered']);
    assert_success($r, 200, 'delivered');
    assert_true(is_string($r->json['order']['delivered_at'] ?? null), 'delivered_at set');
    $hubLoc = $hub->locationId();
    assert_same([
        ['location_id' => $hubLoc, 'sku' => 'SMP001', 'qty_delta_milli' => 10000, 'reason' => 'order_delivered', 'ref_id' => $o['id']],
        ['location_id' => $hubLoc, 'sku' => 'SMP004', 'qty_delta_milli' => 2500, 'reason' => 'order_delivered', 'ref_id' => $o['id']],
    ], ledger($app, $o['id']), 'one +qty movement per line for the order location');

    foreach (['delivered', 'cancelled', 'pending'] as $again) {
        assert_error($hq->patch('/api/orders/' . $o['id'] . '/status', ['status' => $again]), 409, 'CONFLICT', 'from delivered to ' . $again);
    }
    assert_same(2, count(ledger($app, $o['id'])), 'credited exactly once');
});

test('M1-08: cancel from pending and in_process; nothing after cancelled; 404 / 422 / 400 precedence', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');
    $a = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]]])->json['order']['id'];
    $b = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]]])->json['order']['id'];

    assert_success($hq->patch('/api/orders/' . $a . '/status', ['status' => 'cancelled']), 200, 'cancel pending');
    assert_success($hq->patch('/api/orders/' . $b . '/status', ['status' => 'in_process']), 200, 'in_process');
    assert_success($hq->patch('/api/orders/' . $b . '/status', ['status' => 'cancelled']), 200, 'cancel in_process');
    assert_error($hq->patch('/api/orders/' . $a . '/status', ['status' => 'in_process']), 409, 'CONFLICT', 'nothing after cancelled');
    assert_same([], ledger($app, $a), 'cancelled order credits nothing');

    assert_error($hq->patch('/api/orders/999999/status', ['status' => 'shipped']), 404, 'NOT_FOUND', 'unknown id before validation');
    $r = $hq->patch('/api/orders/' . $a . '/status', ['status' => 'shipped']);
    assert_error($r, 422, 'VALIDATION_ERROR', 'unknown status');
    assert_true(isset($r->json['errors']['status']), 'status error field');
    assert_error($hq->patch('/api/orders/' . $a . '/status', []), 422, 'VALIDATION_ERROR', 'missing status');
    assert_error($app->request('PATCH', '/api/orders/' . $a . '/status', '[1,2]', $hq->token), 400, 'BAD_JSON', 'non-object body');
});

run_tests();
