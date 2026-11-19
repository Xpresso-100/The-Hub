<?php
declare(strict_types=1);

// Lane D (build spec 8b): the Hub stock page. Page and script served, no money words in the script,
// a stock count over HTTP shows up on GET api/inventory, and franchise stays locked out.

require __DIR__ . '/../../lib/harness.php';

test('D-01: stock.html and assets/stock.js are served, wired, relative and escaped', function (): void {
    $app = TestApp::start();
    $page = $app->request('GET', '/stock.html');
    assert_status(200, $page, 'GET /stock.html');
    $needles = ['assets/app.js', 'assets/stock.js', 'Hub stock', 'On hand = deliveries from head office plus or minus stock counts.'];
    foreach (['hub-nav', 'stock-body', 'stock-location', 'stock-meta', 'adj-form', 'adj-sku', 'adj-qty', 'adj-reason', 'adj-save', 'stock-message'] as $id) {
        $needles[] = 'id="' . $id . '"';
    }
    foreach ($needles as $needle) {
        assert_true(str_contains($page->body, $needle), 'stock.html contains ' . $needle);
    }
    assert_true(str_contains($page->body, 'maxlength="200"'), 'reason input is limited to 200 characters');
    assert_true(str_contains($page->body, 'placeholder="opening balance, stock count"'), 'reason placeholder');

    $js = $app->request('GET', '/assets/stock.js');
    assert_status(200, $js, 'GET /assets/stock.js');
    foreach (["'api/inventory'", "'api/inventory/adjustments'", "Hub.requireRole('hq', 'hub')", 'Hub.escapeHtml', 'more used than received'] as $needle) {
        assert_true(str_contains($js->body, $needle), 'stock.js contains ' . $needle);
    }
    assert_true(!str_contains($js->body, "'/api/"), 'relative API URLs only (sub-folder deploy)');
    assert_true(!str_contains($js->body, '"/api/'), 'relative API URLs only (double quotes)');

    $lines = file(HUB_TEST_ROOT . '/public/assets/stock.js', FILE_IGNORE_NEW_LINES) ?: [];
    $bad = [];
    foreach ($lines as $n => $line) {
        if (!str_contains($line, '<')) {
            continue; // only lines that build markup
        }
        if (preg_match('/\+\s*(?!esc\()(it|o|l|d|u|r|s)\.[A-Za-z_]/', $line) === 1 || preg_match('/\+\s*(s|id|i)\s*\+\s*\'"/', $line) === 1) {
            $bad[] = ($n + 1) . ': ' . trim($line);
        }
    }
    assert_same([], $bad, 'unescaped values in markup lines');
    $source = implode("\n", $lines);
    assert_true(!str_contains($source, 'eval(') && !str_contains($source, 'document.write'), 'no eval or document.write');
});

test('D-02: stock.js source has no cost, price or total token (any case)', function (): void {
    $source = (string) file_get_contents(HUB_TEST_ROOT . '/public/assets/stock.js');
    assert_true($source !== '', 'stock.js exists and is not empty');
    foreach (['cost', 'price', 'total'] as $word) {
        assert_same(0, preg_match('/' . $word . '/i', $source), 'stock.js must not contain "' . $word . '"');
    }
    $page = (string) file_get_contents(HUB_TEST_ROOT . '/public/stock.html');
    foreach (['cost', 'price', 'total'] as $word) {
        assert_same(0, preg_match('/' . $word . '/i', $page), 'stock.html must not contain "' . $word . '"');
    }
});

test('D-03: hub.user stock count of +5 shows as on hand 5 on GET api/inventory', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $items = hub_list($hub->get('/api/inventory'), 'items', 'inventory list');
    assert_true($items !== [], 'at least one ingredient is listed');
    $sku = hub_find($items, 'sku', 'SMP001') !== null ? 'SMP001' : (string) $items[0]['sku'];
    assert_qty(0, hub_find($items, 'sku', $sku)['on_hand'], $sku . ' starts at 0');

    $r = $hub->post('/api/inventory/adjustments', ['sku' => $sku, 'qty_delta' => 5, 'reason' => 'opening balance']);
    assert_success($r, 201, 'adjust +5');
    assert_qty(5, $r->json['on_hand'], 'on_hand in the 201 body');

    $r = $hub->get('/api/inventory');
    assert_success($r, 200, 'GET api/inventory after the count');
    $row = hub_find(hub_list($r, 'items', 'inventory list'), 'sku', $sku);
    assert_true($row !== null, $sku . ' is listed');
    assert_qty(5, $row['on_hand'], $sku . ' on hand after +5');
    assert_same(['sku', 'name', 'unit', 'on_hand'], array_keys($row), 'item keys carry no money field');
});

test('D-04: franchise.user gets 403 on GET api/inventory and POST api/inventory/adjustments', function (): void {
    $app = TestApp::start();
    $fr = $app->client('franchise.user');
    assert_error($fr->get('/api/inventory'), 403, 'FORBIDDEN', 'franchise GET api/inventory');
    assert_error(
        $fr->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 5, 'reason' => 'opening balance']),
        403,
        'FORBIDDEN',
        'franchise POST api/inventory/adjustments'
    );
});

run_tests();
