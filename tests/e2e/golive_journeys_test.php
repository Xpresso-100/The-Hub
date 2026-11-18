<?php
declare(strict_types=1);

// Go-live API journeys J1-J9 (docs/BUILD-SPEC-2026-11-18-go-live.md s10, Lane E, E1).
// Black-box over HTTP through TestApp, like hub_smoke_flow_test.php. The only non-HTTP step is J3 and the
// set-up of J5-J8: bin/load_hub_list.php is run with hub_php() against the TestApp's own DB env.
// Fixture users and made-up data only: hub.e2e (example.invalid email), SKUs E2E001-E2E004, made-up prices.
//
// Fixture list tests/fixtures/e2e/golive_hub_list.csv, hand-calculated cost per unit (SPEC s3.1,
// cost = round_half_up(pack_price_cents * 1000, pack_size_milli)):
//   E2E001 Test Flour  25 kg at R200.00: 20000 * 1000 / 25000 = 800      -> R8.00 per kg (exact)
//   E2E002 Test Sugar  10 kg at R35.00 :  3500 * 1000 / 10000 = 350      -> R3.50 per kg (exact)
//   E2E003 Test Cream   3 l  at R10.00 :  1000 * 1000 / 3000  = 333.33   -> 333 cents -> R3.33 per l
//   E2E004 Test Spice Mix: no price -> loaded switched off at R0.00 (never orderable)

require __DIR__ . '/../lib/harness.php';

const GL_CSV = HUB_TEST_ROOT . '/tests/fixtures/e2e/golive_hub_list.csv';
const GL_HUB_USER = 'hub.e2e';
const GL_HUB_EMAIL = 'hub.e2e@example.invalid';
const GL_HUB_PASSWORD = 'Hub-E2e-Pw-7391';

/** Makes the app, creates hub.e2e through the API (J1 path) and optionally loads the fixture list. */
function gl_app(bool $load): array
{
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $r = $hq->post('/api/admin/users', [
        'username' => GL_HUB_USER,
        'email' => GL_HUB_EMAIL,
        'password' => GL_HUB_PASSWORD,
        'location_id' => gl_hub_location($hq),
    ]);
    assert_success($r, 201, 'set-up: create hub.e2e');
    if ($load) {
        gl_load($app);
    }
    $token = gl_login($app);
    return [$app, $hq, $token, (int) $r->json['user']['id']];
}

function gl_hub_location(ApiClient $hq): int
{
    $r = $hq->get('/api/admin/locations');
    assert_success($r, 200, 'GET /api/admin/locations');
    $loc = hub_find(hub_list($r, 'locations', 'locations'), 'name', 'The Hub');
    assert_true($loc !== null, 'location "The Hub" is listed');
    return (int) $loc['id'];
}

function gl_login(TestApp $app): string
{
    $r = $app->request('POST', '/api/login', ['username' => GL_HUB_USER, 'email' => GL_HUB_EMAIL, 'password' => GL_HUB_PASSWORD]);
    assert_success($r, 200, 'hub.e2e logs in');
    return (string) $r->json['token'];
}

/** @return array{0:int,1:string} exit code and output of the loader */
function gl_load(TestApp $app, bool $deactivateSamples = true): array
{
    $args = ['bin/load_hub_list.php'];
    if ($deactivateSamples) {
        $args[] = '--deactivate-samples';
    }
    $args[] = GL_CSV;
    [$code, $out] = hub_php($args, $app->env);
    assert_same(0, $code, 'load_hub_list exit code; output: ' . hub_snippet($out));
    return [$code, $out];
}

/** sku => on_hand from GET /api/inventory as the token's user. */
function gl_stock(TestApp $app, string $token): array
{
    $r = $app->request('GET', '/api/inventory', null, $token);
    assert_success($r, 200, 'GET /api/inventory');
    $out = [];
    foreach (hub_list($r, 'items', 'inventory items') as $item) {
        $out[$item['sku']] = $item['on_hand'];
    }
    return $out;
}

