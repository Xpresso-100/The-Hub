<?php
declare(strict_types=1);

// Lane B (BUILD-SPEC 2026-11-16 s7) tests 1 and 2: ingredients.html and ingredients.js are served,
// escape their output, use relative URLs, and send the cost as a JSON number.

require __DIR__ . '/../../lib/harness.php';

test('LB-1: ingredients.html and assets/ingredients.js are served with the required ids and API calls', function (): void {
    $app = TestApp::start();
    $page = $app->request('GET', '/ingredients.html');
    assert_status(200, $page, 'GET /ingredients.html');
    $needles = ['assets/app.js', 'assets/ingredients.js'];
    foreach (['hub-nav', 'ing-body', 'ing-form', 'ing-sku', 'ing-name', 'ing-unit', 'ing-cost', 'ing-fincon', 'ing-active', 'ing-save', 'ing-message', 'ing-show-inactive'] as $id) {
        $needles[] = 'id="' . $id . '"';
    }
    foreach ($needles as $needle) {
        assert_true(str_contains($page->body, $needle), 'ingredients.html contains ' . $needle);
    }
    assert_true(str_contains($page->body, 'Sample ingredients cannot be ordered in production'), 'ingredients.html shows the sample note');
    $js = $app->request('GET', '/assets/ingredients.js');
    assert_status(200, $js, 'GET /assets/ingredients.js');
    assert_true(str_contains($js->body, "'api/ingredients"), "ingredients.js calls 'api/ingredients");
    assert_true(str_contains($js->body, 'include_inactive=1'), 'ingredients.js asks for include_inactive=1');
    assert_true(str_contains($js->body, "'api/admin/ingredients/'"), "ingredients.js calls 'api/admin/ingredients/'");
    assert_true(str_contains($js->body, "'PUT'"), 'ingredients.js uses PUT');
    assert_true(!str_contains($js->body, "'/api/") && !str_contains($js->body, '"/api/'), 'relative API URLs only (sub-folder deploy)');
    assert_true(!str_contains($page->body, 'href="/') && !str_contains($page->body, 'src="/'), 'relative asset URLs only');
});

test('LB-1: every API value concatenated into HTML goes through Hub.escapeHtml; no eval', function (): void {
    $lines = file(HUB_TEST_ROOT . '/public/assets/ingredients.js', FILE_IGNORE_NEW_LINES) ?: [];
    assert_true($lines !== [], 'ingredients.js is readable and not empty');
    $bad = [];
    foreach ($lines as $n => $line) {
        if (!str_contains($line, '<')) {
            continue; // only lines that build markup
        }
        if (preg_match('/\+\s*(?!esc\()(it|o|l|d|u|r|s|row|ing|e)\.[A-Za-z_]/', $line) === 1 || preg_match('/\+\s*(s|id|i)\s*\+\s*\'"/', $line) === 1) {
            $bad[] = ($n + 1) . ': ' . trim($line);
        }
    }
    assert_same([], $bad, 'unescaped values in markup lines');
    $js = implode("\n", $lines);
    assert_true(str_contains($js, 'Hub.escapeHtml'), 'ingredients.js uses Hub.escapeHtml');
    assert_true(!str_contains($js, 'eval(') && !str_contains($js, 'document.write'), 'no eval or document.write');
    assert_true(str_contains($js, "Hub.requireRole('hq')"), "page is gated with Hub.requireRole('hq')");
});

test('LB-2: ingredients.js sends cost_price as a number built with Number(), after the two-decimal check', function (): void {
    $js = (string) file_get_contents(HUB_TEST_ROOT . '/public/assets/ingredients.js');
    assert_true(str_contains($js, 'Number('), 'source contains the Number( conversion');
    assert_true(str_contains($js, '/^\d{1,9}(\.\d{1,2})?$/'), 'source contains the two-decimal regex');
    // The only place cost_price is assigned must take the converted value (costNumber), which is built by Number(...).
    preg_match_all('/cost_price\s*:\s*([^,}\s]+)/', $js, $m);
    assert_true($m[1] !== [], 'cost_price is assigned somewhere');
    foreach ($m[1] as $value) {
        assert_same('costNumber', $value, 'cost_price must be assigned from costNumber only');
    }
    assert_true(preg_match('/costNumber\s*=\s*Number\(/', $js) === 1, 'costNumber is built by Number(');
    assert_true(preg_match('/cost_price\s*:\s*(String\(|[\'"`])/', $js) !== 1, 'cost_price is never a string');
    assert_true(preg_match('/cost_price\s*:[^\n]*toFixed\(/', $js) !== 1, 'the cost_price assignment does not use toFixed (it returns a string)');
});

run_tests();
