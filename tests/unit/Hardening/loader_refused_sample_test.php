<?php
declare(strict_types=1);

// bin/load_hub_list.php --deactivate-samples must leave a REFUSED row exactly as it is.
// A sample ingredient (is_sample=1, active=1) listed in the file with a DIFFERENT unit is refused ("ingredient
// unchanged"), so the sample switch-off must skip it too: no "SAMPLE switched off" line for it, row byte-identical
// (active, is_sample, unit, updated_at), every OTHER active sample is switched off, and the summary's "samples off"
// count equals the number of rows really switched off. --dry-run reports the same lines and writes nothing.
// Fixture data only: the seeded SMP001-SMP006 samples (db/seed_samples.sql: SMP003 is unit 'l') and a TST skus.

require __DIR__ . '/../../lib/harness.php';

const LRS_HEADER = 'sku,name,unit,pack_size,pack_price,fincon_item_no';

/** @return array{0:int,1:string} */
function lrs_load(TestApp $app, string $csv, array $options): array
{
    $file = $app->dir . '/lrs-' . bin2hex(random_bytes(3)) . '.csv';
    file_put_contents($file, $csv);
    return hub_php(array_merge(['bin/load_hub_list.php'], $options, [$file]), $app->env);
}

function lrs_table(TestApp $app): array
{
    $pdo = new PDO('sqlite:' . $app->dbPath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo->query('SELECT * FROM ingredients ORDER BY sku')->fetchAll();
}

function lrs_by_sku(array $table, string $sku): ?array
{
    foreach ($table as $row) {
        if ($row['sku'] === $sku) {
            return $row;
        }
    }
    return null;
}

function lrs_lines(string $out): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $out) ?: []), static fn (string $l): bool => $l !== ''));
}

/** The report: SKU lines only (ACTIVE / OFF / KEPT / REFUSED / SAMPLE), no banners, no summary. */
function lrs_report(string $out): array
{
    return array_values(array_filter(lrs_lines($out), static fn (string $l): bool => preg_match('/^[A-Z0-9-]+ (ACTIVE|OFF|KEPT|REFUSED|SAMPLE)\b/', $l) === 1));
}

function lrs_csv(): string
{
    // SMP003 is a seeded active sample with unit 'l': 'carton' is a unit change -> refused. TST901 is new.
    return LRS_HEADER . "\nSMP003,SAMPLE Liquid eggs,carton,1,45.00,F-SMP003\nTST901,Test New Item,kg,4,20.00,\n";
}

function lrs_assert_report(string $out, string $what): void
{
    $report = lrs_report($out);
    $refused = array_values(array_filter($report, static fn (string $l): bool => str_starts_with($l, 'SMP003 REFUSED')));
    assert_same(1, count($refused), $what . ': one "SMP003 REFUSED" line in: ' . implode(' | ', $report));
    assert_true(str_contains($refused[0], 'carton') && str_contains($refused[0], ' l'), $what . ': the REFUSED line names the old unit l and the new unit carton: ' . $refused[0]);
    foreach ($report as $line) {
        assert_true(!str_starts_with($line, 'SMP003 SAMPLE'), $what . ': a refused sample must not be reported as switched off: ' . $line);
    }
    $off = array_values(array_filter($report, static fn (string $l): bool => str_contains($l, ' SAMPLE switched off')));
    assert_same(['SMP001', 'SMP002', 'SMP004', 'SMP005', 'SMP006'], array_map(static fn (string $l): string => explode(' ', $l)[0], $off), $what . ': the other five samples are reported switched off');
    $summary = null;
    foreach (lrs_lines($out) as $line) {
        if (preg_match('/^load_hub_list: (\d+) active, (\d+) off, (\d+) kept, (\d+) samples off/', $line, $m) === 1) {
            $summary = (int) $m[4];
        }
    }
    assert_same(5, $summary, $what . ': summary "samples off" count');
}

test('C3-06: --deactivate-samples leaves a REFUSED sample untouched; the other samples are switched off; the count matches the rows really switched off', function (): void {
    $app = TestApp::start();
    $before = lrs_table($app);
    $sample = lrs_by_sku($before, 'SMP003');
    assert_true($sample !== null && (int) $sample['is_sample'] === 1 && (int) $sample['active'] === 1 && $sample['unit'] === 'l', 'premise: SMP003 is an active sample with unit l');

    [$code, $out] = lrs_load($app, lrs_csv(), ['--deactivate-samples']);
    assert_same(1, $code, 'exit code (a row was refused); output: ' . $out);
    lrs_assert_report($out, 'live run');

    $after = lrs_table($app);
    assert_same($sample, lrs_by_sku($after, 'SMP003'), 'SMP003 must be byte-identical (active, is_sample, unit, name, cost, updated_at)');
    $switchedOff = 0;
    foreach ($before as $row) {
        if ((int) $row['is_sample'] === 1 && (int) $row['active'] === 1) {
            $now = lrs_by_sku($after, $row['sku']);
            if ((int) $now['active'] === 0) {
                $switchedOff++;
            }
        }
    }
    assert_same(5, $switchedOff, 'samples really switched off in the database (= the summary count)');
    $new = lrs_by_sku($after, 'TST901');
    assert_true($new !== null && (int) $new['cost_cents'] === 500, 'the other valid row in the file still loads');
});

test('C3-07: --dry-run --deactivate-samples gives the same report lines and writes nothing', function (): void {
    $live = TestApp::start();
    [, $liveOut] = lrs_load($live, lrs_csv(), ['--deactivate-samples']);

    $app = TestApp::start();
    $before = lrs_table($app);
    [$code, $out] = lrs_load($app, lrs_csv(), ['--dry-run', '--deactivate-samples']);
    assert_same(1, $code, 'exit code; output: ' . $out);
    assert_true(in_array('dry run: nothing written', lrs_lines($out), true), 'dry run banner: ' . $out);
    lrs_assert_report($out, 'dry run');
    assert_same(lrs_report($liveOut), lrs_report($out), 'dry run report lines equal the live run report lines');
    assert_same($before, lrs_table($app), 'dry run wrote nothing');
});

run_tests();
