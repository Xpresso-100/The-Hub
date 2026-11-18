<?php
declare(strict_types=1);

// R3 red team (18 Nov 2026): POST /api/login must not reveal whether a username exists by its timing.
// Auth::attempt() hashes a throwaway password (password_hash = one bcrypt) AND verifies against it
// (password_verify = a second bcrypt) when the username is unknown, but runs only one bcrypt for a known
// username. So an unknown username answers about twice as slowly: a username oracle, even though the
// body is the same 401 INVALID_CREDENTIALS (src/Auth/Auth.php:34-35).
// Fixture data only (hq.admin from bin/seed.php --fixtures). Medians of interleaved samples.

require __DIR__ . '/../lib/harness.php';

function r3_time_login(TestApp $app, string $username): float
{
    $t = hrtime(true);
    $r = $app->request('POST', '/api/login', [
        'username' => $username,
        'email' => 'nobody@example.test',
        'password' => 'Wrong-password-123',
    ]);
    $elapsed = (hrtime(true) - $t) / 1e6;
    assert_error($r, 401, 'INVALID_CREDENTIALS', 'failed login for ' . $username);
    return $elapsed;
}

function r3_median(array $xs): float
{
    sort($xs);
    return $xs[intdiv(count($xs), 2)];
}

test('R3-SEC-01: an unknown username is not measurably slower than a known one at /api/login', function (): void {
    $app = TestApp::start();
    r3_time_login($app, 'hq.admin'); // warm up
    r3_time_login($app, 'no.such.user');
    $known = [];
    $unknown = [];
    for ($i = 0; $i < 9; $i++) {
        $known[] = r3_time_login($app, 'hq.admin');
        $unknown[] = r3_time_login($app, 'no.such.user.' . $i);
    }
    $k = r3_median($known);
    $u = r3_median($unknown);
    $ratio = $u / max($k, 0.001);
    assert_true($ratio < 1.4, sprintf(
        'unknown-username login median %.1f ms vs known-username %.1f ms (ratio %.2f, must be < 1.40): '
        . 'Auth::attempt runs two bcrypt operations for an unknown user and one for a known user',
        $u, $k, $ratio
    ));
});

run_tests();
