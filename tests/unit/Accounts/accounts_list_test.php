<?php
declare(strict_types=1);

// Lane C test 6 (build spec 2026-11-16 s8): GET /api/admin/users lists users by id and never carries a
// password hash, under any key or in any value.

require __DIR__ . '/../../lib/harness.php';

test('C4 / test 6: the user list is ordered by id, has the agreed keys, and never contains a hash', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hubLocation = $app->client('hub.user')->locationId();
    $created = $hq->post('/api/admin/users', [
        'username' => 'listed.user',
        'email' => 'listed.user@example.test',
        'password' => 'Test-list-' . str_repeat('y', 8),
        'location_id' => $hubLocation,
    ]);
    assert_success($created, 201, 'POST /api/admin/users');

    $r = $hq->get('/api/admin/users');
    assert_success($r, 200, 'GET /api/admin/users');
    $users = hub_list($r, 'users', 'GET /api/admin/users');

    $names = hub_column($users, 'username');
    foreach (['hq.admin', 'hub.user', 'hub2.user', 'franchise.user', 'listed.user'] as $expected) {
        assert_true(in_array($expected, $names, true), $expected . ' is listed; ' . $r->describe());
    }
    $ids = hub_column($users, 'id');
    $sorted = $ids;
    sort($sorted, SORT_NUMERIC);
    assert_same($sorted, $ids, 'ordered by id');

    foreach ($users as $u) {
        assert_same(['id', 'username', 'email', 'location', 'active', 'created_at'], array_keys($u), 'user keys');
        assert_same(['id', 'name', 'type'], array_keys($u['location']), 'user.location keys');
        assert_true(is_int($u['id']) && is_bool($u['active']), 'id is int, active is bool: ' . hub_export($u));
    }
    $listed = hub_find($users, 'username', 'listed.user');
    assert_same('listed.user@example.test', $listed['email'] ?? null, 'listed.user email');
    assert_same(true, $listed['active'] ?? null, 'listed.user active');

    foreach (['GET /api/admin/users' => $r, 'POST /api/admin/users' => $created, 'GET /api/admin/locations' => $hq->get('/api/admin/locations')] as $label => $res) {
        assert_same([], hub_keys_matching($res->json, '/password|hash/i'), $label . ': no password or hash key');
        foreach (['$2y$', '$2a$', '$2b$'] as $prefix) {
            assert_true(!str_contains($res->body, $prefix), $label . ': no bcrypt value (' . $prefix . ') anywhere in the body');
        }
    }
});

run_tests();
