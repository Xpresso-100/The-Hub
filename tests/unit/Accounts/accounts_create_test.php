<?php
declare(strict_types=1);

// Lane C tests 2-5 (build spec 2026-11-16 s8): POST /api/admin/users creates a login that really works,
// refuses franchise locations on the server, and validates every field.

require __DIR__ . '/../../lib/harness.php';

/** A throwaway test-only login secret, built at run time (never a real credential). */
function acc_pw(string $tag): string
{
    return 'Test-' . $tag . '-' . str_repeat('x', 8);
}

/** Id of the listed account location called $name (asserts it is listed). */
function acc_location_id(TestApp $app, string $name): int
{
    $r = $app->client('hq.admin')->get('/api/admin/locations');
    assert_success($r, 200, 'GET /api/admin/locations');
    $loc = hub_find(hub_list($r, 'locations', 'GET /api/admin/locations'), 'name', $name);
    assert_true($loc !== null, 'location "' . $name . '" is listed; ' . $r->describe());
    return (int) $loc['id'];
}

function acc_db(TestApp $app): PDO
{
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function acc_user_count(TestApp $app): int
{
    return (int) acc_db($app)->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function acc_login(TestApp $app, string $username, string $email, string $secret): HttpResult
{
    return $app->request('POST', '/api/login', ['username' => $username, 'email' => $email, 'password' => $secret]);
}

test('C3 / test 2: hq creates a hub login; the new user logs in over HTTP and GET /api/me says role hub', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hqId = (int) $hq->me()['id'];
    $hubLocation = acc_location_id($app, 'The Hub');
    $secret = acc_pw('hub');

    $r = $hq->post('/api/admin/users', [
        'username' => 'new.hub_user-1',
        'email' => 'new.hub@example.test',
        'password' => $secret,
        'location_id' => $hubLocation,
    ]);
    assert_success($r, 201, 'POST /api/admin/users (hub location)');
    $user = hub_object($r, 'user', 'POST /api/admin/users');
    assert_true(is_int($user['id'] ?? null), 'user.id is an int; ' . $r->describe());
    assert_same('new.hub_user-1', $user['username'] ?? null, 'user.username');
    assert_same('new.hub@example.test', $user['email'] ?? null, 'user.email');
    assert_same(true, $user['active'] ?? null, 'user.active');
    assert_same($hubLocation, $user['location']['id'] ?? null, 'user.location.id');
    assert_same('The Hub', $user['location']['name'] ?? null, 'user.location.name');
    assert_same('hub', $user['location']['type'] ?? null, 'user.location.type');
    assert_true(
        is_string($user['created_at'] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $user['created_at']) === 1,
        'user.created_at is ISO 8601 with offset; ' . $r->describe()
    );
    assert_same([], hub_keys_matching($r->json, '/password|hash/i'), 'no password or hash key in the response');
    assert_true(!str_contains($r->body, $secret), 'the plain password is never returned');
    assert_true(!str_contains($r->body, '$2y$'), 'no bcrypt hash in the response');

    $login = acc_login($app, 'new.hub_user-1', 'new.hub@example.test', $secret);
    assert_success($login, 200, 'the new user logs in with username, email and password');
    $me = $app->request('GET', '/api/me', null, (string) ($login->json['token'] ?? ''));
    assert_success($me, 200, 'GET /api/me as the new user');
    assert_same('hub', $me->json['user']['role'] ?? null, 'new user role');
    assert_same($hubLocation, $me->json['user']['location']['id'] ?? null, 'new user location');

    // Audit: one id-only line; the password and email never reach the log.
    $log = (string) @file_get_contents($app->server->logFile);
    assert_true(
        str_contains($log, 'HUB-AUDIT account_created user_id=' . $user['id'] . ' by=' . $hqId),
        'server log has the HUB-AUDIT account_created line'
    );
    assert_true(!str_contains($log, $secret), 'the password is not in the server log');
    assert_true(!str_contains($log, 'new.hub@example.test'), 'the email is not in the server log');
});

test('C3 / test 3: hq creates an hq login; it logs in with role hq and can open the account list', function (): void {
    $app = TestApp::start();
    $hqLocation = acc_location_id($app, 'Xpresso HQ');
    $secret = acc_pw('hq');
    $r = $app->client('hq.admin')->post('/api/admin/users', [
        'username' => 'warehouse.lead',
        'email' => 'warehouse.lead@example.test',
        'password' => $secret,
        'location_id' => $hqLocation,
    ]);
    assert_success($r, 201, 'POST /api/admin/users (hq location)');
    assert_same('hq', $r->json['user']['location']['type'] ?? null, 'user.location.type');

    $login = acc_login($app, 'warehouse.lead', 'warehouse.lead@example.test', $secret);
    assert_success($login, 200, 'the new hq user logs in');
    $token = (string) ($login->json['token'] ?? '');
    $me = $app->request('GET', '/api/me', null, $token);
    assert_success($me, 200, 'GET /api/me as the new hq user');
    assert_same('hq', $me->json['user']['role'] ?? null, 'new user role');
    assert_success($app->request('GET', '/api/admin/users', null, $token), 200, 'the new hq user can open GET /api/admin/users');
});

test('C2 / test 4: franchise, inactive and unknown locations are never listed and are refused with 422 on location_id', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $franchiseLocation = $app->client('franchise.user')->locationId();

    $pdo = acc_db($app);
    $pdo->prepare('INSERT INTO locations (name, type, active, created_at) VALUES (?, ?, 0, ?)')
        ->execute(['Closed Hub (TEST)', 'hub', '2026-11-16 08:00:00']);
    $closedLocation = (int) $pdo->lastInsertId();
    $pdo = null;

    $r = $hq->get('/api/admin/locations');
    assert_success($r, 200, 'GET /api/admin/locations');
    $list = hub_list($r, 'locations', 'GET /api/admin/locations');
    assert_true(count($list) >= 3, 'HQ and both hub locations listed; ' . $r->describe());
    $ids = hub_column($list, 'id');
    assert_true(!in_array($franchiseLocation, $ids, true), 'the franchise location is never listed');
    assert_true(!in_array($closedLocation, $ids, true), 'an inactive location is not listed');
    foreach ($list as $loc) {
        assert_true(in_array($loc['type'] ?? null, ['hq', 'hub'], true), 'only hq and hub types listed: ' . hub_export($loc));
        assert_same(['id', 'name', 'type'], array_keys($loc), 'location keys');
    }

    $before = acc_user_count($app);
    $secret = acc_pw('franchise');
    $body = ['username' => 'franchise.try', 'email' => 'franchise.try@example.test', 'password' => $secret];

    $r = $hq->post('/api/admin/users', $body + ['location_id' => $franchiseLocation]);
    assert_error($r, 422, 'VALIDATION_ERROR', 'franchise location refused');
    assert_true(isset($r->json['errors']['location_id']), 'errors.location_id present; ' . $r->describe());
    assert_true(str_contains((string) $r->json['errors']['location_id'], 'franchise locations cannot have Hub accounts'), 'franchise message; ' . $r->describe());

    foreach (['inactive' => $closedLocation, 'unknown' => 999999, 'string id' => (string) $closedLocation, 'missing' => null] as $label => $locationId) {
        $r = $hq->post('/api/admin/users', $body + ['location_id' => $locationId]);
        assert_error($r, 422, 'VALIDATION_ERROR', $label . ' location refused');
        assert_true(isset($r->json['errors']['location_id']), $label . ': errors.location_id present; ' . $r->describe());
    }

    assert_same($before, acc_user_count($app), 'no user row was inserted');
    assert_error(acc_login($app, 'franchise.try', 'franchise.try@example.test', $secret), 401, 'INVALID_CREDENTIALS', 'the refused user cannot log in');
});

