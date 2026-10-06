<?php
declare(strict_types=1);

// A3 (SPEC s12): Hub order lines use cost price.
// hub.user orders SMP001 qty 10 and SMP004 qty 2.5 while the body also sends unit_price 1.00 / price 1.00
// -> unit prices 12.50 / 120.00, line totals 125.00 / 300.00, total 425.00, price_basis "cost"; each unit_price
// equals cost_price from GET /api/ingredients. After hq.admin changes SMP001 cost to 13.00, a new order uses
// 13.00 and the first order still shows 12.50. Plus SPEC s11: the order reaches HQ by email.

require __DIR__ . '/helpers.php';

function a3_assert_line(array $order, string $sku, float $qty, float $unitPrice, float $lineTotal, string $what): void
{
    $lines = $order['lines'] ?? null;
    assert_true(is_array($lines) && array_is_list($lines), $what . ': order.lines is a list');
    $line = acc_pick($lines, 'sku', $sku, $what);
    assert_qty($qty, $line['qty'] ?? null, $what . ' ' . $sku . ' qty');
    assert_money($unitPrice, $line['unit_price'] ?? null, $what . ' ' . $sku . ' unit_price');
    assert_money($lineTotal, $line['line_total'] ?? null, $what . ' ' . $sku . ' line_total');
}

test('A3: order lines are priced at cost from /api/ingredients; client-sent prices are ignored; total 425.00', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');

    $r = $hub->get('/api/ingredients');
    assert_success($r, 200, 'hub.user GET /api/ingredients');
    $ingredients = hub_list($r, 'ingredients', 'GET /api/ingredients');
    $costs = [];
    foreach (['SMP001' => 12.50, 'SMP004' => 120.00] as $sku => $expected) {
        $costs[$sku] = acc_pick($ingredients, 'sku', $sku, 'ingredients')['cost_price'] ?? null;
        assert_money($expected, $costs[$sku], $sku . ' cost_price');
    }

    $delivery = hub_date_plus(2);
    $r = $hub->post('/api/orders', [
        'lines' => [
            ['sku' => 'SMP001', 'qty' => 10, 'unit_price' => 1.00, 'price' => 1.00, 'cost_price' => 1.00, 'line_total' => 1.00],
            ['sku' => 'SMP004', 'qty' => 2.5, 'unit_price' => 1.00, 'price' => 1.00],
        ],
        'requested_delivery_date' => $delivery,
        'unit_price' => 1.00,
        'price' => 1.00,
        'total' => 1.00,
    ]);
    assert_success($r, 201, 'hub.user POST /api/orders');
    $order = hub_object($r, 'order', 'POST /api/orders');
    assert_same('cost', $order['price_basis'] ?? null, 'price_basis');
    assert_same('pending', $order['status'] ?? null, 'status');
    assert_same($hub->locationId(), $order['location_id'] ?? null, 'order.location_id');
    assert_same($delivery, $order['requested_delivery_date'] ?? null, 'requested_delivery_date');
    a3_assert_line($order, 'SMP001', 10, 12.50, 125.00, 'created order');
    a3_assert_line($order, 'SMP004', 2.5, 120.00, 300.00, 'created order');
    assert_money(425.00, $order['total'] ?? null, 'order total');
    foreach ($order['lines'] as $line) {
        assert_money($costs[$line['sku']], $line['unit_price'] ?? null, 'unit_price equals /api/ingredients cost_price for ' . $line['sku']);
    }

    foreach (['hub.user' => $hub, 'hq.admin' => $hq] as $who => $client) {
        $r = $client->get('/api/orders/' . $order['id']);
        assert_success($r, 200, $who . ' GET /api/orders/{id}');
        $stored = hub_object($r, 'order', 'GET /api/orders/{id}');
        assert_same('cost', $stored['price_basis'] ?? null, $who . ' stored price_basis');
        a3_assert_line($stored, 'SMP001', 10, 12.50, 125.00, $who . ' stored order');
        a3_assert_line($stored, 'SMP004', 2.5, 120.00, 300.00, $who . ' stored order');
        assert_money(425.00, $stored['total'] ?? null, $who . ' stored total');
    }
    $r = $hub->get('/api/orders');
    assert_success($r, 200, 'hub.user GET /api/orders');
    $listed = acc_pick(hub_list($r, 'orders', 'GET /api/orders'), 'id', $order['id'], 'order list');
    assert_money(425.00, $listed['total'] ?? null, 'order list total');
});

