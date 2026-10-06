<?php
declare(strict_types=1);

// M2-06 (SPEC s8.7): public/recipes.html - recipe list, editor, record-batch form, inventory + adjustment.

require __DIR__ . '/../../lib/harness.php';

test('M2-06: recipes.html and recipes.js are served and carry every section', function (): void {
    $app = TestApp::start();
    $r = $app->request('GET', '/recipes.html');
    assert_status(200, $r, 'GET /recipes.html');
    foreach (['recipes-body', 'recipe-form', 'r-lines', 'batch-form', 'batch-result', 'inv-body', 'adj-form', 'assets/app.js', 'assets/recipes.js', 'hub-nav'] as $needle) {
        assert_true(str_contains($r->body, $needle), 'recipes.html contains ' . $needle);
    }
    $js = $app->request('GET', '/assets/recipes.js');
    assert_status(200, $js, 'GET /assets/recipes.js');
    foreach (["requireRole('hq', 'hub')", 'api/recipes', 'api/batches', 'api/inventory', 'api/inventory/adjustments', 'warnings'] as $needle) {
        assert_true(str_contains($js->body, $needle), 'recipes.js uses ' . $needle);
    }
});

test('M2-06: every server value concatenated into HTML in recipes.js goes through esc()', function (): void {
    $src = file_get_contents(HUB_TEST_ROOT . '/public/assets/recipes.js');
    $offenders = [];
    foreach (explode("\n", $src) as $n => $line) {
        if (!str_contains($line, "'<") && !str_contains($line, "+ '</")) {
            continue; // only lines that build markup
        }
        // drop every balanced esc(...) call; a bare property read (rc.name, l.sku, b.total_cost, i.unit, w.sku)
        // still joined with + after that is unescaped
        $outside = preg_replace('/esc(\((?:[^()]++|(?1))*\))/', 'ESCAPED', $line);
        if (preg_match('/\+\s*(?:rc|l|i|b|w|recipe)\.[a-z_]+\s*(?:\+|;|$)/', $outside) === 1) {
            $offenders[] = ($n + 1) . ': ' . trim($line);
        }
    }
    assert_same([], $offenders, 'unescaped values in markup lines');
});

run_tests();
