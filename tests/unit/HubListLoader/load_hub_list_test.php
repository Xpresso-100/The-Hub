<?php
declare(strict_types=1);

// Lane C (docs/BUILD-SPEC-2026-11-18-go-live.md s10, C1-C3): php bin/load_hub_list.php [--dry-run] [--deactivate-samples] <file.csv>
// The Hub list loader must never make an item orderable at R0.00. Fixture data only (TST skus, "Test ..." names, made-up prices).

require __DIR__ . '/../../lib/harness.php';

const HLL_HEADER = 'sku,name,unit,pack_size,pack_price,fincon_item_no';
const HLL_DOT = "\u{00B7}";

function hll_fixture(string $name): string
{
    return HUB_TEST_ROOT . '/tests/fixtures/hub-list/' . $name;
}

/** Writes $csv to a scratch file and runs the loader on it. @return array{0:int,1:string} */
function hll_load(TestApp $app, string $csv, array $options = []): array
{
    $file = $app->dir . '/hub-list-' . bin2hex(random_bytes(3)) . '.csv';
    file_put_contents($file, $csv);
    return hll_run($app, $file, $options);
}

/** @return array{0:int,1:string} */
function hll_run(TestApp $app, string $file, array $options = []): array
{
    return hub_php(array_merge(['bin/load_hub_list.php'], $options, [$file]), $app->env);
}

function hll_pdo(TestApp $app): PDO
{
    return new PDO('sqlite:' . $app->dbPath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/** The ingredients row for $sku, or null. */
function hll_row(TestApp $app, string $sku): ?array
{
    $stmt = hll_pdo($app)->prepare('SELECT sku, name, unit, cost_cents, fincon_item_no, is_sample, active, updated_at FROM ingredients WHERE sku = ?');
    $stmt->execute([$sku]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    foreach (['cost_cents', 'is_sample', 'active'] as $key) {
        $row[$key] = (int) $row[$key];
    }
    return $row;
}

/** The whole ingredients table, every column. */
function hll_table(TestApp $app): array
{
    return hll_pdo($app)->query('SELECT * FROM ingredients ORDER BY sku')->fetchAll();
}

/** Skus of active sample rows. */
function hll_active_samples(TestApp $app): array
{
    return array_column(hll_pdo($app)->query('SELECT sku FROM ingredients WHERE is_sample = 1 AND active = 1 ORDER BY sku')->fetchAll(), 'sku');
}

/** Non-empty output lines, trimmed. */
function hll_lines(string $out): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $out) ?: []), static fn (string $l): bool => $l !== ''));
}

function hll_last_line(string $out): string
{
    $lines = hll_lines($out);
    return $lines === [] ? '' : $lines[count($lines) - 1];
}

function hll_has_line(string $out, string $expected, string $message): void
{
    assert_true(in_array($expected, hll_lines($out), true), $message . ': expected the line "' . $expected . '" in: ' . $out);
}

function hll_line_starting(string $out, string $prefix): ?string
{
    foreach (hll_lines($out) as $line) {
        if (str_starts_with($line, $prefix)) {
            return $line;
        }
    }
    return null;
}

test('C-1: comma CSV, 24 kg at R1 234.56 -> 5144 cents per kg, active, not a sample', function (): void {
    $app = TestApp::start();
    $samplesBefore = hll_active_samples($app);
    [$code, $out] = hll_run($app, hll_fixture('comma.csv'));
    assert_same(0, $code, 'exit code; output: ' . $out);

    $row = hll_row($app, 'TST001');
    assert_true($row !== null, 'TST001 inserted');
    assert_same(5144, $row['cost_cents'], 'cost_cents = round_half_up(123456 * 1000, 24000)');
    assert_same(1, $row['active'], 'priced row is active');
    assert_same(0, $row['is_sample'], 'loaded row is not a sample');
    assert_same('Test Flour', $row['name'], 'name');
    assert_same('kg', $row['unit'], 'unit');
    assert_same('F-TST001', $row['fincon_item_no'], 'fincon_item_no');

    hll_has_line($out, 'TST001 ACTIVE per kg R51.44 ' . HLL_DOT . ' pack 24 kg = R1 234.56 ' . HLL_DOT . ' diff R0.00', 'row line');
    assert_same('load_hub_list: 1 active, 0 off, 0 kept, 0 samples off', hll_last_line($out), 'final line');
    assert_same($samplesBefore, hll_active_samples($app), 'samples untouched without --deactivate-samples');
});