test('C3 / test 5: duplicate username 409; bad password, email and username 422; boundaries accepted', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hubLocation = acc_location_id($app, 'The Hub');
    $ok = ['username' => 'valid.user', 'email' => 'valid.user@example.test', 'password' => str_repeat('a', 12), 'location_id' => $hubLocation];
    $before = acc_user_count($app);

    assert_error($hq->post('/api/admin/users', ['username' => 'hub.user'] + $ok), 409, 'CONFLICT', 'duplicate username');
    assert_error($hq->post('/api/admin/users', ['username' => 'HUB.USER'] + $ok), 409, 'CONFLICT', 'duplicate username in another case');

    $longEmail = str_repeat('a', 60) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.test'; // 193 characters
    $cases = [
        'password of 11 characters' => [['password' => str_repeat('a', 11)], 'password'],
        'password of 73 bytes' => [['password' => str_repeat('a', 73)], 'password'],
        'password of 37 two-byte characters (74 bytes)' => [['password' => str_repeat("\u{e9}", 37)], 'password'],
        'password of only spaces' => [['password' => str_repeat(' ', 14)], 'password'],
        'password as a number' => [['password' => 123456789012], 'password'],
        'email without @' => [['email' => 'not-an-email'], 'email'],
        'email over 190 characters' => [['email' => $longEmail], 'email'],
        'username with a space' => [['username' => 'bad name'], 'username'],
        'username with !' => [['username' => 'bad!name'], 'username'],
        'username of 2 characters' => [['username' => 'ab'], 'username'],
        'username of 61 characters' => [['username' => str_repeat('a', 61)], 'username'],
    ];
    foreach ($cases as $label => [$change, $field]) {
        $r = $hq->post('/api/admin/users', $change + $ok);
        assert_error($r, 422, 'VALIDATION_ERROR', $label);
        assert_true(isset($r->json['errors'][$field]), $label . ': errors.' . $field . ' present; ' . $r->describe());
    }

    $r = $hq->post('/api/admin/users', []);
    assert_error($r, 422, 'VALIDATION_ERROR', 'empty body');
    foreach (['username', 'email', 'password', 'location_id'] as $field) {
        assert_true(isset($r->json['errors'][$field]), 'empty body: errors.' . $field . ' present; ' . $r->describe());
    }
    assert_same($before, acc_user_count($app), 'no user row inserted by a refused request');

    // Boundaries that must be accepted, and must log in.
    $accepted = [
        ['username' => 'abc', 'email' => 'abc@example.test', 'password' => str_repeat('b', 12)],
        ['username' => str_repeat('u', 60), 'email' => 'sixty@example.test', 'password' => str_repeat('c', 72)],
    ];
    foreach ($accepted as $creds) {
        $label = strlen($creds['username']) . '-char username, ' . strlen($creds['password']) . '-byte password';
        assert_success($hq->post('/api/admin/users', $creds + ['location_id' => $hubLocation]), 201, 'accepted: ' . $label);
        assert_success(acc_login($app, $creds['username'], $creds['email'], $creds['password']), 200, 'boundary user logs in: ' . $label);
    }
    assert_same($before + 2, acc_user_count($app), 'exactly the two boundary users were inserted');
});

run_tests();