function gl_order(TestApp $app, string $token, array $lines): array
{
    $r = $app->request('POST', '/api/orders', ['lines' => $lines], $token);
    assert_success($r, 201, 'POST /api/orders');
    return hub_object($r, 'order', 'created order');
}

function gl_move(TestApp $app, string $hqToken, int $orderId, string $status): HttpResult
{
    return $app->request('PATCH', '/api/orders/' . $orderId . '/status', ['status' => $status], $hqToken);
}

test('J1: hq creates a hub user; a weak password, a duplicate username and a franchise location are each refused', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hubLocation = gl_hub_location($hq);
    $franchiseLocation = $app->client('franchise.user')->locationId();
    $countUsers = static fn (): int => count(hub_list($hq->get('/api/admin/users'), 'users', 'GET /api/admin/users'));
    $before = $countUsers();

    $good = ['username' => GL_HUB_USER, 'email' => GL_HUB_EMAIL, 'password' => GL_HUB_PASSWORD, 'location_id' => $hubLocation];

    // Refused: weak (10 character) password, duplicate username, franchise location.
    $r = $hq->post('/api/admin/users', ['password' => 'Weak-pw-1!'] + $good);
    assert_error($r, 422, 'VALIDATION_ERROR', 'weak password');
    assert_true(isset($r->json['errors']['password']), 'errors.password present; ' . $r->describe());
    assert_error($hq->post('/api/admin/users', ['username' => 'hub.user'] + $good), 409, 'CONFLICT', 'duplicate username');
    $r = $hq->post('/api/admin/users', ['location_id' => $franchiseLocation] + $good);
    assert_error($r, 422, 'VALIDATION_ERROR', 'franchise location');
    assert_true(isset($r->json['errors']['location_id']), 'errors.location_id present; ' . $r->describe());
    assert_same($before, $countUsers(), 'no user was created by a refused request');
    assert_error($app->request('POST', '/api/login', ['username' => GL_HUB_USER, 'email' => GL_HUB_EMAIL, 'password' => GL_HUB_PASSWORD]), 401, 'INVALID_CREDENTIALS', 'refused user cannot log in');

    // Accepted: the same request with a valid password, a free username and the hub location.
    $r = $hq->post('/api/admin/users', $good);
    assert_success($r, 201, 'valid create');
    assert_same(GL_HUB_USER, $r->json['user']['username'] ?? null, 'created username');
    assert_same('hub', $r->json['user']['location']['type'] ?? null, 'created location type');
    assert_same([], hub_keys_matching($r->json, '/password|hash/i'), 'no password or hash in the response');
    assert_same($before + 1, $countUsers(), 'exactly one user created');
});

test('J2: the new hub user logs in; admin endpoints return 403; ordering and inventory return 200', function (): void {
    [$app, , $token] = gl_app(false);

    $me = $app->request('GET', '/api/me', null, $token);
    assert_success($me, 200, 'GET /api/me');
    assert_same('hub', $me->json['user']['role'] ?? null, 'role');
    assert_same(GL_HUB_USER, $me->json['user']['username'] ?? null, 'username');

    // Accounts admin and Ingredients admin.
    assert_error($app->request('GET', '/api/admin/users', null, $token), 403, 'FORBIDDEN', 'GET /api/admin/users');
    assert_error($app->request('GET', '/api/admin/locations', null, $token), 403, 'FORBIDDEN', 'GET /api/admin/locations');
    assert_error($app->request('POST', '/api/admin/users', ['username' => 'x.y.z'], $token), 403, 'FORBIDDEN', 'POST /api/admin/users');
    $body = ['name' => 'Test Nope', 'unit' => 'kg', 'cost_price' => 1.0, 'fincon_item_no' => null, 'active' => true];
    assert_error($app->request('PUT', '/api/admin/ingredients/E2E-NOPE', $body, $token), 403, 'FORBIDDEN', 'PUT /api/admin/ingredients/{sku}');

    // Ordering and inventory.
    assert_success($app->request('GET', '/api/ingredients', null, $token), 200, 'GET /api/ingredients');
    assert_success($app->request('GET', '/api/orders', null, $token), 200, 'GET /api/orders');
    assert_success($app->request('GET', '/api/inventory', null, $token), 200, 'GET /api/inventory');
});