test('C-2: semicolon CSV with a decimal comma, BOM, CRLF and non-breaking spaces -> same result', function (): void {
    $app = TestApp::start();
    $csv = "\xEF\xBB\xBFsku;name;unit;pack_size;pack_price;fincon_item_no\r\n"
        . "TST001;Test Flour, fine;kg;24,000;1\xC2\xA0234,56;F-TST001\r\n"
        . "TST002;Test Sugar;kg;12,5;100,00;\r\n";
    [$code, $out] = hll_load($app, $csv);
    assert_same(0, $code, 'exit code; output: ' . $out);

    $row = hll_row($app, 'TST001');
    assert_true($row !== null, 'TST001 inserted');
    assert_same(5144, $row['cost_cents'], 'same cost as the comma file');
    assert_same(1, $row['active'], 'active');
    assert_same(0, $row['is_sample'], 'not a sample');
    assert_same('Test Flour, fine', $row['name'], 'comma inside a name in a ; file');

    $row2 = hll_row($app, 'TST002');
    assert_same(800, $row2['cost_cents'] ?? null, '10000 * 1000 / 12500 = 800');
    assert_true(is_array($row2) && array_key_exists('fincon_item_no', $row2) && $row2['fincon_item_no'] === null, 'blank item no = NULL');

    hll_has_line($out, 'TST001 ACTIVE per kg R51.44 ' . HLL_DOT . ' pack 24 kg = R1 234.56 ' . HLL_DOT . ' diff R0.00', 'row line');
    hll_has_line($out, 'TST002 ACTIVE per kg R8.00 ' . HLL_DOT . ' pack 12.5 kg = R100.00 ' . HLL_DOT . ' diff R0.00', 'row line 2');
    assert_same('load_hub_list: 2 active, 0 off, 0 kept, 0 samples off', hll_last_line($out), 'final line');
});

test('C-3: blank price (or blank size) on a new sku -> inserted with active 0, cost 0', function (): void {
    $app = TestApp::start();
    $csv = HLL_HEADER . "\n"
        . "TST002,Test Sugar,kg,10,,F-TST002\n"
        . "TST003,Test Salt,kg,,15.00,\n";
    [$code, $out] = hll_load($app, $csv);
    assert_same(0, $code, 'exit code; output: ' . $out);

    foreach (['TST002', 'TST003'] as $sku) {
        $row = hll_row($app, $sku);
        assert_true($row !== null, $sku . ' inserted');
        assert_same(0, $row['active'], $sku . ' active');
        assert_same(0, $row['cost_cents'], $sku . ' cost_cents');
        assert_same(0, $row['is_sample'], $sku . ' is_sample');
        assert_true(str_starts_with((string) hll_line_starting($out, $sku . ' '), $sku . ' OFF'), $sku . ' reported OFF: ' . $out);
    }
    assert_same('F-TST002', hll_row($app, 'TST002')['fincon_item_no'], 'item no stored on an OFF row');
    assert_same('load_hub_list: 0 active, 2 off, 0 kept, 0 samples off', hll_last_line($out), 'final line');
});

// Amended 19 Nov 2026 with Damian's approval: C-4 used to assert the unit change was applied; decision 2 reverses that.
test('C-4: blank price on an existing priced sku with a changed unit -> that row is REFUSED and left whole; other rows still load; exit 1', function (): void {
    $app = TestApp::start();
    [$code, $out] = hll_load($app, HLL_HEADER . "\nTST004,Test Butter,kg,10,125.00,F-OLD\n");
    assert_same(0, $code, 'first load; output: ' . $out);
    $before = hll_row($app, 'TST004');
    assert_same(1250, $before['cost_cents'] ?? null, 'priced first');
    assert_same(1, $before['active'] ?? null, 'active first');

    [$code, $out] = hll_load($app, HLL_HEADER . "\nTST004,Test Butter Salted,block,10,,F-NEW\nTST005,Test Cream,l,,,\n");
    assert_same(1, $code, 'second load exits 1 (a row was refused); output: ' . $out);
    $after = hll_row($app, 'TST004');
    assert_same(1250, $after['cost_cents'], 'cost unchanged');
    assert_same(1, $after['active'], 'active unchanged');
    assert_same(0, $after['is_sample'], 'is_sample unchanged');
    assert_same('Test Butter', $after['name'], 'name unchanged');
    assert_same('kg', $after['unit'], 'unit unchanged');
    assert_same('F-OLD', $after['fincon_item_no'], 'item no unchanged');
    assert_true(str_starts_with((string) hll_line_starting($out, 'TST004 '), 'TST004 REFUSED'), 'reported REFUSED: ' . $out);
    $new = hll_row($app, 'TST005');
    assert_true($new !== null && $new['active'] === 0 && $new['cost_cents'] === 0, 'TST005 still inserted, switched off');
    hll_has_line($out, 'load_hub_list: 0 active, 1 off, 0 kept, 0 samples off, 1 refused', 'final stdout line');
});

