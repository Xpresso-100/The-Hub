<?php
declare(strict_types=1);

// Hardening item 2: failed-login limit on POST /api/login (src/Auth/Auth.php::attempt).
// Rule under test: 5 failed logins for one username within 15 minutes lock that username for 15 minutes, counted
// from the 5th failure. While locked, even the CORRECT credentials get the byte-identical response a wrong login
// gets (401 INVALID_CREDENTIALS, same message, same headers and body), so a lock cannot reveal that a username
// exists. Failures are tracked by username and time only (never an IP address).
//
// Time control: there is no clock override in src/Clock.php or the harness, and tests must not sleep 15 minutes.
// So the tests age the stored failures: they rewrite attempted_at in the table login_attempts (the coordinator
// creates it: a username-or-hash column plus attempted_at) by a fixed number of seconds, whatever its format
// (epoch seconds, epoch milliseconds, 'Y-m-d H:i:s' local time or ISO 8601). Only attempted_at is assumed.
// Fixture data only (users from bin/seed.php --fixtures; 203.0.113.9 is a documentation address).

require __DIR__ . '/../../lib/harness.php';

const LL_MESSAGE = 'Those login details are not valid.';
const LL_WRONG = 'Wrong-password-123';

function ll_login(TestApp $app, string $username, string $email, string $pass, array $headers = []): HttpResult
{
    return $app->request('POST', '/api/login', ['username' => $username, 'email' => $email, 'password' => $pass], null, $headers);
}

/** Login with the fixture user's own email and correct password. */
function ll_good(TestApp $app, string $fixtureUser): HttpResult
{
    $c = HUB_FIXTURE_USERS[$fixtureUser];
    return ll_login($app, $fixtureUser, $c['email'], $c['password']);
}

/** One failed login for $username (a fixture user's own email, wrong password). Must answer the usual 401. */
function ll_fail(TestApp $app, string $username, array $headers = []): HttpResult
{
    $email = HUB_FIXTURE_USERS[$username]['email'] ?? 'nobody@example.test';
    $r = ll_login($app, $username, $email, LL_WRONG, $headers);
    assert_error($r, 401, 'INVALID_CREDENTIALS', 'failed login for ' . $username);
    return $r;
}

function ll_fail_n(TestApp $app, string $username, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        ll_fail($app, $username);
    }
}

/** Status + headers (minus Date) + body: what "byte-identical response" means here. */
function ll_sig(HttpResult $r): array
{
    $headers = $r->headers;
    unset($headers['date']);
    ksort($headers);
    return ['status' => $r->status, 'headers' => $headers, 'body' => $r->body];
}

function ll_assert_refused_like(HttpResult $r, array $reference, string $what): void
{
    assert_error($r, 401, 'INVALID_CREDENTIALS', $what . ' must be refused as a wrong login');
    assert_same(LL_MESSAGE, $r->json['message'] ?? null, $what . ': message');
    assert_true(!array_key_exists('token', $r->json ?? []), $what . ': no token in the body');
    assert_same($reference, ll_sig($r), $what . ' must be byte-identical to a wrong login (status, headers, body)');
}

/** The reference: what a plain wrong login looks like (one failure for a user nobody else touches). */
function ll_reference(TestApp $app): array
{
    return ll_sig(ll_fail($app, 'franchise.user'));
}

