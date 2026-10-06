<?php
declare(strict_types=1);

// M1-01 (SPEC s1, s6): hub login end to end. Role and location come from the token, never from a request field.

require __DIR__ . '/../../lib/harness.php';

test('M1-01: hub.user logs in as role hub at a hub location; hub2.user is a different hub location', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user')->me();
    $hub2 = $app->client('hub2.user')->me();

    assert_same('hub', $hub['role'] ?? null, 'hub.user role');
    assert_same('hub', $hub['location']['type'] ?? null, 'hub.user location type');
    assert_same('The Hub', $hub['location']['name'] ?? null, 'hub.user location name');
    assert_same('hub', $hub2['role'] ?? null, 'hub2.user role');
    assert_true(is_int($hub['location']['id'] ?? null) && $hub['location']['id'] !== ($hub2['location']['id'] ?? null), 'hub.user and hub2.user are different locations');

    $r = $app->request('POST', '/api/login', ['username' => 'hub.user', 'email' => 'hub.user@example.test', 'password' => 'wrong']);
    assert_error($r, 401, 'INVALID_CREDENTIALS', 'wrong password');
});

run_tests();