test('J3: the loader loads the fixture list; the hub sees exactly the 3 priced lines and samples are off', function (): void {
    [$app, $hq, $token] = gl_app(false);
    [, $out] = gl_load($app);
    assert_true(
        preg_match('/load_hub_list: 3 active, 1 off, 0 kept, \d+ samples off/', $out) === 1,
        'loader summary line; output: ' . hub_snippet($out)
    );

    // What the hub user sees: exactly the 3 priced lines, at the hand-calculated costs (see the header).
    $r = $app->request('GET', '/api/ingredients', null, $token);
    assert_success($r, 200, 'hub GET /api/ingredients');
    $list = hub_list($r, 'ingredients', 'hub ingredients');
    $skus = hub_column($list, 'sku');
    sort($skus);
    assert_same(['E2E001', 'E2E002', 'E2E003'], $skus, 'hub ingredient list shows exactly the 3 priced lines');
    assert_money(8.00, hub_find($list, 'sku', 'E2E001')['cost_price'] ?? null, 'E2E001 cost per kg');
    assert_money(3.50, hub_find($list, 'sku', 'E2E002')['cost_price'] ?? null, 'E2E002 cost per kg');
    assert_money(3.33, hub_find($list, 'sku', 'E2E003')['cost_price'] ?? null, 'E2E003 cost per l (333.33 cents rounds down)');
    assert_same('Test Flour', hub_find($list, 'sku', 'E2E001')['name'] ?? null, 'E2E001 name');

    // The unpriced line exists but is switched off at R0.00; every sample is switched off.
    $all = hub_list($hq->get('/api/ingredients?include_inactive=1'), 'ingredients', 'hq ingredients incl. inactive');
    $spice = hub_find($all, 'sku', 'E2E004');
    assert_true($spice !== null, 'E2E004 was loaded');
    assert_same(false, $spice['active'] ?? null, 'E2E004 is switched off');
    assert_money(0.00, $spice['cost_price'] ?? null, 'E2E004 cost is R0.00');
    $samples = array_filter($all, static fn (array $i): bool => str_starts_with((string) $i['sku'], 'SMP'));
    assert_true(count($samples) >= 6, 'the sample ingredients are still on file; found ' . count($samples));
    foreach ($samples as $sample) {
        assert_same(false, $sample['active'] ?? null, $sample['sku'] . ' (sample) is switched off');
    }
});

test('J4: the hub enters a stock count and GET /api/inventory shows it', function (): void {
    [$app, , $token] = gl_app(true);
    $before = gl_stock($app, $token);
    assert_qty(0, $before['E2E001'] ?? null, 'E2E001 starts at zero');

    $r = $app->request('POST', '/api/inventory/adjustments', ['sku' => 'E2E001', 'qty_delta' => 5, 'reason' => 'stock count'], $token);
    assert_success($r, 201, 'POST /api/inventory/adjustments +5');
    assert_qty(5, $r->json['on_hand'] ?? null, 'on_hand in the adjustment response');
    $r = $app->request('POST', '/api/inventory/adjustments', ['sku' => 'E2E002', 'qty_delta' => 1.25, 'reason' => 'stock count'], $token);
    assert_success($r, 201, 'POST /api/inventory/adjustments +1.25');

    $after = gl_stock($app, $token);
    assert_qty(5, $after['E2E001'] ?? null, 'E2E001 on_hand in the inventory list');
    assert_qty(1.25, $after['E2E002'] ?? null, 'E2E002 on_hand in the inventory list');
    assert_qty(0, $after['E2E003'] ?? null, 'E2E003 untouched');
});

