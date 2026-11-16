<?php
declare(strict_types=1);

// Lane A test 8 (build spec 2026-11-16 s6): warehouse page and script are served, wired and escaped.
// Escape lint copied from tests/unit/Ordering/ordering_ui_test.php.

require __DIR__ . '/../../lib/harness.php';

test('WH-8: warehouse.html, warehouse.js and warehouse.css are served with the required ids and calls', function (): void {
    $app = TestApp::start();
    $page = $app->request('GET', '/warehouse.html');
    assert_status(200, $page, 'GET /warehouse.html');
    $needles = ['assets/app.css', 'assets/warehouse.css', 'assets/app.js', 'assets/warehouse.js'];
    foreach (['hub-nav', 'queue-counts', 'queue-meta', 'picklist-body', 'queue-orders', 'queue-message', 'refresh-btn', 'print-btn'] as $id) {
        $needles[] = 'id="' . $id . '"';
    }
    foreach ($needles as $needle) {
        assert_true(str_contains($page->body, $needle), 'warehouse.html contains ' . $needle);
    }
    assert_true(strpos($page->body, 'assets/app.js') < strpos($page->body, 'assets/warehouse.js'), 'app.js loads before warehouse.js');

    $js = $app->request('GET', '/assets/warehouse.js');
    assert_status(200, $js, 'GET /assets/warehouse.js');
    foreach (["'api/warehouse/queue'", "'api/orders/'", '/status', "Hub.requireRole('hq')", 'Hub.renderNav', 'Hub.escapeHtml'] as $needle) {
        assert_true(str_contains($js->body, $needle), 'warehouse.js contains ' . $needle);
    }
    assert_true(!str_contains($js->body, "'/api/") && !str_contains($js->body, '"/api/'), 'relative API URLs only (sub-folder deploy)');

    $css = $app->request('GET', '/assets/warehouse.css');
    assert_status(200, $css, 'GET /assets/warehouse.css');
    assert_true(str_contains($css->body, '@media print'), 'warehouse.css has an @media print rule');
});

test('WH-8: forward buttons, delivered confirm, 409 text, refresh, title and print are wired', function (): void {
    $js = (string) file_get_contents(HUB_TEST_ROOT . '/public/assets/warehouse.js');
    foreach ([
        'Start picking', 'Out for delivery', 'Mark delivered',
        "'in_process'", "'out_for_delivery'", "'delivered'",
        'window.confirm', 'This adds the items to the Hub', 'cannot be undone',
        '409', 'Someone else changed this order. Refreshing.',
        'visibilitychange', 'document.hidden', '60000', 'Last updated',
        'document.title', 'Warehouse queue', 'window.print', 'Nothing waiting.', 'none given',
    ] as $needle) {
        assert_true(str_contains($js, $needle), 'warehouse.js contains ' . $needle);
    }
    assert_true(!str_contains($js, "'cancelled'"), 'the queue page never cancels an order');
    assert_true(preg_match('/cost|price|total/i', $js) !== 1, 'warehouse.js shows no money field');
});

test('WH-8: every API value concatenated into HTML goes through Hub.escapeHtml', function (): void {
    $lines = file(HUB_TEST_ROOT . '/public/assets/warehouse.js', FILE_IGNORE_NEW_LINES) ?: [];
    assert_true(count($lines) > 0, 'warehouse.js exists');
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
    assert_true(!str_contains($js, 'outerHTML') && !str_contains($js, 'insertAdjacentHTML'), 'markup only through innerHTML with escaped values');
});

run_tests();
