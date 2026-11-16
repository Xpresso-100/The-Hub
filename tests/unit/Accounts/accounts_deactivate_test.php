<?php
declare(strict_types=1);

// Lane C test 7 (build spec 2026-11-16 s8): PATCH /api/admin/users/{id} deactivates and reactivates.
// Deactivating ends every session of that user at once and blocks new logins; hq cannot deactivate itself.

require __DIR__ . '/../../lib/harness.php';

function acc_token_count(TestApp $app, int $userId): int
{
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

test('C5 / test 7: deactivate ends the session and blocks login; reactivate restores login', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hubLocation = $app->client('hub.user')->locationId();
    $creds = [
        'username' => 'temp.staff',
        'email' => 'temp.staff@example.test',
        'password' => 'Test-temp-' . str_repeat('z', 8),
    ];
    $r = $hq->post('/api/admin/users', $creds + ['location_id' => $hubLocation]);
    assert_success($r, 201, 'create temp.staff');
    $id = (int) $r->json['user']['id'];

    $login = $app->request('POST', '/api/login', $creds);
    assert_success($login, 200, 'temp.staff logs in');
    $token = (string) ($login->json['token'] ?? '');
    assert_success($app->request('GET', '/api/me', null, $token), 200, 'token works before deactivation');
    assert_true(acc_token_count($app, $id) >= 1, 'a token row exists before deactivation');

    $r = $hq->patch('/api/admin/users/' . $id, ['active' => false]);
    assert_success($r, 200, 'PATCH active=false');
    assert_same($id, $r->json['user']['id'] ?? null, 'same user returned');
    assert_same(false, $r->json['user']['active'] ?? null, 'user.active after deactivation');
    assert_same([], hub_keys_matching($r->json, '/password|hash/i'), 'no password or hash key in the PATCH response');

    assert_error($app->request('GET', '/api/me', null, $token), 401, 'UNAUTHENTICATED', 'old token after deactivation');
    assert_same(0, acc_token_count($app, $id), 'auth_tokens rows of the user deleted');
    assert_error($app->request('POST', '/api/login', $creds), 401, 'INVALID_CREDENTIALS', 'login while deactivated');

    $listed = hub_find(hub_list($hq->get('/api/admin/users'), 'users', 'GET /api/admin/users'), 'id', $id);
    assert_same(false, $listed['active'] ?? null, 'the list shows the user as deactivated');

    $r = $hq->patch('/api/admin/users/' . $id, ['active' => true]);
    assert_success($r, 200, 'PATCH active=true');
    assert_same(true, $r->json['user']['active'] ?? null, 'user.active after reactivation');
    assert_success($app->request('POST', '/api/login', $creds), 200, 'login works again after reactivation');
});

test('C5 / test 7: hq cannot deactivate itself (409); unknown id 404; active must be a boolean (422)', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $selfId = (int) $hq->me()['id'];

    assert_error($hq->patch('/api/admin/users/' . $selfId, ['active' => false]), 409, 'CONFLICT', 'self-deactivate');
    assert_success($hq->get('/api/me'), 200, 'hq.admin is still logged in after the refused self-deactivate');
    assert_success($hq->patch('/api/admin/users/' . $selfId, ['active' => true]), 200, 'self PATCH active=true is a harmless no-op');

    assert_error($hq->patch('/api/admin/users/999999', ['active' => false]), 404, 'NOT_FOUND', 'unknown user id');

    $hubId = (int) $app->client('hub.user')->me()['id'];
    foreach ([[], ['active' => 'false'], ['active' => 0], ['active' => null]] as $body) {
        $r = $hq->patch('/api/admin/users/' . $hubId, $body);
        assert_error($r, 422, 'VALIDATION_ERROR', 'PATCH body ' . hub_export($body));
        assert_true(isset($r->json['errors']['active']), 'errors.active present; ' . $r->describe());
    }
    $app->login('hub.user'); // throws if a refused PATCH deactivated hub.user
});

run_tests();
