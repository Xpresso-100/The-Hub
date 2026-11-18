<?php
declare(strict_types=1);

// F-R1: the loader must never leave an item orderable at R0.00, even when the row was already active at cost 0
// (made by the admin screen or a sample) and the CSV line has no usable price. Fixture data only.

require __DIR__ . '/../../lib/harness.php';

const HLZ_HEADER = 'sku,name,unit,pack_size,pack_price,fincon_item_no';

/** @return array{0:int,1:string} */
function hlz_load(TestApp $app, string $csv, array $options = []): array
{
    $file = $app->dir . '/hub-list-' . bin2hex(random_bytes(3)) . '.csv';
    file_put_contents($file, $csv);
    return hub_php(array_merge(['bin/load_hub_list.php'], $options, [$file]), $app->env);
}

function hlz_pdo(TestApp $app): PDO
{
    return new PDO('sqlite:' . $app->dbPath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/** @return array<string,mixed> */
function hlz_row(TestApp $app, string $sku): array
{
    $stmt = hlz_pdo($app)->prepare('SELECT sku, name, cost_cents, active FROM ingredients WHERE sku = ?');
    $stmt->execute([$sku]);
    $row = $stmt->fetch();
    return $row === false ? [] : $row;
}

function hlz_last_line(string $out): string
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $out) ?: []), static fn (string $l): bool => $l !== ''));
    return $lines === [] ? '' : $lines[count($lines) - 1];
}

/** Loads a priced row, then forces it to active at R0.00 (as the admin screen could). */
function hlz_seed_zero_active(TestApp $app, string $sku): void
{
    [$code, $out] = hlz_load($app, HLZ_HEADER . "\n$sku,Test Zero Active,kg,5,50.00,\n");
    assert_same(0, $code, 'setup load; output: ' . $out);
    hlz_pdo($app)->exec("UPDATE ingredients SET cost_cents = 0, active = 1 WHERE sku = '$sku'");
    assert_same(0, hlz_row($app, $sku)['cost_cents'], 'seeded cost 0');
    assert_same(1, hlz_row($app, $sku)['active'], 'seeded active');
}

test('FR1-1: existing row active at R0.00 + blank price -> switched off', function (): void {
    $app = TestApp::start();
    hlz_seed_zero_active($app, 'TST901');
    [$code, $out] = hlz_load($app, HLZ_HEADER . "\nTST901,Test Zero Renamed,kg,,,\n");
    assert_same(0, $code, 'exit code; output: ' . $out);
    $row = hlz_row($app, 'TST901');
    assert_same(0, $row['active'], 'no longer orderable at R0.00');
    assert_same(0, $row['cost_cents'], 'cost still 0');
    assert_same('Test Zero Renamed', $row['name'], 'name updated');
    assert_same('load_hub_list: 0 active, 1 off, 0 kept, 0 samples off', hlz_last_line($out), 'final line');
});

test('FR1-2: existing row active at R0.00 + price 0 -> switched off', function (): void {
    $app = TestApp::start();
    hlz_seed_zero_active($app, 'TST902');
    [$code, $out] = hlz_load($app, HLZ_HEADER . "\nTST902,Test Zero Active,kg,5,0,\n");
    assert_same(0, $code, 'exit code; output: ' . $out);
    assert_same(0, hlz_row($app, 'TST902')['active'], 'switched off');
});

test('FR1-3: --dry-run changes nothing for a zero-cost active row', function (): void {
    $app = TestApp::start();
    hlz_seed_zero_active($app, 'TST903');
    [$code, $out] = hlz_load($app, HLZ_HEADER . "\nTST903,Test Zero Active,kg,,,\n", ['--dry-run']);
    assert_same(0, $code, 'exit code; output: ' . $out);
    assert_same(1, hlz_row($app, 'TST903')['active'], 'still active after dry run');
    assert_same('load_hub_list: 0 active, 1 off, 0 kept, 0 samples off', hlz_last_line($out), 'final line');
});

test('FR1-4: existing priced active row + blank price stays active and unchanged in cost', function (): void {
    $app = TestApp::start();
    [$code, $out] = hlz_load($app, HLZ_HEADER . "\nTST904,Test Priced,kg,10,100.00,\n");
    assert_same(0, $code, 'setup load; output: ' . $out);
    [$code, $out] = hlz_load($app, HLZ_HEADER . "\nTST904,Test Priced,kg,,,\n");
    assert_same(0, $code, 'exit code; output: ' . $out);
    $row = hlz_row($app, 'TST904');
    assert_same(1, $row['active'], 'still active');
    assert_same(1000, $row['cost_cents'], 'cost unchanged');
    assert_same('load_hub_list: 0 active, 0 off, 1 kept, 0 samples off', hlz_last_line($out), 'final line');
});

run_tests();
