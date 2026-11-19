<?php
declare(strict_types=1);

// Shared helpers for the lane A warehouse queue tests (build spec 2026-11-16 s6). Not a test file itself:
// tests/run.php only runs *_test.php. Every test file requires the harness first, then this file.

/** Places a hub order over HTTP and returns its id. @param list<array{sku:string,qty:int|float}> $lines */
function wh_order(ApiClient $hub, array $lines, ?string $deliveryDate = null, ?string $notes = null): int
{
    $body = ['lines' => $lines];
    if ($deliveryDate !== null) {
        $body['requested_delivery_date'] = $deliveryDate;
    }
    if ($notes !== null) {
        $body['notes'] = $notes;
    }
    $r = $hub->post('/api/orders', $body);
    assert_success($r, 201, 'POST /api/orders as ' . $hub->username);
    return (int) $r->json['order']['id'];
}

/** Moves an order with the existing PATCH endpoint (hq). */
function wh_move(ApiClient $hq, int $orderId, string $status): void
{
    assert_success($hq->patch('/api/orders/' . $orderId . '/status', ['status' => $status]), 200, 'move order ' . $orderId . ' to ' . $status);
}

/** GET /api/warehouse/queue, asserted 200 + success. */
function wh_queue(ApiClient $hq): HttpResult
{
    $r = $hq->get('/api/warehouse/queue');
    assert_success($r, 200, 'GET /api/warehouse/queue as ' . $hq->username);
    return $r;
}

/** Order ids in the queue, in API order. */
function wh_ids(HttpResult $r): array
{
    return array_map('intval', hub_column(hub_list($r, 'orders', 'queue orders'), 'id'));
}

/** Row count of a table in the test app's SQLite file (table names are fixed by the caller). */
function wh_count(TestApp $app, string $table): int
{
    if (!in_array($table, ['orders', 'order_lines', 'inventory_movements', 'order_notifications', 'auth_tokens'], true)) {
        hub_fail('wh_count: table not allowed: ' . $table);
    }
    $pdo = new PDO('sqlite:' . $app->dbPath());
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
