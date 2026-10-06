<?php
declare(strict_types=1);

// M1-09 (SPEC s7.8): ordering page and script are served; the script escapes what it puts into HTML.

require __DIR__ . '/../../lib/harness.php';

test('M1-09: ordering.html and assets/ordering.js are served and wired to app.js', function (): void {
    $app = TestApp::start();
    $page = $app->request('GET', '/ordering.html');
    assert_status(200, $page, 'GET /ordering.html');
    foreach (['assets/app.js', 'assets/ordering.js', 'id="hub-nav"', 'id="stock-body"', 'id="order-form"', 'id="orders-body"'] as $needle) {
        assert_true(str_contains($page->body, $needle), 'ordering.html contains ' . $needle);
    }
    $js = $app->request('GET', '/assets/ordering.js');
    assert_status(200, $js, 'GET /assets/ordering.js');
    foreach (["'api/stock'", "'api/ingredients'", "'api/orders'", '/status'] as $needle) {
        assert_true(str_contains($js->body, $needle), 'ordering.js calls ' . $needle);
    }
    assert_true(!str_contains($js->body, "'/api/"), 'relative API URLs only (sub-folder deploy)');
});

test('M1-09: every API value concatenated into HTML goes through Hub.escapeHtml', function (): void {
    $lines = file(HUB_TEST_ROOT . '/public/assets/ordering.js', FILE_IGNORE_NEW_LINES) ?: [];
    $bad = [];
    foreach ($lines as $n => $line) {
        if (!str_contains($line, '<')) {
            continue; // only lines that build markup
        }
        // A bare data value after "+" (it.x, o.x, l.x, d.x, u.x, r.data...) without esc( around it.
        if (preg_match('/\+\s*(?!esc\()(it|o|l|d|u|r|s)\.[A-Za-z_]/', $line) === 1 || preg_match('/\+\s*(s|id|i)\s*\+\s*\'"/', $line) === 1) {
            $bad[] = ($n + 1) . ': ' . trim($line);
        }
    }
    assert_same([], $bad, 'unescaped values in markup lines');
    $js = implode("\n", $lines);
    assert_true(!str_contains($js, 'eval(') && !str_contains($js, 'document.write'), 'no eval or document.write');
});

run_tests();