function ll_pdo(TestApp $app): PDO
{
    $pdo = new PDO('sqlite:' . $app->dbPath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function ll_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

/** Ages every stored failure by $seconds (as if that much time had passed). */
function ll_age(TestApp $app, int $seconds): void
{
    $pdo = ll_pdo($app);
    assert_true(ll_table_exists($pdo, 'login_attempts'), 'the table login_attempts does not exist (failed logins must be stored there by username and time)');
    $cols = array_column($pdo->query('PRAGMA table_info(login_attempts)')->fetchAll(), 'name');
    assert_true(in_array('attempted_at', $cols, true), 'login_attempts has no attempted_at column; columns: ' . implode(',', $cols));
    $tz = new DateTimeZone('Africa/Johannesburg');
    $update = $pdo->prepare('UPDATE login_attempts SET attempted_at = ? WHERE rowid = ?');
    foreach ($pdo->query('SELECT rowid AS rid, attempted_at FROM login_attempts')->fetchAll() as $row) {
        $v = (string) $row['attempted_at'];
        if (preg_match('/^\d{13,}$/', $v) === 1) {
            $new = (string) ((int) $v - $seconds * 1000);
        } elseif (preg_match('/^\d{9,12}$/', $v) === 1) {
            $new = (string) ((int) $v - $seconds);
        } else {
            $dt = new DateTimeImmutable($v, $tz);
            $new = $dt->modify('-' . $seconds . ' seconds')->format(str_contains($v, 'T') ? DATE_ATOM : 'Y-m-d H:i:s');
        }
        $update->execute([$new, $row['rid']]);
    }
}

function ll_hq_hub_location(TestApp $app): int
{
    $r = $app->client('hq.admin')->get('/api/admin/locations');
    assert_success($r, 200, 'GET /api/admin/locations');
    $loc = hub_find(hub_list($r, 'locations', 'locations'), 'type', 'hub');
    assert_true($loc !== null, 'a hub location exists');
    return (int) $loc['id'];
}

test('L2-01: four failures do not lock (the correct login works); the fifth failure does (control)', function (): void {
    $app = TestApp::start();
    ll_fail_n($app, 'hub.user', 4);
    assert_success(ll_good($app, 'hub.user'), 200, 'correct login after 4 failures');

    // Control: without this the first half passes today for the wrong reason (nothing ever locks).
    $ref = ll_reference($app);
    ll_fail_n($app, 'hub2.user', 5);
    ll_assert_refused_like(ll_good($app, 'hub2.user'), $ref, 'correct login after 5 failures');
});

test('L2-02: 5 failures lock the username: the CORRECT email and password get the byte-identical wrong-login response', function (): void {
    $app = TestApp::start();
    $ref = ll_reference($app);
    ll_fail_n($app, 'hq.admin', 5);
    ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'correct credentials while locked');
    ll_assert_refused_like(ll_login($app, 'hq.admin', HUB_FIXTURE_USERS['hq.admin']['email'], LL_WRONG), $ref, 'wrong password while locked');
    ll_assert_refused_like(ll_login($app, 'hq.admin', 'someone.else@example.test', HUB_FIXTURE_USERS['hq.admin']['password']), $ref, 'right password, wrong email while locked');
    // The refused attempt must not have issued a session.
    $tokens = (int) ll_pdo($app)->query('SELECT COUNT(*) FROM auth_tokens')->fetchColumn();
    assert_same(0, $tokens, 'no auth token was issued to a locked login');
});

test('L2-03: an unknown username is tracked and locked the same way, with the same response', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hubLocation = ll_hq_hub_location($app);
    $ref = ll_reference($app);

    for ($i = 0; $i < 5; $i++) {
        ll_fail($app, 'ghost.user');
    }
    ll_assert_refused_like(ll_login($app, 'ghost.user', 'ghost.user@example.test', LL_WRONG), $ref, '6th attempt for an unknown username');

    // The lock belongs to the username, not to the account: if the account appears later, it is locked too.
    $fresh = 'Fx-' . bin2hex(random_bytes(6)) . '-Aa1';
    assert_success($hq->post('/api/admin/users', ['username' => 'ghost.user', 'email' => 'ghost.user@example.test', 'password' => $fresh, 'location_id' => $hubLocation]), 201, 'create ghost.user');
    ll_assert_refused_like(ll_login($app, 'ghost.user', 'ghost.user@example.test', $fresh), $ref, 'correct login for an account created after its username was locked');

    $pdo = ll_pdo($app);
    assert_true(ll_table_exists($pdo, 'login_attempts'), 'the table login_attempts does not exist');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() >= 5, 'failures for an unknown username are stored (otherwise a lock would only ever exist for real usernames)');
});

