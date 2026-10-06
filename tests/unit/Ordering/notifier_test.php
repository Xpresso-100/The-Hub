<?php
declare(strict_types=1);

// M1-06 (SPEC s11): every hub order notifies HQ (logged transport in tests) and records an outbox row.

require __DIR__ . '/../../lib/harness.php';

function notif_rows(TestApp $app, int $orderId): array
{
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $stmt = $pdo->prepare('SELECT channel, recipient, status, error FROM order_notifications WHERE order_id = ? ORDER BY id');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

test('M1-06: one message to HQ_ORDERS_EMAIL with lines, costs, total and the internal-transfer line; outbox row sent', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $date = hub_date_plus(3);
    $r = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 10], ['sku' => 'SMP004', 'qty' => 2.5]], 'requested_delivery_date' => $date, 'notes' => "Line one\nline two"]);
    assert_success($r, 201, 'order');
    $o = $r->json['order'];
    assert_same(['channel' => 'email', 'status' => 'sent'], $o['hq_notification'] ?? null, 'hq_notification');

    $sent = hub_json_lines($app->mailLog());
    assert_same(1, count($sent), 'exactly one message');
    assert_same('hq-orders@example.test', $sent[0]['to'] ?? null, 'recipient');
    assert_same('Hub order #' . $o['id'] . ' - The Hub', $sent[0]['subject'] ?? null, 'subject');
    $body = (string) ($sent[0]['body'] ?? '');
    foreach (['SMP001', 'SAMPLE Cake flour', '10.000', 'kg', '12.50', '125.00', 'SMP004', '2.500', '120.00', '300.00', 'Total (R): 425.00', $date, 'Line one line two', 'Internal transfer at cost - not a VAT invoice.'] as $needle) {
        assert_true(str_contains($body, $needle), 'body contains "' . $needle . '": ' . $body);
    }
    $rows = notif_rows($app, $o['id']);
    assert_same(1, count($rows), 'one outbox row');
    assert_same(['channel' => 'email', 'recipient' => 'hq-orders@example.test', 'status' => 'sent', 'error' => null], $rows[0], 'outbox row');
});

test('M1-06: empty HQ_ORDERS_EMAIL or a failing transport -> outbox row failed, order still 201 and readable', function (): void {
    foreach ([['HQ_ORDERS_EMAIL' => ''], ['HQ_ORDERS_EMAIL' => "bad\r\nBcc: x@example.test"], ['MAIL_TRANSPORT' => 'nonsense']] as $env) {
        $app = TestApp::start($env);
        $hub = $app->client('hub.user');
        $r = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]]]);
        assert_success($r, 201, 'order with ' . hub_export($env));
        $o = $r->json['order'];
        assert_same('failed', $o['hq_notification']['status'] ?? null, 'failed notification for ' . hub_export($env));
        $rows = notif_rows($app, $o['id']);
        assert_same('failed', $rows[0]['status'] ?? null, 'outbox status');
        assert_true(is_string($rows[0]['error'] ?? null) && $rows[0]['error'] !== '', 'outbox error recorded');
        assert_success($hub->get('/api/orders/' . $o['id']), 200, 'order kept');
        assert_same([], hub_json_lines($app->mailLog()), 'nothing sent');
    }
});

run_tests();