test('A3: after hq.admin sets SMP001 cost to 13.00 a new order uses 13.00 and the first order still shows 12.50', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $hq = $app->client('hq.admin');

    $first = acc_place_order($hub, [['sku' => 'SMP001', 'qty' => 10], ['sku' => 'SMP004', 'qty' => 2.5]]);
    assert_money(425.00, $first['total'] ?? null, 'first order total');

    $newCost = ['name' => 'SAMPLE Cake flour', 'unit' => 'kg', 'cost_price' => 13.00, 'fincon_item_no' => 'F-SMP001'];
    assert_error($hub->put('/api/admin/ingredients/SMP001', $newCost), 403, 'FORBIDDEN', 'hub.user cannot change a cost price');
    $r = $hq->put('/api/admin/ingredients/SMP001', $newCost);
    assert_success($r, 200, 'hq.admin PUT /api/admin/ingredients/SMP001 cost 13.00');
    assert_money(13.00, $r->json['ingredient']['cost_price'] ?? null, 'updated ingredient cost_price');

    $r = $hub->get('/api/ingredients');
    assert_success($r, 200, 'hub.user GET /api/ingredients');
    assert_money(13.00, acc_pick(hub_list($r, 'ingredients', 'ingredients'), 'sku', 'SMP001', 'ingredients')['cost_price'] ?? null, 'SMP001 cost_price now');

    $second = acc_place_order($hub, [['sku' => 'SMP001', 'qty' => 10]]);
    a3_assert_line($second, 'SMP001', 10, 13.00, 130.00, 'new order');
    assert_money(130.00, $second['total'] ?? null, 'new order total');

    $r = $hub->get('/api/orders/' . $first['id']);
    assert_success($r, 200, 'GET first order');
    $stored = hub_object($r, 'order', 'GET first order');
    a3_assert_line($stored, 'SMP001', 10, 12.50, 125.00, 'first order after the cost change');
    assert_money(425.00, $stored['total'] ?? null, 'first order total after the cost change');
});

test('A3 / s11: the order reaches HQ by email (logged transport); a failed email never loses the order', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $order = acc_place_order($hub, [['sku' => 'SMP001', 'qty' => 10], ['sku' => 'SMP004', 'qty' => 2.5]]);
    assert_same('email', $order['hq_notification']['channel'] ?? null, 'hq_notification.channel');
    assert_same('sent', $order['hq_notification']['status'] ?? null, 'hq_notification.status');
    $mails = array_values(array_filter(hub_json_lines($app->mailLog()), static fn (array $m): bool => ($m['to'] ?? null) === 'hq-orders@example.test'));
    assert_same(1, count($mails), 'one email to HQ_ORDERS_EMAIL');
    assert_same('Hub order #' . $order['id'] . ' - The Hub', $mails[0]['subject'] ?? null, 'email subject');
    $body = (string) ($mails[0]['body'] ?? '');
    foreach (['SMP001', 'SMP004', 'Internal transfer at cost - not a VAT invoice.'] as $needle) {
        assert_true(str_contains($body, $needle), 'email body contains "' . $needle . '"');
    }

    $noMail = TestApp::start(['HQ_ORDERS_EMAIL' => '']);
    $order = acc_place_order($noMail->client('hub.user'), [['sku' => 'SMP001', 'qty' => 1]]);
    assert_same('failed', $order['hq_notification']['status'] ?? null, 'notification status with no HQ_ORDERS_EMAIL');
    assert_success($noMail->client('hub.user')->get('/api/orders/' . $order['id']), 200, 'order is kept when the email fails');
});

run_tests();