test('J5: the hub orders 2 lines; the warehouse queue shows the order, its lines and the pick-list totals; cost excl. VAT', function (): void {
    [$app, $hq, $token] = gl_app(true);
    $hqToken = (string) $hq->token;

    // Hand calculation (SPEC s3.1: line_cents = round_half_up(qty_milli * cost_cents_per_unit, 1000)):
    //   E2E001: 2.5 kg = 2500 milli x 800 c = 2 000 000 / 1000 = 2000 c exactly          -> R20.00
    //   E2E003: 0.5 l  =  500 milli x 333 c =   166 500 / 1000 = 166.5 c -> half up 167  -> R1.67
    //   order total = 2000 + 167 = 2167 c = R21.67, cost basis excl. VAT, no VAT added.
    //   Second order (hub2.user): E2E001 1.25 kg = 1250 x 800 = 1 000 000 / 1000 = 1000 c -> R10.00
    // Client-sent prices (R0.01) must be ignored.
    $order = gl_order($app, $token, [
        ['sku' => 'E2E001', 'qty' => 2.5, 'unit_price' => 0.01],
        ['sku' => 'E2E003', 'qty' => 0.5, 'price' => 0.01],
    ]);
    $orderId = $order['id'];
    assert_true(is_int($orderId), 'order id is an int');
    assert_same('pending', $order['status'] ?? null, 'order status');
    assert_same('cost', $order['price_basis'] ?? null, 'price basis is cost');
    $flour = hub_find($order['lines'] ?? [], 'sku', 'E2E001');
    $cream = hub_find($order['lines'] ?? [], 'sku', 'E2E003');
    assert_true($flour !== null && $cream !== null, 'both lines are on the order');
    assert_money(8.00, $flour['unit_price'] ?? null, 'E2E001 unit_price');
    assert_money(20.00, $flour['line_total'] ?? null, 'E2E001 line_total');
    assert_money(3.33, $cream['unit_price'] ?? null, 'E2E003 unit_price');
    assert_money(1.67, $cream['line_total'] ?? null, 'E2E003 line_total (166.5 cents rounds half up to 167)');
    assert_money(21.67, $order['total'] ?? null, 'order total, excl. VAT');
    assert_same([], hub_keys_matching($order, '/vat|tax/i'), 'no VAT or tax anywhere on the order');

    $detail = $app->request('GET', '/api/orders/' . $orderId, null, $token);
    assert_success($detail, 200, 'GET /api/orders/{id}');
    assert_money(21.67, $detail->json['order']['total'] ?? null, 'order detail total');

    $other = gl_order($app, $app->client('hub2.user')->token, [['sku' => 'E2E001', 'qty' => 1.25]]);
    assert_money(10.00, $other['total'] ?? null, 'second order total');

    // Warehouse queue (hq): the order, its lines and the pick-list totals.
    $q = $app->request('GET', '/api/warehouse/queue', null, $hqToken);
    assert_success($q, 200, 'GET /api/warehouse/queue');
    assert_same(['pending' => 2, 'in_process' => 0, 'out_for_delivery' => 0], hub_object($q, 'counts', 'counts'), 'queue counts');
    $queued = hub_find(hub_list($q, 'orders', 'queue orders'), 'id', $orderId);
    assert_true($queued !== null, 'the order is in the queue');
    assert_same('The Hub', $queued['location_name'] ?? null, 'queue location_name');
    assert_same(GL_HUB_USER, $queued['created_by'] ?? null, 'queue created_by');
    assert_same(2, count($queued['lines'] ?? []), 'queue order has 2 lines');
    $qFlour = hub_find($queued['lines'], 'sku', 'E2E001');
    $qCream = hub_find($queued['lines'], 'sku', 'E2E003');
    assert_true($qFlour !== null && $qCream !== null, 'queue lines are E2E001 and E2E003');
    assert_same('Test Flour', $qFlour['name'] ?? null, 'queue line name');
    assert_same('kg', $qFlour['unit'] ?? null, 'queue line unit');
    assert_qty(2.5, $qFlour['qty'] ?? null, 'queue line E2E001 qty');
    assert_qty(0.5, $qCream['qty'] ?? null, 'queue line E2E003 qty');

    // Pick list: 2.5 + 1.25 = 3.75 kg of flour over both orders; 0.5 l of cream.
    $pick = hub_list($q, 'picklist', 'picklist');
    assert_same(['E2E001', 'E2E003'], hub_column($pick, 'sku'), 'pick-list skus');
    assert_same(3.75, hub_find($pick, 'sku', 'E2E001')['qty'] ?? null, 'pick-list E2E001 total qty');
    assert_same([$orderId, $other['id']], hub_find($pick, 'sku', 'E2E001')['order_ids'] ?? null, 'pick-list E2E001 order_ids');
    assert_same(0.5, hub_find($pick, 'sku', 'E2E003')['qty'] ?? null, 'pick-list E2E003 total qty');
    assert_same([$orderId], hub_find($pick, 'sku', 'E2E003')['order_ids'] ?? null, 'pick-list E2E003 order_ids');
});