test('L2-04: failures spread over more than 15 minutes do not lock; failures inside 15 minutes do (control)', function (): void {
    $app = TestApp::start();
    $ref = ll_reference($app);
    // Control first: five quick failures lock (fails today on behaviour, not on the missing table).
    ll_fail_n($app, 'hq.admin', 5);
    ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'control: 5 quick failures');

    // 3 failures, then 15 min 20 s pass, then 2 more: only 2 are inside the window.
    ll_fail_n($app, 'hub.user', 3);
    ll_age($app, 15 * 60 + 20);
    ll_fail_n($app, 'hub.user', 2);
    assert_success(ll_good($app, 'hub.user'), 200, '3 failures 15 min 20 s ago + 2 now are not 5 within 15 minutes');

    // Same shape but the first 3 are only 14 minutes old: all 5 are inside the window.
    ll_fail_n($app, 'hub2.user', 3);
    ll_age($app, 14 * 60);
    ll_fail_n($app, 'hub2.user', 2);
    ll_assert_refused_like(ll_good($app, 'hub2.user'), $ref, '3 failures 14 minutes ago + 2 now are 5 within 15 minutes');
});

test('L2-05: the lock is still on 14 min 30 s after the 5th failure', function (): void {
    $app = TestApp::start();
    $ref = ll_reference($app);
    ll_fail_n($app, 'hq.admin', 5);
    ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'locked at once');
    ll_age($app, 14 * 60 + 30);
    ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'locked 14 min 30 s after the 5th failure (30 seconds left)');
});

test('L2-06: the lock is over 15 min 20 s after the 5th failure', function (): void {
    $app = TestApp::start();
    $ref = ll_reference($app);
    ll_fail_n($app, 'hq.admin', 5);
    ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'locked at once');
    ll_age($app, 15 * 60 + 20);
    assert_success(ll_good($app, 'hq.admin'), 200, 'correct login 15 min 20 s after the 5th failure');
});

test('L2-07: attempts made while locked do not extend the lock (it ends 15 minutes after the 5th failure)', function (): void {
    $app = TestApp::start();
    $ref = ll_reference($app);
    ll_fail_n($app, 'hq.admin', 5);
    ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'locked at once');
    ll_age($app, 14 * 60);
    // Six more attempts while the lock still has a minute left: wrong and correct, all refused.
    for ($i = 0; $i < 3; $i++) {
        ll_assert_refused_like(ll_login($app, 'hq.admin', HUB_FIXTURE_USERS['hq.admin']['email'], LL_WRONG), $ref, 'wrong attempt ' . $i . ' while locked');
        ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'correct attempt ' . $i . ' while locked');
    }
    // Now 15 min 20 s have passed since the 5th failure (and 80 s since the locked attempts).
    ll_age($app, 80);
    assert_success(ll_good($app, 'hq.admin'), 200, 'the lock ends 15 minutes after the 5th failure, not after the last locked attempt');
});

test('L2-08: a successful login clears the count', function (): void {
    $app = TestApp::start();
    $ref = ll_reference($app);
    ll_fail_n($app, 'hub.user', 4);
    assert_success(ll_good($app, 'hub.user'), 200, 'success after 4 failures');
    ll_fail_n($app, 'hub.user', 4); // 8 failures in total, but only 4 since the success
    assert_success(ll_good($app, 'hub.user'), 200, 'success after 4 more failures: the count restarted at the success');

    // Control: the count does still add up when nothing clears it.
    ll_fail_n($app, 'hub2.user', 5);
    ll_assert_refused_like(ll_good($app, 'hub2.user'), $ref, 'control: 5 failures with no success in between');
});

test('L2-09: locking one username does not lock another', function (): void {
    $app = TestApp::start();
    $ref = ll_reference($app);
    ll_fail_n($app, 'hq.admin', 5);
    ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'hq.admin is locked');
    foreach (['hub.user', 'hub2.user'] as $other) {
        assert_success(ll_good($app, $other), 200, $other . ' is not locked by hq.admin\'s failures');
    }
    // 2 failures for each of two other users add up per username, not together.
    ll_fail_n($app, 'hub.user', 2);
    ll_fail_n($app, 'hub2.user', 2);
    assert_success(ll_good($app, 'hub.user'), 200, 'hub.user after 2 failures');
    assert_success(ll_good($app, 'hub2.user'), 200, 'hub2.user after 2 failures');
});

