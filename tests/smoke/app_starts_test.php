<?php
declare(strict_types=1);

// Smoke: the app starts from empty (migrate + seed --fixtures + php -S) and answers (SPEC s2.6, s6).

require __DIR__ . '/../lib/harness.php';

test('a fresh app starts and GET /api/health answers 200 ok', function (): void {
    $app = TestApp::start();
    $r = $app->anon()->get('/api/health');
    assert_success($r, 200, 'GET /api/health');
    assert_same('ok', $r->json['status'] ?? null, 'health status');
    assert_true(str_starts_with((string) $r->header('Content-Type'), 'application/json'), 'JSON content type, got ' . $r->header('Content-Type'));
    assert_same('no-store', $r->header('Cache-Control'), 'Cache-Control header');
    assert_same('nosniff', $r->header('X-Content-Type-Options'), 'X-Content-Type-Options header');
});

test('landing page, login page and shared assets are served', function (): void {
    $app = TestApp::start();
    foreach (['/', '/index.html', '/login.html', '/assets/app.js', '/assets/app.css'] as $path) {
        $r = $app->request('GET', $path);
        assert_status(200, $r, 'GET ' . $path);
        assert_true(strlen($r->body) > 50, 'GET ' . $path . ' has content');
    }
    assert_true(str_contains($app->request('GET', '/login.html')->body, 'api/login'), 'login page posts to api/login');
});

test('every fixture user logs in and /api/me reports role = location type', function (): void {
    $app = TestApp::start();
    $locations = [];
    foreach (HUB_FIXTURE_USERS as $username => $creds) {
        $user = $app->client($username)->me();
        assert_same($username, $user['username'] ?? null, 'me.username');
        assert_same($creds['role'], $user['role'] ?? null, 'me.role for ' . $username);
        assert_same($creds['role'], $user['location']['type'] ?? null, 'me.location.type for ' . $username);
        assert_true(is_int($user['id'] ?? null) && is_int($user['location']['id'] ?? null), 'ids are integers');
        $locations[$username] = $user['location']['id'];
    }
    assert_true($locations['hub.user'] !== $locations['hub2.user'], 'hub.user and hub2.user are in different locations');
});

test('unknown route answers 404 JSON, wrong method answers 405', function (): void {
    $app = TestApp::start();
    assert_error($app->anon()->get('/api/no-such-route'), 404, 'NOT_FOUND', 'unknown route');
    assert_error($app->anon()->delete('/api/health'), 405, 'METHOD_NOT_ALLOWED', 'DELETE /api/health');
    assert_error($app->request('GET', '/db/schema.sqlite.sql'), 404, 'NOT_FOUND', 'files outside public/ are not served');
});

run_tests();
