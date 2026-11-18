<?php
declare(strict_types=1);

// Lane B (build spec 2026-11-18 s5): tables on the ingredients, warehouse, stock and accounts pages scroll
// sideways on a phone instead of breaking the layout, and the warehouse empty state shows once.

require __DIR__ . '/../../lib/harness.php';

/** Every '<table' in $source must be directly preceded by <div class="table-scroll"> (whitespace and quotes tolerated). */
function unwrapped_tables(string $source): array
{
    $bad = [];
    $offset = 0;
    while (($pos = strpos($source, '<table', $offset)) !== false) {
        $before = substr($source, max(0, $pos - 80), min(80, $pos));
        if (preg_match('/<div\s+class=["\']table-scroll["\']\s*>[\s\'"+]*$/', $before) !== 1) {
            $bad[] = 'offset ' . $pos . ': ' . trim(substr($source, $pos, 40));
        }
        $offset = $pos + 6;
    }
    return $bad;
}

test('MT-1: every table in the four pages HTML sits inside a table-scroll wrapper', function (): void {
    foreach (['ingredients', 'warehouse', 'stock', 'accounts'] as $page) {
        $html = (string) file_get_contents(HUB_TEST_ROOT . '/public/' . $page . '.html');
        assert_true(str_contains($html, '<table'), $page . '.html has a table');
        assert_same([], unwrapped_tables($html), $page . '.html tables outside .table-scroll');
    }
});

test('MT-2: every table string built in the four pages JS sits inside a table-scroll wrapper', function (): void {
    foreach (['ingredients', 'warehouse', 'stock', 'accounts'] as $name) {
        $path = HUB_TEST_ROOT . '/public/assets/' . $name . '.js';
        assert_true(is_file($path), $name . '.js exists');
        $js = (string) file_get_contents($path);
        assert_same([], unwrapped_tables($js), $name . '.js tables outside .table-scroll');
    }
});

test('MT-3: app.css defines .table-scroll with horizontal scroll and touch momentum', function (): void {
    $css = (string) file_get_contents(HUB_TEST_ROOT . '/public/assets/app.css');
    assert_true(
        preg_match('/\.table-scroll\s*\{\s*overflow-x\s*:\s*auto\s*;\s*-webkit-overflow-scrolling\s*:\s*touch\s*;?\s*\}/', $css) === 1,
        'app.css has .table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}'
    );
});

test('MT-4: warehouse.js contains the literal "Nothing waiting." exactly once', function (): void {
    $js = (string) file_get_contents(HUB_TEST_ROOT . '/public/assets/warehouse.js');
    assert_same(1, substr_count($js, 'Nothing waiting.'), 'occurrences of "Nothing waiting." in warehouse.js');
});

run_tests();