test('L2-10: failures count per username whatever its capitalisation (MySQL matches usernames case-insensitively)', function (): void {
    // Stricter reading: on MySQL "HQ.Admin" logs in as hq.admin, so counting it separately would let an attacker
    // make unlimited guesses by varying case. Three failures as typed + two in upper case = five for one username.
    $app = TestApp::start();
    $ref = ll_reference($app);
    ll_fail_n($app, 'hq.admin', 3);
    for ($i = 0; $i < 2; $i++) {
        $r = ll_login($app, 'HQ.ADMIN', HUB_FIXTURE_USERS['hq.admin']['email'], LL_WRONG);
        assert_error($r, 401, 'INVALID_CREDENTIALS', 'failed login for HQ.ADMIN');
    }
    ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'hq.admin after 3 failures as typed + 2 as HQ.ADMIN');
});

test('L2-11: a locked login still verifies a password hash: no timing shortcut (median ratio < 1.4, like R3)', function (): void {
    $app = TestApp::start();
    $ref = ll_reference($app);
    ll_fail_n($app, 'hq.admin', 5);
    // Arm: it really is locked, otherwise the timings below compare two identical unlocked paths.
    ll_assert_refused_like(ll_good($app, 'hq.admin'), $ref, 'hq.admin is locked');

    $locked = static function () use ($app): float {
        $t = hrtime(true);
        $r = ll_good($app, 'hq.admin');
        $ms = (hrtime(true) - $t) / 1e6;
        assert_error($r, 401, 'INVALID_CREDENTIALS', 'locked login');
        return $ms;
    };
    $baseline = static function () use ($app): float { // one successful, unlocked login: exactly one bcrypt verify
        $t = hrtime(true);
        $r = ll_good($app, 'hub.user');
        $ms = (hrtime(true) - $t) / 1e6;
        assert_success($r, 200, 'baseline login');
        return $ms;
    };
    $locked();
    $baseline();
    $l = [];
    $b = [];
    for ($i = 0; $i < 9; $i++) {
        $l[] = $locked();
        $b[] = $baseline();
    }
    sort($l);
    sort($b);
    $ml = $l[4];
    $mb = $b[4];
    $ratio = max($mb / max($ml, 0.001), $ml / max($mb, 0.001));
    assert_true($ratio < 1.4, sprintf(
        'locked login median %.1f ms vs normal login median %.1f ms (ratio %.2f, must be < 1.40): '
        . 'the locked path must still run password_verify so a lock cannot be told from a wrong password by timing',
        $ml, $mb, $ratio
    ));
});

test('L2-12: failures are stored by username and time only: no IP address anywhere in the database', function (): void {
    $app = TestApp::start();
    $needles = ['203.0.113.9', '127.0.0.1']; // forged forwarding headers, and the real peer address of the test client
    $scan = static function () use ($app, $needles): array {
        $pdo = ll_pdo($app);
        $found = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll() as $t) {
            foreach ($pdo->query('SELECT * FROM "' . $t['name'] . '"')->fetchAll() as $row) {
                foreach ($row as $col => $value) {
                    foreach ($needles as $needle) {
                        if (is_string($value) && str_contains($value, $needle)) {
                            $key = $t['name'] . '.' . $col . ' contains ' . $needle;
                            $found[$key] = ($found[$key] ?? 0) + 1;
                        }
                    }
                }
            }
        }
        return $found;
    };
    $before = $scan();

    $forged = [
        'X-Forwarded-For' => '203.0.113.9',
        'X-Real-IP' => '203.0.113.9',
        'Client-IP' => '203.0.113.9',
        'Forwarded' => 'for=203.0.113.9',
        'REMOTE_ADDR' => '203.0.113.9',
    ];
    for ($i = 0; $i < 5; $i++) {
        ll_fail($app, 'hq.admin', $forged);
    }

    $pdo = ll_pdo($app);
    assert_true(ll_table_exists($pdo, 'login_attempts'), 'the table login_attempts does not exist (failed logins must be stored there by username and time)');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() >= 5, 'login_attempts holds the 5 failures');
    $cols = array_column($pdo->query('PRAGMA table_info(login_attempts)')->fetchAll(), 'name');
    assert_true(in_array('attempted_at', $cols, true), 'login_attempts has an attempted_at column; columns: ' . implode(',', $cols));
    foreach ($cols as $col) {
        assert_true(preg_match('/(^|_)ip(_|$)|addr|remote|forward|client/i', (string) $col) !== 1, 'login_attempts column "' . $col . '" looks like it stores an address');
    }
    assert_same($before, $scan(), 'no table row may contain the client address after failed logins (new matches)');
});

run_tests();
