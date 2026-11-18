<?php
declare(strict_types=1);

// Hardening item 6: a new account password is shown once and never again.
// The password is generated or typed in the browser (public/assets/accounts.js) and shown to the admin once in the
// page message. The SERVER must never return it, in any response, or write it anywhere:
//  - POST /api/admin/users success (201): no password in body or headers, Cache-Control: no-store
//  - GET /api/admin/users and PATCH /api/admin/users/{id} (the only per-user response): no password, no hash
//  - 422 / 409 / 400 / 403 error responses to a request that carried a password do not echo it
//  - the server error log (php -S stderr, which also receives the 'HUB-AUDIT' lines) never holds it
//  - the database holds only a bcrypt hash of it
// There is no GET /api/admin/users/{id} route; C6-04 shows that if one is ever added it must also be password-free.
// "The password" is checked in every form a careless implementation might leak: raw, JSON-escaped, URL-encoded,
// base64, hex, and a random core fragment. Test-only throwaway values built at run time.
//
// Browser test: none added. tests/browser/run.cjs already has W3 (create through the form, password shown exactly once,
// "not shown again", field cleared, gone after a reload) and tests/unit/Accounts/accounts_ui_test.php locks that the
// password never reaches storage, the URL or the console. A new browser case would need an edit to run.cjs (an
// existing file, one shared check() list), which this task does not allow.

require __DIR__ . '/../../lib/harness.php';

/** [core, password]: a throwaway password with characters that JSON and URLs would escape. */
function pw_make(): array
{
    $core = bin2hex(random_bytes(6));
    return [$core, 'Fx/' . $core . '"Q\\z&=+'];
}

/** Every form of $pw that must not appear in a response, a log line or a database row. */
function pw_forms(string $core, string $pw): array
{
    return [
        'raw' => $pw,
        'json-escaped' => substr((string) json_encode($pw), 1, -1),
        'json-unescaped-slashes' => substr((string) json_encode($pw, JSON_UNESCAPED_SLASHES), 1, -1),
        'urlencoded' => urlencode($pw),
        'rawurlencoded' => rawurlencode($pw),
        'base64' => base64_encode($pw),
        'hex' => bin2hex($pw),
        'core fragment' => $core,
    ];
}

function pw_assert_absent(string $haystack, array $forms, string $where): void
{
    foreach ($forms as $form => $needle) {
        assert_true(!str_contains($haystack, $needle), $where . ' contains the password (' . $form . ' form)');
    }
}

function pw_assert_response_clean(HttpResult $r, array $forms, string $what): void
{
    pw_assert_absent($r->body, $forms, $what . ' body');
    pw_assert_absent(implode("\n", array_map(static fn ($k, $v): string => $k . ': ' . $v, array_keys($r->headers), $r->headers)), $forms, $what . ' headers');
    // Keys under errors.* only name the field that failed validation; they are not a leak.
    $keys = array_values(array_filter(hub_keys_matching($r->json, '/pass|hash|secret|pwd/i'), static fn (string $k): bool => !str_starts_with($k, '$.errors.')));
    assert_same([], $keys, $what . ': no password-like key');
    foreach ((array) ($r->json['errors'] ?? []) as $field => $reason) {
        assert_true(is_string($reason) && !str_contains($reason, '$2y$'), $what . ': errors.' . $field . ' is a plain reason text');
    }
    assert_true(!str_contains($r->body, '$2y$') && !str_contains($r->body, '$2a$'), $what . ': no bcrypt hash in the body');
}

function pw_assert_no_store(HttpResult $r, string $what): void
{
    $cc = strtolower((string) $r->header('Cache-Control'));
    assert_true(str_contains($cc, 'no-store'), $what . ': Cache-Control must contain no-store, got "' . $cc . '"');
}

function pw_hub_location(TestApp $app): int
{
    $r = $app->client('hq.admin')->get('/api/admin/locations');
    assert_success($r, 200, 'GET /api/admin/locations');
    $loc = hub_find(hub_list($r, 'locations', 'locations'), 'type', 'hub');
    assert_true($loc !== null, 'a hub location exists');
    return (int) $loc['id'];
}

function pw_log(TestApp $app): string
{
    return (string) @file_get_contents($app->server->logFile);
}

test('C6-01: POST /api/admin/users (201) returns no password anywhere and is sent with Cache-Control: no-store', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    [$core, $pw] = pw_make();
    $forms = pw_forms($core, $pw);
    $r = $hq->post('/api/admin/users', ['username' => 'once.user', 'email' => 'once.user@example.test', 'password' => $pw, 'location_id' => pw_hub_location($app)]);
    assert_success($r, 201, 'create account');
    pw_assert_response_clean($r, $forms, 'POST /api/admin/users 201');
    pw_assert_no_store($r, 'POST /api/admin/users 201');
    $user = hub_object($r, 'user', 'user');
    assert_same(['id', 'username', 'email', 'location', 'active', 'created_at'], array_keys($user), 'the user object has no extra (secret) fields');
});

test('C6-02: GET /api/admin/users never contains a password or hash, before and after accounts are created and changed', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    [$core, $pw] = pw_make();
    $forms = pw_forms($core, $pw);
    $created = $hq->post('/api/admin/users', ['username' => 'once.user', 'email' => 'once.user@example.test', 'password' => $pw, 'location_id' => pw_hub_location($app)]);
    assert_success($created, 201, 'create account');
    $id = (int) $created->json['user']['id'];

    $list = $hq->get('/api/admin/users');
    assert_success($list, 200, 'GET /api/admin/users');
    pw_assert_response_clean($list, $forms, 'GET /api/admin/users');
    pw_assert_no_store($list, 'GET /api/admin/users');
    foreach (hub_list($list, 'users', 'users') as $u) {
        assert_same(['id', 'username', 'email', 'location', 'active', 'created_at'], array_keys($u), 'user ' . ($u['username'] ?? '?') . ' has only the public fields');
    }

    foreach ([false, true] as $active) {
        $p = $hq->patch('/api/admin/users/' . $id, ['active' => $active]);
        assert_success($p, 200, 'PATCH active=' . json_encode($active));
        pw_assert_response_clean($p, $forms, 'PATCH /api/admin/users/{id}');
    }
});