test('C-5: a price of 0 never makes a row active (new sku, existing off sku, existing active sku)', function (): void {
    $app = TestApp::start();
    [$code, $out] = hll_load($app, HLL_HEADER . "\nTST006,Test Off Row,kg,5,,\nTST007,Test Priced Row,kg,5,50.00,\n");
    assert_same(0, $code, 'setup load; output: ' . $out);

    $csv = HLL_HEADER . "\n"
        . "TST005,Test Zero New,kg,5,0.00,\n"
        . "TST006,Test Zero Off,kg,5,0,\n"
        . "TST007,Test Zero Active,kg,5,0,\n";
    [$code, $out] = hll_load($app, $csv);
    assert_same(0, $code, 'exit code; output: ' . $out);

    $new = hll_row($app, 'TST005');
    assert_same(0, $new['active'] ?? null, 'new sku at price 0 is off');
    assert_same(0, $new['cost_cents'] ?? null, 'new sku cost 0');
    assert_true(str_starts_with((string) hll_line_starting($out, 'TST005 '), 'TST005 OFF'), 'TST005 OFF: ' . $out);

    $off = hll_row($app, 'TST006');
    assert_same(0, $off['active'], 'existing off sku stays off');
    assert_same(0, $off['cost_cents'], 'existing off sku cost stays 0');
    assert_same('Test Zero Off', $off['name'], 'name updated');
    assert_true(str_starts_with((string) hll_line_starting($out, 'TST006 '), 'TST006 KEPT'), 'TST006 KEPT: ' . $out);

    $active = hll_row($app, 'TST007');
    assert_same(1000, $active['cost_cents'], 'price 0 does not overwrite an existing cost');
    assert_same(1, $active['active'], 'existing active row keeps its state');
    assert_true(str_starts_with((string) hll_line_starting($out, 'TST007 '), 'TST007 KEPT'), 'TST007 KEPT: ' . $out);

    $zeroActive = (int) hll_pdo($app)->query("SELECT COUNT(*) FROM ingredients WHERE sku LIKE 'TST%' AND active = 1 AND cost_cents = 0")->fetchColumn();
    assert_same(0, $zeroActive, 'no loaded row is active at R0.00');
    assert_same('load_hub_list: 0 active, 1 off, 2 kept, 0 samples off', hll_last_line($out), 'final line');
});

test('C-6: one bad row among good rows -> nothing written, exit 1, every problem listed with its line number', function (): void {
    $app = TestApp::start();
    $before = hll_table($app);
    [$code, $out] = hll_run($app, hll_fixture('bad-rows.csv'), ['--deactivate-samples']);
    assert_same(1, $code, 'exit code; output: ' . $out);
    foreach (['line 3:', 'line 4:', 'line 5:', 'line 6:', 'line 7:', 'line 8:', 'line 9:', 'line 10:', 'line 11:', 'line 12:'] as $needle) {
        assert_true(str_contains($out, $needle), 'output lists "' . $needle . '": ' . $out);
    }
    foreach (['line 1:', 'line 2:', 'line 13:', 'line 14:'] as $needle) {
        assert_true(!str_contains($out, $needle), 'good, header and blank lines are not reported ("' . $needle . '"): ' . $out);
    }
    assert_true(str_contains($out, 'Nothing changed.'), 'says nothing changed: ' . $out);
    assert_true(!str_contains($out, 'load_hub_list: '), 'no success summary: ' . $out);
    assert_same($before, hll_table($app), 'ingredients table unchanged (good rows not written, samples not switched off)');
});

