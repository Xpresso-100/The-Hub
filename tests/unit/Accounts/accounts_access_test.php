<?php
declare(strict_types=1);

// Lane C test 1 (build spec 2026-11-16 s8): every account endpoint is hq only.
// 401 without a token; 403 for hub and franchise (decided before the body is read); 200/201 for hq.

require __DIR__ . '/../../lib/harness.php';

function acc_access_body(int $locationId, string $username): array
{
    return [
        'username' => $username,
        'email' => $username . '@example.test',
        'password' => 'Long-enough-pass-1',
        'location_id' => $locationId,
    ];
}

function acc_access_user_count(TestApp $app): int
{
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

test('C1: 401 UNAUTHENTICATED without a token on every account endpoint', function (): void {
    $app = TestApp::start();
    $anon = $app->anon();
    assert_error($anon->get('/api/admin/locations'), 401, 'UNAUTHENTICATED', 'anon GET /api/admin/locations');
    assert_error($anon->get('/api/admin/users'), 401, 'UNAUTHENTICATED', 'anon GET /api/admin/users');
    assert_error($anon->post('/api/admin/users', acc_access_body(2, 'anon.try')), 401, 'UNAUTHENTICATED', 'anon POST /api/admin/users');
    assert_error($anon->patch('/api/admin/users/1', ['active' => false]), 401, 'UNAUTHENTICATED', 'anon PATCH /api/admin/users/1');
});

test('C1: 403 FORBIDDEN for hub.user and franchise.user on every account endpoint; nothing changes', function (): void {
    $app = TestApp::start();
    $hubLocation = $app->client('hub.user')->locationId();
    $hub2Id = (int) $app->client('hub2.user')->me()['id'];
    $before = acc_access_user_count($app);

    foreach (['hub.user', 'franchise.user'] as $who) {
        $c = $app->client($who);
        assert_error($c->get('/api/admin/locations'), 403, 'FORBIDDEN', $who . ' GET /api/admin/locations');
        assert_error($c->get('/api/admin/users'), 403, 'FORBIDDEN', $who . ' GET /api/admin/users');
        assert_error($c->post('/api/admin/users', acc_access_body($hubLocation, 'made.by.' . str_replace('.', '', $who))), 403, 'FORBIDDEN', $who . ' POST /api/admin/users');
        assert_error($c->patch('/api/admin/users/' . $hub2Id, ['active' => false]), 403, 'FORBIDDEN', $who . ' PATCH /api/admin/users/{hub2}');
        // The role is checked before the body: malformed JSON still answers 403, not 400.
        assert_error($app->request('POST', '/api/admin/users', '{not json', $c->token), 403, 'FORBIDDEN', $who . ' POST with a bad body');
        assert_error($app->request('PATCH', '/api/admin/users/' . $hub2Id, '{not json', $c->token), 403, 'FORBIDDEN', $who . ' PATCH with a bad body');
    }

    assert_same($before, acc_access_user_count($app), 'no user row was created by a refused request');
    $app->login('hub2.user'); // throws if hub2.user was deactivated
});

test('C1: hq.admin gets 200 on the lists, 201 on create and 200 on PATCH', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hubLocation = $app->client('hub.user')->locationId();

    assert_success($hq->get('/api/admin/locations'), 200, 'hq GET /api/admin/locations');
    assert_success($hq->get('/api/admin/users'), 200, 'hq GET /api/admin/users');
    $r = $hq->post('/api/admin/users', acc_access_body($hubLocation, 'access.check'));
    assert_success($r, 201, 'hq POST /api/admin/users');
    $id = $r->json['user']['id'] ?? null;
    assert_true(is_int($id), 'created user has an int id; ' . $r->describe());
    assert_success($hq->patch('/api/admin/users/' . $id, ['active' => true]), 200, 'hq PATCH /api/admin/users/{id}');
});

run_tests();
