<?php
declare(strict_types=1);

// Hardening item 3: bin/load_hub_list.php must not change the unit of an existing sku.
// A row whose sku already exists with a DIFFERENT unit (exact comparison after trim: 'kg' vs 'KG' is different) is
// REFUSED: listed in the report as a REFUSED row naming the sku, old unit and new unit, and the ingredient row is
// left completely untouched. The other valid rows in the same file still load. A row with the SAME unit still
// updates normally, and a brand-new sku is unaffected.
//
// Decisions (the brief leaves them open; the stricter reading is taken):
// - Report word: REFUSED (same family as ACTIVE / OFF / KEPT / SAMPLE), one line per refused row that contains the
//   sku, the old unit and the new unit. The text is read from stdout+stderr together (hub_php merges them).
// - Exit code 1 when any row was refused, even though the other rows were loaded: a refusal must not look like a clean
//   run (exit 0) to the person loading the list. Exit 1 is the existing code for "a row is not acceptable".
// - --dry-run reports the same REFUSED line and the same exit code, and writes nothing.
// Fixture data only (TST8xx skus, made-up names and prices).

require __DIR__ . '/../../lib/harness.php';

const LUC_HEADER = 'sku,name,unit,pack_size,pack_price,fincon_item_no';

/** @return array{0:int,1:string} */
function luc_load(TestApp $app, string $csv, array $options = []): array
{
    $file = $app->dir . '/luc-' . bin2hex(random_bytes(3)) . '.csv';
    file_put_contents($file, $csv);
    return hub_php(array_merge(['bin/load_hub_list.php'], $options, [$file]), $app->env);
}

function luc_pdo(TestApp $app): PDO
{
    return new PDO('sqlite:' . $app->dbPath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/** The whole ingredients row for $sku (every column), or null. */
function luc_row(TestApp $app, string $sku): ?array
{
    $st = luc_pdo($app)->prepare('SELECT * FROM ingredients WHERE sku = ?');
    $st->execute([$sku]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function luc_table(TestApp $app): array
{
    return luc_pdo($app)->query('SELECT * FROM ingredients ORDER BY sku')->fetchAll();
}

function luc_lines(string $out): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $out) ?: []), static fn (string $l): bool => $l !== ''));
}

/** Lines of the report that mention $sku. */
function luc_lines_for(string $out, string $sku): array
{
    return array_values(array_filter(luc_lines($out), static fn (string $l): bool => preg_match('/(^|[^A-Z0-9-])' . preg_quote($sku, '/') . '([^A-Z0-9-]|$)/', $l) === 1));
}

/** A REFUSED line for $sku that names the old and the new unit. */
function luc_assert_refused_line(string $out, string $sku, string $old, string $new): void
{
    foreach (luc_lines_for($out, $sku) as $line) {
        if (str_contains($line, 'REFUSED') && str_contains($line, $old) && str_contains($line, $new)) {
            // Both units must be there as separate tokens, in the order old then new.
            assert_true(
                strpos($line, $old) !== false && strpos($line, $new, (int) strpos($line, $old) + strlen($old)) !== false,
                $sku . ' REFUSED line names the old unit "' . $old . '" and then the new unit "' . $new . '": ' . $line
            );
            return;
        }
    }
    hub_fail('expected a REFUSED line naming ' . $sku . ', old unit "' . $old . '" and new unit "' . $new . '" in: ' . hub_snippet($out, 900));
}

/** Seeds TST801.. through the loader itself, so the rows are exactly what the loader writes. */
function luc_seed(TestApp $app): void
{
    $csv = LUC_HEADER . "\n"
        . "TST801,Test Flour,tub,10,100.00,F-801\n"
        . "TST802,Test Sugar,kg,10,50.00,F-802\n"
        . "TST803,Test Salt,kg,,,\n"      // OFF row: blank price, stays switched off at R0.00
        . "TST805,Test Yeast,kg,2,40.00,\n";
    [$code, $out] = luc_load($app, $csv);
    assert_same(0, $code, 'seed load exit code; output: ' . $out);
}