test('C-7: --dry-run reports the plan and changes nothing', function (): void {
    $app = TestApp::start();
    [$code, $out] = hll_load($app, HLL_HEADER . "\nTST008,Test Existing,kg,10,100.00,\n");
    assert_same(0, $code, 'setup load; output: ' . $out);
    $before = hll_table($app);
    $samples = count(hll_active_samples($app));
    assert_true($samples > 0, 'fixture app has active sample rows');

    $csv = HLL_HEADER . "\n"
        . "TST001,Test Flour,kg,24,1234.56,F-TST001\n"
        . "TST002,Test Sugar,kg,10,,\n"
        . "TST008,Test Existing Renamed,kg,10,,\n";
    [$code, $out] = hll_load($app, $csv, ['--dry-run', '--deactivate-samples']);
    assert_same(0, $code, 'exit code; output: ' . $out);
    hll_has_line($out, 'TST001 ACTIVE per kg R51.44 ' . HLL_DOT . ' pack 24 kg = R1 234.56 ' . HLL_DOT . ' diff R0.00', 'planned row line');
    assert_true(str_contains(strtolower($out), 'dry run'), 'says dry run: ' . $out);
    assert_same('load_hub_list: 1 active, 1 off, 1 kept, ' . $samples . ' samples off', hll_last_line($out), 'final line');
    assert_same($before, hll_table($app), 'ingredients table unchanged');
});

test('C-8: --deactivate-samples switches off only sample rows; a sample the file re-prices stays active', function (): void {
    $app = TestApp::start();
    [$code, $out] = hll_load($app, HLL_HEADER . "\nTST031,Test Not In Next File,kg,1,10.00,\n");
    assert_same(0, $code, 'setup load; output: ' . $out);
    $samples = hll_active_samples($app);
    assert_true(in_array('SMP001', $samples, true) && count($samples) >= 2, 'fixture app has active samples incl. SMP001: ' . implode(',', $samples));
    $smp001 = hll_row($app, 'SMP001');
    $nonSampleActive = array_column(hll_pdo($app)->query('SELECT sku FROM ingredients WHERE is_sample = 0 AND active = 1 ORDER BY sku')->fetchAll(), 'sku');

    $csv = HLL_HEADER . "\n"
        . "SMP001,Test Flour Real,kg,25,350.00,F-SMP001\n"
        . "TST030,Test Yeast,kg,1,80.00,\n";
    [$code, $out] = hll_load($app, $csv, ['--deactivate-samples']);
    assert_same(0, $code, 'exit code; output: ' . $out);

    $repriced = hll_row($app, 'SMP001');
    assert_same(1400, $repriced['cost_cents'], '35000 * 1000 / 25000');
    assert_same(1, $repriced['active'], 're-priced sample stays active');
    assert_same(0, $repriced['is_sample'], 're-priced sample is no longer a sample');
    $diff = 1400 - $smp001['cost_cents'];
    $diffText = ($diff > 0 ? '+' : ($diff < 0 ? '-' : '')) . 'R' . intdiv(abs($diff), 100) . '.' . str_pad((string) (abs($diff) % 100), 2, '0', STR_PAD_LEFT);
    hll_has_line($out, 'SMP001 ACTIVE per kg R14.00 ' . HLL_DOT . ' pack 25 kg = R350.00 ' . HLL_DOT . ' diff ' . $diffText, 'diff vs old cost');

    $stillActiveSamples = (int) hll_pdo($app)->query('SELECT COUNT(*) FROM ingredients WHERE is_sample = 1 AND active = 1')->fetchColumn();
    assert_same(0, $stillActiveSamples, 'every sample row is off');
    foreach ($nonSampleActive as $sku) {
        assert_same(1, hll_row($app, $sku)['active'], 'non-sample ' . $sku . ' still active');
    }
    assert_same(1, hll_row($app, 'TST030')['active'] ?? null, 'new row active');
    assert_same(
        'load_hub_list: 2 active, 0 off, 0 kept, ' . (count($samples) - 1) . ' samples off',
        hll_last_line($out),
        'samples off excludes the re-priced sample'
    );
});

test('C-9: a duplicate sku -> exit 1, nothing written', function (): void {
    $app = TestApp::start();
    $before = hll_table($app);
    $csv = HLL_HEADER . "\n"
        . "TST040,Test First,kg,1,10.00,\n"
        . "TST041,Test Second,kg,1,10.00,\n"
        . "TST040,Test First Again,kg,1,12.00,\n";
    [$code, $out] = hll_load($app, $csv);
    assert_same(1, $code, 'exit code; output: ' . $out);
    assert_true(str_contains($out, 'line 4: sku TST040 already on line 2'), 'duplicate reported with both lines: ' . $out);
    assert_same($before, hll_table($app), 'ingredients table unchanged');
});

