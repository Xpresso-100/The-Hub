<?php
declare(strict_types=1);

// Lane C test 8 (build spec 2026-11-16 s8): accounts page and script are served and wired to app.js;
// relative API URLs; every API value in markup is escaped; the password never goes to browser storage,
// the URL or the console.

require __DIR__ . '/../../lib/harness.php';

test('C6 / test 8: accounts.html and assets/accounts.js are served with the required ids and API calls', function (): void {
    $app = TestApp::start();
    $page = $app->request('GET', '/accounts.html');
    assert_status(200, $page, 'GET /accounts.html');
    $needles = ['assets/app.css', 'assets/app.js', 'assets/accounts.js'];
    foreach (['hub-nav', 'acc-body', 'acc-form', 'acc-username', 'acc-email', 'acc-password', 'acc-location', 'acc-suggest', 'acc-save', 'acc-message'] as $id) {
        $needles[] = 'id="' . $id . '"';
    }
    foreach ($needles as $needle) {
        assert_true(str_contains($page->body, $needle), 'accounts.html contains ' . $needle);
    }

    $js = $app->request('GET', '/assets/accounts.js');
    assert_status(200, $js, 'GET /assets/accounts.js');
    foreach (["'api/admin/users'", "'api/admin/users/'", "'api/admin/locations'", "Hub.requireRole('hq')", 'Hub.renderNav', 'crypto.getRandomValues'] as $needle) {
        assert_true(str_contains($js->body, $needle), 'accounts.js contains ' . $needle);
    }
    assert_true(!str_contains($js->body, "'/api/"), 'relative API URLs only (sub-folder deploy)');
});

test('C6 / test 8: every API value concatenated into HTML goes through Hub.escapeHtml', function (): void {
    $lines = file(HUB_TEST_ROOT . '/public/assets/accounts.js', FILE_IGNORE_NEW_LINES) ?: [];
    assert_true($lines !== [], 'accounts.js exists and is not empty');
    $bad = [];
    foreach ($lines as $n => $line) {
        if (!str_contains($line, '<')) {
            continue; // only lines that build markup
        }
        // Same lint as tests/unit/Ordering/ordering_ui_test.php, plus this script's row variables (a, loc, acc, created).
        if (preg_match('/\+\s*(?!esc\()(it|o|l|d|u|r|s|a|loc|acc|created)\.[A-Za-z_]/', $line) === 1
            || preg_match('/\+\s*(s|id|i)\s*\+\s*\'"/', $line) === 1) {
            $bad[] = ($n + 1) . ': ' . trim($line);
        }
    }
    assert_same([], $bad, 'unescaped values in markup lines');
    $js = implode("\n", $lines);
    assert_true(!str_contains($js, 'eval(') && !str_contains($js, 'document.write'), 'no eval or document.write');
});

test('C6 / test 8: the password never goes to localStorage, sessionStorage, the URL or the console', function (): void {
    $js = (string) file_get_contents(HUB_TEST_ROOT . '/public/assets/accounts.js');
    assert_true(!str_contains($js, 'localStorage'), 'accounts.js does not touch localStorage');
    assert_true(!str_contains($js, 'sessionStorage'), 'accounts.js does not touch sessionStorage (the token is app.js business)');
    assert_true(!str_contains($js, 'console.'), 'accounts.js does not log to the console');
    assert_true(preg_match('/window\.location|location\.(href|search|hash)|history\.(push|replace)State|URLSearchParams/', $js) !== 1, 'accounts.js never writes to the URL');
    assert_true(!str_contains($js, 'innerHTML = password') && !str_contains($js, 'esc(password)'), 'the password is never placed into markup');
    $html = (string) file_get_contents(HUB_TEST_ROOT . '/public/accounts.html');
    assert_true(preg_match('/<form[^>]*method="get"/i', $html) !== 1 && !str_contains($html, ' action='), 'the form never submits itself (no GET form putting the password in the URL)');
});

run_tests();