test('C3-01: a different unit on an existing sku is REFUSED (named in the report), the row is untouched, the other rows still load', function (): void {
    $app = TestApp::start();
    luc_seed($app);
    $before801 = luc_row($app, 'TST801');
    $before805 = luc_row($app, 'TST805');
    assert_true($before801 !== null && $before805 !== null, 'seeded rows exist');

    sleep(1); // so an updated_at touch would show
    $csv = LUC_HEADER . "\n"
        . "TST801,Test Flour Renamed,bag,10,200.00,F-NEW\n"   // unit tub -> bag: refused
        . "TST805,Test Yeast,KG,2,41.00,\n"                   // unit kg -> KG: different, refused
        . "TST802,Test Sugar Renamed,kg,10,60.00,F-802B\n"    // same unit: updated
        . "TST804,Test Brand New,litre,5,25.00,\n";           // new sku: inserted
    [$code, $out] = luc_load($app, $csv);

    luc_assert_refused_line($out, 'TST801', 'tub', 'bag');
    luc_assert_refused_line($out, 'TST805', 'kg', 'KG');
    assert_same($before801, luc_row($app, 'TST801'), 'TST801 row must be completely unchanged (name, unit, cost, item no, updated_at)');
    assert_same($before805, luc_row($app, 'TST805'), 'TST805 row must be completely unchanged (kg vs KG is a different unit)');
    foreach (['TST801', 'TST805'] as $sku) {
        foreach (luc_lines_for($out, $sku) as $line) {
            assert_true(preg_match('/\b(ACTIVE|OFF|KEPT)\b/', $line) !== 1, $sku . ' is not also reported as loaded: ' . $line);
        }
    }

    $sugar = luc_row($app, 'TST802');
    assert_same('Test Sugar Renamed', $sugar['name'] ?? null, 'a valid row in the same file still loads (name)');
    assert_same(600, (int) ($sugar['cost_cents'] ?? -1), 'a valid row in the same file still loads (cost = 6000 * 1000 / 10000 cents)');
    $new = luc_row($app, 'TST804');
    assert_true($new !== null, 'a brand-new sku in the same file is inserted');
    assert_same('litre', $new['unit'] ?? null, 'new sku unit');
    assert_same(500, (int) ($new['cost_cents'] ?? -1), 'new sku cost');

    assert_same(1, $code, 'exit code when a row was refused (not 0: it must not look like a clean run)');
});

test('C3-02: a blank-price row (the KEPT/OFF path) that changes the unit is refused too', function (): void {
    $app = TestApp::start();
    luc_seed($app);
    $before = luc_row($app, 'TST803');
    $beforeAll = luc_table($app);
    sleep(1);
    [$code, $out] = luc_load($app, LUC_HEADER . "\nTST803,Test Salt,sack,,,F-NEW\nTST802,Test Sugar,kg,10,50.00,F-802\n");
    luc_assert_refused_line($out, 'TST803', 'kg', 'sack');
    assert_same($before, luc_row($app, 'TST803'), 'TST803 unchanged');
    assert_same(1, $code, 'exit code');
    $after = luc_table($app);
    foreach ($after as $i => $row) {
        if ($row['sku'] !== 'TST803' && $row['sku'] !== 'TST802') {
            assert_same($beforeAll[$i], $row, $row['sku'] . ' not touched');
        }
    }
});

test('C3-03: a file with only refused rows changes nothing and still exits 1', function (): void {
    $app = TestApp::start();
    luc_seed($app);
    $before = luc_table($app);
    sleep(1);
    [$code, $out] = luc_load($app, LUC_HEADER . "\nTST801,Other Name,bag,10,100.00,\nTST802,Other Name,crate,10,50.00,\n");
    luc_assert_refused_line($out, 'TST801', 'tub', 'bag');
    luc_assert_refused_line($out, 'TST802', 'kg', 'crate');
    assert_same($before, luc_table($app), 'ingredients table unchanged');
    assert_same(1, $code, 'exit code');
});

test('C3-04: --dry-run reports the same REFUSED lines and exit code, and writes nothing', function (): void {
    $app = TestApp::start();
    luc_seed($app);
    $before = luc_table($app);
    sleep(1);
    [$code, $out] = luc_load($app, LUC_HEADER . "\nTST801,Test Flour,bag,10,200.00,\nTST802,Test Sugar,kg,10,60.00,\nTST806,Test New,kg,1,10.00,\n", ['--dry-run']);
    luc_assert_refused_line($out, 'TST801', 'tub', 'bag');
    assert_true(str_contains($out, 'dry run: nothing written'), 'dry run banner: ' . $out);
    assert_same($before, luc_table($app), 'dry run wrote nothing');
    assert_same(1, $code, 'dry-run exit code matches the live run');
});

test('C3-05: the SAME unit still updates normally (also after trim), and a brand-new sku is unaffected', function (): void {
    $app = TestApp::start();
    luc_seed($app);
    [$code, $out] = luc_load($app, LUC_HEADER . "\nTST801,Test Flour v2, tub ,10,150.00,F-801\nTST807,Test New Item,kg,4,20.00,\n");
    assert_same(0, $code, 'exit code; output: ' . $out);
    assert_true(!str_contains($out, 'REFUSED'), 'nothing is refused: ' . $out);
    $flour = luc_row($app, 'TST801');
    assert_same('Test Flour v2', $flour['name'] ?? null, 'name updated');
    assert_same('tub', $flour['unit'] ?? null, 'unit kept (trimmed equal)');
    assert_same(1500, (int) ($flour['cost_cents'] ?? -1), 'cost updated');
    $new = luc_row($app, 'TST807');
    assert_true($new !== null && (int) $new['cost_cents'] === 500 && (int) $new['active'] === 1, 'brand-new sku inserted active at 500 cents');
});

run_tests();