test('C-10: rounding half up: R10.00 over 3 kg -> 333 cents; R0.05 over 2 -> 3 cents', function (): void {
    $app = TestApp::start();
    $csv = HLL_HEADER . "\n"
        . "TST050,Test Round Down,kg,3,10.00,\n"
        . "TST051,Test Round Half,kg,2,0.05,\n";
    [$code, $out] = hll_load($app, $csv);
    assert_same(0, $code, 'exit code; output: ' . $out);
    assert_same(333, hll_row($app, 'TST050')['cost_cents'] ?? null, '1000 * 1000 / 3000 = 333.33 -> 333');
    assert_same(3, hll_row($app, 'TST051')['cost_cents'] ?? null, '5 * 1000 / 2000 = 2.5 -> 3');
    hll_has_line($out, 'TST050 ACTIVE per kg R3.33 ' . HLL_DOT . ' pack 3 kg = R10.00 ' . HLL_DOT . ' diff R0.00', 'TST050 line');
    hll_has_line($out, 'TST051 ACTIVE per kg R0.03 ' . HLL_DOT . ' pack 2 kg = R0.05 ' . HLL_DOT . ' diff R0.00', 'TST051 line');
    assert_same('load_hub_list: 2 active, 0 off, 0 kept, 0 samples off', hll_last_line($out), 'final line');
});

test('C-usage: usage errors exit 2; bad header, header only and missing file exit 1; skipped lines keep physical line numbers', function (): void {
    $app = TestApp::start();
    $before = hll_table($app);
    $file = $app->dir . '/ok.csv';
    file_put_contents($file, HLL_HEADER . "\nTST060,Test Ok,kg,1,1.00,\n");

    foreach ([[], ['--bogus', $file], [$file, $file], ['--dry-run']] as $args) {
        [$code, $out] = hub_php(array_merge(['bin/load_hub_list.php'], $args), $app->env);
        assert_same(2, $code, 'usage exit for [' . implode(' ', $args) . ']; output: ' . $out);
        assert_true(str_contains($out, 'Usage:'), 'usage text: ' . $out);
    }

    [$code, $out] = hll_load($app, "sku,name,unit,cost_price,fincon_item_no\nTST060,Test Ok,kg,1.00,\n");
    assert_same(1, $code, 'old import header rejected; output: ' . $out);
    assert_true(str_contains($out, 'line 1:'), 'header problem on line 1: ' . $out);

    [$code, $out] = hll_load($app, HLL_HEADER . "\n");
    assert_same(1, $code, 'header only rejected; output: ' . $out);

    [$code, $out] = hll_run($app, $app->dir . '/missing.csv');
    assert_same(1, $code, 'missing file; output: ' . $out);
    assert_same($before, hll_table($app), 'nothing written by any failed run');

    $csv = "\n" . HLL_HEADER . "\n\n,,,,,\n  \n,,kg,1,1.00,\nTST061,Test Bad Price,kg,1,abc,\n";
    [$code, $out] = hll_load($app, $csv);
    assert_same(1, $code, 'bad price; output: ' . $out);
    assert_true(str_contains($out, 'line 7:'), 'physical line number 7 reported: ' . $out);
    assert_true(!str_contains($out, 'line 4:') && !str_contains($out, 'line 6:'), 'skipped lines not reported: ' . $out);

    $csv = HLL_HEADER . "\n\n,,,,,\nTST062,Test After Skips,kg,1,1.00,\n\n";
    [$code, $out] = hll_load($app, $csv);
    assert_same(0, $code, 'valid file with skipped lines; output: ' . $out);
    assert_same(100, hll_row($app, 'TST062')['cost_cents'] ?? null, 'row after skipped lines loaded');
    assert_same('load_hub_list: 1 active, 0 off, 0 kept, 0 samples off', hll_last_line($out), 'final line');
});

test('C-11: a cost above 2147483647 cents per unit is rejected; nothing written; exit 1; the problem names the line', function (): void {
    $app = TestApp::start();
    $before = hll_table($app);
    $csv = HLL_HEADER . "\n"
        . "TST070,Test Good Row,kg,1,10.00,\n"
        . "TST071,Test Overflow,kg,0.001,25000.00,\n";
    [$code, $out] = hll_load($app, $csv);
    assert_same(1, $code, 'exit code; output: ' . $out);
    assert_true(
        str_contains($out, 'line 3: cost per kg R25 000 000.00 is above the maximum R21 474 836.47'),
        'overflow reported on line 3 with both amounts: ' . $out
    );
    assert_true(!str_contains($out, 'line 2:'), 'good row not reported: ' . $out);
    assert_true(str_contains($out, 'Nothing changed.'), 'says nothing changed: ' . $out);
    assert_true(!str_contains($out, 'load_hub_list: '), 'no success summary: ' . $out);
    assert_same($before, hll_table($app), 'ingredients table unchanged (good row not written either)');
});

run_tests();
