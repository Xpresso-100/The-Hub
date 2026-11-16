<?php
declare(strict_types=1);

// N1 (BUILD-SPEC 2026-11-16 s9 step 3): recipes and reports are unlinked from the menu and the home tiles;
// the new live-ops pages are linked, each for the roles the spec gives it.

require __DIR__ . '/../../lib/harness.php';

/** The new pages that must be linked, with the roles that must see them. */
function nav_expected_pages(): array
{
    return [
        'stock.html' => ['hq', 'hub'],
        'warehouse.html' => ['hq'],
        'ingredients.html' => ['hq'],
        'accounts.html' => ['hq'],
    ];
}

/** Each `{ href: '<page>', ... roles: [...] }` entry in a JS list, as page => roles. */
function nav_entries(string $source): array
{
    $out = [];
    preg_match_all("/\\{\\s*href:\\s*'([^']+)'[^}]*roles:\\s*\\[([^\\]]*)\\]/", $source, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        preg_match_all("/'([a-z]+)'/", $hit[2], $roles);
        $out[$hit[1]][] = $roles[1];
    }
    return $out;
}

test('N1: app.js LINKS and index.html TILES no longer link recipes.html or reports.html', function (): void {
    foreach (['/public/assets/app.js', '/public/index.html'] as $file) {
        $src = (string) file_get_contents(HUB_TEST_ROOT . $file);
        assert_true(!str_contains($src, 'recipes.html'), $file . ' does not contain recipes.html');
        assert_true(!str_contains($src, 'reports.html'), $file . ' does not contain reports.html');
    }
});

test('N1: app.js LINKS is exactly the six spec entries, in order, with the spec roles', function (): void {
    $src = (string) file_get_contents(HUB_TEST_ROOT . '/public/assets/app.js');
    $entries = nav_entries($src);
    assert_same(
        ['index.html', 'ordering.html', 'stock.html', 'warehouse.html', 'ingredients.html', 'accounts.html'],
        array_keys($entries),
        'LINKS pages in order'
    );
    assert_same([['hq', 'hub', 'franchise']], $entries['index.html'], 'Home roles unchanged');
    assert_same([['hq', 'hub', 'franchise']], $entries['ordering.html'], 'Stock & ordering roles unchanged');
    foreach (nav_expected_pages() as $page => $roles) {
        assert_same([$roles], $entries[$page], 'LINKS roles for ' . $page);
    }
});

test('N1: index.html TILES keep the two ordering tiles and add the four new pages for the spec roles', function (): void {
    $src = (string) file_get_contents(HUB_TEST_ROOT . '/public/index.html');
    $entries = nav_entries($src);
    assert_same([['hq', 'hub'], ['franchise']], $entries['ordering.html'] ?? null, 'both ordering tiles kept');
    foreach (nav_expected_pages() as $page => $roles) {
        assert_same([$roles], $entries[$page] ?? null, 'TILES roles for ' . $page);
    }
    foreach ($entries as $page => $list) {
        foreach ($list as $roles) {
            if ($page !== 'ordering.html') {
                assert_true(!in_array('franchise', $roles, true), 'no new tile for franchise: ' . $page);
            }
        }
    }
});

test('N1: the home page and app.js are still served', function (): void {
    $app = TestApp::start();
    assert_status(200, $app->request('GET', '/index.html'), 'GET /index.html');
    assert_status(200, $app->request('GET', '/assets/app.js'), 'GET /assets/app.js');
});

run_tests();