test('J6: hq walks pending -> in_process -> out_for_delivery -> delivered; hub stock rises by exactly the ordered quantities; 409 after', function (): void {
    [$app, $hq, $token] = gl_app(true);
    $hqToken = (string) $hq->token;
    assert_success($app->request('POST', '/api/inventory/adjustments', ['sku' => 'E2E001', 'qty_delta' => 5, 'reason' => 'stock count'], $token), 201, 'opening E2E001 5');
    $before = gl_stock($app, $token);
    assert_qty(5, $before['E2E001'] ?? null, 'E2E001 opening stock');
    assert_qty(0, $before['E2E003'] ?? null, 'E2E003 opening stock');

    $orderId = gl_order($app, $token, [['sku' => 'E2E001', 'qty' => 2.5], ['sku' => 'E2E003', 'qty' => 0.5]])['id'];

    $r = gl_move($app, $hqToken, $orderId, 'in_process');
    assert_success($r, 200, 'pending -> in_process');
    assert_same('in_process', $r->json['order']['status'] ?? null, 'status in_process');
    $r = gl_move($app, $hqToken, $orderId, 'out_for_delivery');
    assert_success($r, 200, 'in_process -> out_for_delivery');
    assert_same('out_for_delivery', $r->json['order']['status'] ?? null, 'status out_for_delivery');
    assert_error(gl_move($app, $hqToken, $orderId, 'in_process'), 409, 'CONFLICT', 'backward out_for_delivery -> in_process');
    assert_error(gl_move($app, $hqToken, $orderId, 'pending'), 409, 'CONFLICT', 'backward out_for_delivery -> pending');
    assert_same($before, gl_stock($app, $token), 'stock is unchanged before delivery');

    $r = gl_move($app, $hqToken, $orderId, 'delivered');
    assert_success($r, 200, 'out_for_delivery -> delivered');
    assert_same('delivered', $r->json['order']['status'] ?? null, 'status delivered');

    // Hub stock rises by exactly the ordered quantities: 5 + 2.5 = 7.5 kg; 0 + 0.5 = 0.5 l; nothing else moves.
    $after = gl_stock($app, $token);
    assert_qty(7.5, $after['E2E001'] ?? null, 'E2E001 stock after delivery');
    assert_qty(0.5, $after['E2E003'] ?? null, 'E2E003 stock after delivery');
    assert_qty(0, $after['E2E002'] ?? null, 'E2E002 stock after delivery (not ordered)');

    foreach (['delivered', 'out_for_delivery', 'in_process', 'pending', 'cancelled'] as $again) {
        assert_error(gl_move($app, $hqToken, $orderId, $again), 409, 'CONFLICT', 'delivered -> ' . $again);
    }
    assert_same($after, gl_stock($app, $token), 'stock is credited exactly once');
});

