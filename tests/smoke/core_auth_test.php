<?php
declare(strict_types=1);

// Smoke: core login contract (SPEC s3, s6). Black-box over HTTP plus a look at the test DB for token storage.

require __DIR__ . '/../lib/harness.php';

function core_db(TestApp $app): PDO
{
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

test('login needs username, email and password (422 with field errors)', function (): void {
    $app = TestApp::start();
    $r = $app->anon()->post('/api/login', []);
    assert_error($r, 422, 'VALIDATION_ERROR', 'empty login');
    foreach (['username', 'email', 'password'] as $field) {
        assert_true(isset($r->json['errors'][$field]), 'errors.' . $field . ' present; ' . $r->describe());
    }
    $r = $app->anon()->post('/api/login', ['username' => 'hub.user', 'password' => 'TestPass!Hub1']);
    assert_error($r, 422, 'VALIDATION_ERROR', 'login without email');
});

test('wrong username, email or password all give the same generic 401', function (): void {
    $app = TestApp::start();
    $good = ['username' => 'hub.user', 'email' => 'hub.user@example.test', 'password' => 'TestPass!Hub1'];
    $messages = [];
    foreach ([['username' => 'nobody'], ['email' => 'other@example.test'], ['password' => 'wrong']] as $change) {
        $r = $app->anon()->post('/api/login', array_merge($good, $change));
        assert_error($r, 401, 'INVALID_CREDENTIALS', 'login with wrong ' . array_key_first($change));
        assert_true(!isset($r->json['token']), 'no token on failure');
        $messages[] = $r->json['message'] ?? null;
    }
    assert_same(1, count(array_unique($messages)), 'one generic message for every cause');
    assert_success($app->anon()->post('/api/login', $good), 200, 'correct login');
});

test('malformed JSON body answers 400 BAD_JSON', function (): void {
    $app = TestApp::start();
    assert_error($app->request('POST', '/api/login', '{"username": '), 400, 'BAD_JSON', 'malformed JSON');
});

test('token: required, 64 hex, stored only as sha256, dead after logout', function (): void {
    $app = TestApp::start();
    assert_error($app->anon()->get('/api/me'), 401, 'UNAUTHENTICATED', 'no token');
    assert_error($app->request('GET', '/api/me', null, str_repeat('ab', 32)), 401, 'UNAUTHENTICATED', 'unknown token');

    $r = $app->anon()->post('/api/login', ['username' => 'hq.admin', 'email' => 'HQ.Admin@example.test', 'password' => 'TestPass!Hq1']);
    assert_success($r, 200, 'login (email is case-insensitive)');
    $token = (string) $r->json['token'];
    assert_true(preg_match('/^[0-9a-f]{64}$/', $token) === 1, 'token is 64 hex chars');
    assert_true(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) $r->json['expires_at']) === 1, 'expires_at is ISO 8601 with offset');
    assert_same('hq', $r->json['user']['role'] ?? null, 'login returns USER');

    $stored = core_db($app)->query('SELECT token_hash FROM auth_tokens')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array(hash('sha256', $token), $stored, true), 'sha256 of the token is stored');
    assert_true(!in_array($token, $stored, true), 'raw token is not stored');

    assert_success($app->request('GET', '/api/me', null, $token), 200, 'me with token');
    assert_success($app->request('POST', '/api/logout', [], $token), 200, 'logout');
    assert_error($app->request('GET', '/api/me', null, $token), 401, 'UNAUTHENTICATED', 'token reused after logout');
});

test('expired tokens and inactive users are refused', function (): void {
    $app = TestApp::start();
    $token = $app->login('hub.user');
    core_db($app)->exec("UPDATE auth_tokens SET expires_at = '2000-01-01 00:00:00'");
    assert_error($app->request('GET', '/api/me', null, $token), 401, 'UNAUTHENTICATED', 'expired token');

    core_db($app)->exec("UPDATE users SET active = 0 WHERE username = 'hub2.user'");
    $r = $app->anon()->post('/api/login', ['username' => 'hub2.user', 'email' => 'hub2.user@example.test', 'password' => 'TestPass!Hub2']);
    assert_error($r, 401, 'INVALID_CREDENTIALS', 'inactive user login');
});

run_tests();