test('C6-03: 422, 409, 400, 401 and 403 responses to a request that carried a password never echo it', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hub = $app->client('hub.user');
    $hubLocation = pw_hub_location($app);
    [$core, $pw] = pw_make();
    $forms = pw_forms($core, $pw);
    $good = ['username' => 'once.user', 'email' => 'once.user@example.test', 'password' => $pw, 'location_id' => $hubLocation];
    assert_success($hq->post('/api/admin/users', $good), 201, 'first create');

    $cases = [
        '409 duplicate username' => [$hq->post('/api/admin/users', $good), 409],
        '409 duplicate username, other case' => [$hq->post('/api/admin/users', ['username' => 'ONCE.USER'] + $good), 409],
        '422 bad email' => [$hq->post('/api/admin/users', ['username' => 'other.user', 'email' => 'not-an-email'] + $good), 422],
        '422 bad username' => [$hq->post('/api/admin/users', ['username' => 'bad name'] + $good), 422],
        '422 bad location' => [$hq->post('/api/admin/users', ['username' => 'other.user', 'location_id' => 999999] + $good), 422],
        '422 franchise location' => [$hq->post('/api/admin/users', ['username' => 'other.user', 'location_id' => $app->client('franchise.user')->locationId()] + $good), 422],
        '422 password too long (73 bytes)' => [$hq->post('/api/admin/users', ['username' => 'other.user', 'password' => $pw . str_repeat('x', 73)] + $good), 422],
        '422 password too short (11 bytes)' => [$hq->post('/api/admin/users', ['username' => 'other.user', 'password' => substr($pw, 0, 11)] + $good), 422],
        '422 password with NUL' => [$hq->post('/api/admin/users', ['username' => 'other.user', 'password' => $pw . "\0"] + $good), 422],
        '403 hub user' => [$hub->post('/api/admin/users', ['username' => 'other.user'] + $good), 403],
        '401 no token' => [$app->anon()->post('/api/admin/users', ['username' => 'other.user'] + $good), 401],
        '400 malformed JSON that holds the password' => [
            $app->request('POST', '/api/admin/users', '{"password":"' . substr((string) json_encode($pw), 1, -1) . '", "username": ', $hq->token),
            400,
        ],
    ];
    foreach ($cases as $label => [$r, $status]) {
        assert_status($status, $r, $label);
        pw_assert_response_clean($r, $forms, $label);
        pw_assert_no_store($r, $label);
    }
});

test('C6-04: if a per-user GET is ever added it must be password-free; today it is not routed', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    [$core, $pw] = pw_make();
    $forms = pw_forms($core, $pw);
    $created = $hq->post('/api/admin/users', ['username' => 'once.user', 'email' => 'once.user@example.test', 'password' => $pw, 'location_id' => pw_hub_location($app)]);
    assert_success($created, 201, 'create account');
    $r = $hq->get('/api/admin/users/' . (int) $created->json['user']['id']);
    assert_true(in_array($r->status, [200, 404, 405], true), 'GET one user answers 200 (if built) or 404/405: ' . $r->describe());
    pw_assert_response_clean($r, $forms, 'GET /api/admin/users/{id}');
});

test('C6-05: the server error log never holds the password (the HUB-AUDIT line is there as the probe); the database holds only a bcrypt hash', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hubLocation = pw_hub_location($app);
    [$core, $pw] = pw_make();
    $forms = pw_forms($core, $pw);
    $good = ['username' => 'once.user', 'email' => 'once.user@example.test', 'password' => $pw, 'location_id' => $hubLocation];

    $created = $hq->post('/api/admin/users', $good);
    assert_success($created, 201, 'create account');
    $hq->post('/api/admin/users', $good);                                                       // 409
    $hq->post('/api/admin/users', ['email' => 'not-an-email'] + $good);                         // 422
    $app->request('POST', '/api/admin/users', '{"password":"' . substr((string) json_encode($pw), 1, -1) . '", "username": ', $hq->token); // 400
    $app->request('POST', '/api/login', ['username' => 'once.user', 'email' => 'once.user@example.test', 'password' => $pw]);             // a real login with it

    $log = pw_log($app);
    assert_true(str_contains($log, 'HUB-AUDIT account_created user_id=' . (int) $created->json['user']['id']), 'probe: the log holds the HUB-AUDIT line, so an empty log cannot pass this test');
    pw_assert_absent($log, $forms, 'server error log');

    $pdo = new PDO('sqlite:' . $app->dbPath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll() as $t) {
        foreach ($pdo->query('SELECT * FROM "' . $t['name'] . '"')->fetchAll() as $row) {
            foreach ($row as $col => $value) {
                if (is_string($value)) {
                    pw_assert_absent($value, $forms, 'database ' . $t['name'] . '.' . $col);
                }
            }
        }
    }
    $hash = (string) $pdo->query("SELECT password_hash FROM users WHERE username = 'once.user'")->fetchColumn();
    assert_true(preg_match('/^\$2y\$\d\d\$/', $hash) === 1 && password_verify($pw, $hash), 'users.password_hash is a bcrypt hash of the password');
});

run_tests();