test('J7: ordering the unpriced (inactive) sku is refused; so is a switched-off sample', function (): void {
    [$app, , $token] = gl_app(true);
    foreach (['E2E004', 'SMP001'] as $sku) {
        $r = $app->request('POST', '/api/orders', ['lines' => [['sku' => 'E2E001', 'qty' => 1], ['sku' => $sku, 'qty' => 1]]], $token);
        assert_error($r, 422, 'VALIDATION_ERROR', 'order containing ' . $sku);
        assert_true(isset($r->json['errors']['lines.1.sku']), $sku . ': errors.lines.1.sku present; ' . $r->describe());
    }
    $r = $app->request('POST', '/api/orders', ['lines' => [['sku' => 'E2E004', 'qty' => 1]]], $token);
    assert_error($r, 422, 'VALIDATION_ERROR', 'order of only E2E004');
    assert_true(isset($r->json['errors']['lines.0.sku']), 'errors.lines.0.sku present; ' . $r->describe());
    assert_same([], hub_list($app->request('GET', '/api/orders', null, $token), 'orders', 'orders'), 'a refused order is not saved');
});

test('J8: a franchise user gets 403 on warehouse and admin endpoints; cancel works from pending', function (): void {
    [$app, $hq, $token] = gl_app(true);
    $hqToken = (string) $hq->token;
    $fr = $app->client('franchise.user');

    assert_error($fr->get('/api/warehouse/queue'), 403, 'FORBIDDEN', 'franchise GET /api/warehouse/queue');
    assert_error($fr->get('/api/admin/users'), 403, 'FORBIDDEN', 'franchise GET /api/admin/users');
    assert_error($fr->get('/api/admin/locations'), 403, 'FORBIDDEN', 'franchise GET /api/admin/locations');
    assert_error($fr->put('/api/admin/ingredients/E2E-NOPE', ['name' => 'Test Nope', 'unit' => 'kg', 'cost_price' => 1.0, 'fincon_item_no' => null, 'active' => true]), 403, 'FORBIDDEN', 'franchise PUT /api/admin/ingredients/{sku}');

    $orderId = gl_order($app, $token, [['sku' => 'E2E002', 'qty' => 4]])['id'];
    assert_error($fr->patch('/api/orders/' . $orderId . '/status', ['status' => 'cancelled']), 403, 'FORBIDDEN', 'franchise cancel');
    assert_error($app->request('PATCH', '/api/orders/' . $orderId . '/status', ['status' => 'cancelled'], $token), 403, 'FORBIDDEN', 'hub cannot cancel either');

    $r = gl_move($app, $hqToken, $orderId, 'cancelled');
    assert_success($r, 200, 'hq cancels from pending');
    assert_same('cancelled', $r->json['order']['status'] ?? null, 'status cancelled');
    $q = $app->request('GET', '/api/warehouse/queue', null, $hqToken);
    assert_same([], hub_list($q, 'orders', 'queue orders'), 'a cancelled order leaves the queue');
    assert_same([], hub_list($q, 'picklist', 'picklist'), 'a cancelled order leaves the pick list');
    assert_qty(0, gl_stock($app, $token)['E2E002'] ?? null, 'a cancelled order credits no stock');
    assert_error(gl_move($app, $hqToken, $orderId, 'in_process'), 409, 'CONFLICT', 'nothing after cancelled');
});

test('J9: a deactivated user\'s token stops working', function (): void {
    [$app, $hq, $token, $userId] = gl_app(false);
    assert_success($app->request('GET', '/api/me', null, $token), 200, 'token works before deactivation');
    assert_success($app->request('GET', '/api/orders', null, $token), 200, 'GET /api/orders works before deactivation');
    $otherToken = $app->client('hub.user')->token;

    $r = $hq->patch('/api/admin/users/' . $userId, ['active' => false]);
    assert_success($r, 200, 'PATCH active=false');
    assert_same(false, $r->json['user']['active'] ?? null, 'user.active');

    assert_error($app->request('GET', '/api/me', null, $token), 401, 'UNAUTHENTICATED', 'old token on /api/me');
    assert_error($app->request('GET', '/api/orders', null, $token), 401, 'UNAUTHENTICATED', 'old token on /api/orders');
    assert_error($app->request('POST', '/api/login', ['username' => GL_HUB_USER, 'email' => GL_HUB_EMAIL, 'password' => GL_HUB_PASSWORD]), 401, 'INVALID_CREDENTIALS', 'login while deactivated');
    assert_success($app->request('GET', '/api/me', null, $otherToken), 200, 'another user\'s token is unaffected');
});

run_tests();
