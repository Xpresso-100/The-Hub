<?php
declare(strict_types=1);

// Hardening item 4: control characters are refused in names and notes.
// Refused: any C0 control U+0000-U+001F (tab, CR, LF included), DEL U+007F and the C1 controls U+0080-U+009F,
// with 422 VALIDATION_ERROR and errors.<field> set. Fields: ingredient name and unit (PUT /api/admin/ingredients/{sku}),
// order notes (POST /api/orders), batch notes (POST /api/batches), inventory reason (POST /api/inventory/adjustments),
// recipe name and unit (POST/PUT /api/recipes), and the name column of bin/load_hub_list.php.
// Account username/email are pattern-checked already and are not covered here.
//
// RULING (coordinator): ORDER NOTES and BATCH NOTES are multi-line free text: LF (U+000A) and CR (U+000D) are ALLOWED there
// ("Line one\nline two" stays 201, as tests/unit/Ordering/notifier_test.php:20 needs). Every other control is still refused.
// A notes value that is only "\n" or only whitespace keeps today's behaviour: trimmed to empty, stored as null, accepted.
// Names (ingredient, recipe, loader) and the inventory reason: ALL controls refused, tab/CR/LF included.
// Decisions (stricter reading): the check is made on the RAW value, before trim(). So a control character at the
// start or end ("Flour\n", "\tFlour") is refused too, not silently trimmed away (LF/CR in the two notes fields excepted). For the CSV loader only the middle of a name is tested (cells are trimmed by design and a
// trailing CR is the line ending) plus the whole C0/DEL/C1 range in the middle of a name.
// Ordinary text with spaces, accents and apostrophes must still be accepted (C4-05).
//
// (The earlier conflict with tests/unit/Ordering/notifier_test.php:20 is resolved by the ruling above.)
// Fixture data only.

require __DIR__ . '/../../lib/harness.php';

/** Every code point that must be refused: U+0000-U+001F, U+007F, U+0080-U+009F. */
function cc_codepoints(): array
{
    return array_merge(range(0x00, 0x1F), [0x7F], range(0x80, 0x9F));
}

function cc_char(int $cp): string
{
    return $cp < 0x80 ? chr($cp) : mb_chr($cp, 'UTF-8');
}

function cc_label(int $cp): string
{
    return sprintf('U+%04X', $cp);
}

/**
 * label => [field, callable(string $value): HttpResult]. Every other field of the body is valid.
 * @return array<string,array{0:string,1:callable}>
 */
function cc_targets(TestApp $app): array
{
    $hq = $app->client('hq.admin');
    $hub = $app->client('hub.user');
    $recipe = static fn (array $over): array => $over + [
        'finished_sku' => 'CTL-R1', 'name' => 'Control test brownie', 'unit' => 'each',
        'lines' => [['sku' => 'SMP001', 'qty' => 0.05]],
    ];
    return [
        'ingredient name' => ['name', static fn (string $v): HttpResult => $hq->put('/api/admin/ingredients/CTL-1', ['name' => $v, 'unit' => 'kg', 'cost_price' => 1.5])],
        'ingredient unit' => ['unit', static fn (string $v): HttpResult => $hq->put('/api/admin/ingredients/CTL-1', ['name' => 'Control test flour', 'unit' => $v, 'cost_price' => 1.5])],
        'order notes' => ['notes', static fn (string $v): HttpResult => $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]], 'notes' => $v])],
        'batch notes' => ['notes', static fn (string $v): HttpResult => $hub->post('/api/batches', ['recipe_id' => 1, 'quantity' => 1, 'notes' => $v])],
        'inventory reason' => ['reason', static fn (string $v): HttpResult => $hub->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 1, 'reason' => $v])],
        'recipe name (create)' => ['name', static fn (string $v): HttpResult => $hub->post('/api/recipes', $recipe(['name' => $v]))],
        'recipe unit (create)' => ['unit', static fn (string $v): HttpResult => $hub->post('/api/recipes', $recipe(['unit' => $v]))],
        'recipe name (update)' => ['name', static fn (string $v): HttpResult => $hq->put('/api/recipes/1', $recipe(['finished_sku' => 'SMP-F01', 'name' => $v]))],
    ];
}

/** Table row counts that a refused request must not change. */
function cc_counts(TestApp $app): array
{
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $out = [];
    foreach (['ingredients', 'orders', 'recipes', 'batches', 'inventory_movements'] as $table) {
        $out[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    return $out;
}

/** Sends every value to every target; returns the cases that were NOT refused with 422 + errors.<field>. */
function cc_accepted(array $targets, array $values): array
{
    $bad = [];
    foreach ($targets as $label => [$field, $send]) {
        foreach ($values as $tag => $value) {
            if (in_array($label, ['order notes', 'batch notes'], true) && in_array((int) hexdec(substr($tag, 2, 4)), [0x0A, 0x0D], true)) {
                continue; // LF and CR are allowed in the two multi-line notes fields (see C4-06)
            }
            $r = $send($value);
            $refused = $r->status === 422 && ($r->json['code'] ?? null) === 'VALIDATION_ERROR' && isset($r->json['errors'][$field]);
            if (!$refused) {
                $bad[$label][] = $tag . ' -> HTTP ' . $r->status;
            }
        }
    }
    return $bad;
}

function cc_summary(array $bad): string
{
    $parts = [];
    foreach ($bad as $label => $cases) {
        $parts[] = $label . ': ' . count($cases) . ' not refused (e.g. ' . implode(', ', array_slice($cases, 0, 3)) . ')';
    }
    return implode(' | ', $parts);
}

test('C4-01: every C0 control, DEL and C1 control in the middle of a value is refused with 422 naming the field', function (): void {
    $app = TestApp::start();
    $targets = cc_targets($app);
    $before = cc_counts($app);
    $values = [];
    foreach (cc_codepoints() as $cp) {
        $values[cc_label($cp) . ' in the middle'] = 'Ab' . cc_char($cp) . 'Cd';
    }
    $bad = cc_accepted($targets, $values);
    assert_same([], $bad, 'control characters accepted: ' . cc_summary($bad));
    assert_same($before, cc_counts($app), 'a refused request must not write a row');
});

test('C4-02: a control character at the start or end of a value, or a value that is only one, is refused (not trimmed away)', function (): void {
    $app = TestApp::start();
    $targets = cc_targets($app);
    $before = cc_counts($app);
    $values = [];
    foreach ([0x00, 0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x1F, 0x7F, 0x85, 0x9F] as $cp) {
        $values[cc_label($cp) . ' at the start'] = cc_char($cp) . 'Abcd';
        $values[cc_label($cp) . ' at the end'] = 'Abcd' . cc_char($cp);
        $values[cc_label($cp) . ' alone'] = cc_char($cp);
        $values[cc_label($cp) . ' between spaces'] = ' ' . cc_char($cp) . ' ';
    }
    $bad = cc_accepted($targets, $values);
    assert_same([], $bad, 'control characters accepted at an edge: ' . cc_summary($bad));
    assert_same($before, cc_counts($app), 'a refused request must not write a row');
});

test('C4-06: order and batch notes accept LF and CR; only-whitespace notes are accepted as empty (null); other controls stay refused there', function (): void {
    $app = TestApp::start();
    $hub = $app->client('hub.user');
    $sends = [
        'order notes' => static fn (string $v): HttpResult => $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]], 'notes' => $v]),
        'batch notes' => static fn (string $v): HttpResult => $hub->post('/api/batches', ['recipe_id' => 1, 'quantity' => 1, 'notes' => $v]),
    ];
    $key = ['order notes' => 'order', 'batch notes' => 'batch'];
    foreach ($sends as $label => $send) {
        foreach (['LF' => "Line one\nline two", 'CRLF' => "a\r\nb", 'CR' => "a\rb"] as $tag => $value) {
            $r = $send($value);
            assert_success($r, 201, $label . ' accepts ' . $tag);
            assert_same($value, $r->json[$key[$label]]['notes'] ?? null, $label . ' stored as sent (' . $tag . ')');
        }
        foreach (['only LF' => "\n", 'only CRLF' => "\r\n", 'only spaces' => '   ', 'spaces and newlines' => " \n \r\n "] as $tag => $value) {
            $r = $send($value);
            assert_success($r, 201, $label . ' accepts ' . $tag . ' (trimmed to empty)');
            $o = $r->json[$key[$label]];
            assert_true(array_key_exists('notes', $o) && $o['notes'] === null, $label . ': ' . $tag . ' is stored as null');
        }
        foreach ([0x09 => 'tab', 0x00 => 'NUL', 0x0B => 'VT', 0x0C => 'FF', 0x1B => 'ESC', 0x7F => 'DEL', 0x85 => 'NEL'] as $cp => $name) {
            assert_error($send('x' . cc_char($cp) . 'y'), 422, 'VALIDATION_ERROR', $label . ' still refuses ' . $name);
            assert_error($send("line\n" . cc_char($cp)), 422, 'VALIDATION_ERROR', $label . ' still refuses ' . $name . ' next to a newline');
        }
    }
});

test('C4-03: the C1 range is exactly U+0080-U+009F: U+00A0 (no-break space) and U+00A1 are ordinary text', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    foreach ([0xA0 => 'CTL-A0', 0xA1 => 'CTL-A1'] as $cp => $sku) {
        $r = $hq->put('/api/admin/ingredients/' . $sku, ['name' => 'Fine' . mb_chr($cp, 'UTF-8') . 'flour', 'unit' => 'kg', 'cost_price' => 1]);
        assert_success($r, 201, cc_label($cp) . ' is not a control character');
    }
    $r = $hq->put('/api/admin/ingredients/CTL-9F', ['name' => 'Fine' . mb_chr(0x9F, 'UTF-8') . 'flour', 'unit' => 'kg', 'cost_price' => 1]);
    assert_error($r, 422, 'VALIDATION_ERROR', 'U+009F is a control character');
});

test('C4-04: bin/load_hub_list.php refuses every C0 (including tab and CR), DEL and C1 control in a name, by line number, and changes nothing', function (): void {
    $app = TestApp::start();
    $rows = [];
    $lineOf = [];
    $line = 2; // line 1 is the header
    foreach (cc_codepoints() as $cp) {
        if ($cp === 0x0A) {
            continue; // a newline ends the CSV row; it cannot sit inside a cell of this loader
        }
        $rows[] = sprintf('TST%03d,Test Ab%sCd,kg,1,10.00,', count($rows) + 100, cc_char($cp));
        $lineOf[$cp] = $line++;
    }
    $rows[] = 'TST999,Cr' . "\u{e8}" . 'me fra' . "\u{ee}" . "che d'Isigny,kg,1,10.00,"; // a valid row: accents and an apostrophe
    $csv = "sku,name,unit,pack_size,pack_price,fincon_item_no\n" . implode("\n", $rows) . "\n";
    $file = $app->dir . '/cc-names.csv';
    file_put_contents($file, $csv);
    $pdo = new PDO('sqlite:' . $app->dbPath());
    $before = (int) $pdo->query('SELECT COUNT(*) FROM ingredients')->fetchColumn();

    [$code, $out] = hub_php(['bin/load_hub_list.php', $file], $app->env);
    $missing = [];
    foreach ($lineOf as $cp => $n) {
        if (preg_match('/^line ' . $n . ':[^\n]*\bname\b/m', $out) !== 1) {
            $missing[] = cc_label($cp) . ' (line ' . $n . ')';
        }
    }
    assert_same([], $missing, 'name rows with a control character that the loader did not report as a name error; exit ' . $code . '; output starts: ' . hub_snippet($out, 200));
    assert_same(1, $code, 'exit code for an invalid file');
    assert_same($before, (int) $pdo->query('SELECT COUNT(*) FROM ingredients')->fetchColumn(), 'nothing changed (all or nothing)');
    assert_true(preg_match('/^line ' . ($line) . ':/m', $out) !== 1, 'the valid accented row is not reported as an error: ' . hub_snippet($out, 300));
});

test('C4-05: ordinary text with spaces, accents, apostrophes and punctuation is still accepted everywhere', function (): void {
    $app = TestApp::start();
    $hq = $app->client('hq.admin');
    $hub = $app->client('hub.user');
    $text = "Cr\u{e8}me fra\u{ee}che d'Isigny (25 kg) - 5% \u{2013} \"A\" & B, caf\u{e9}";

    assert_success($hq->put('/api/admin/ingredients/CTL-OK', ['name' => $text, 'unit' => 'tub', 'cost_price' => 12.5]), 201, 'ingredient name with accents and apostrophe');
    $order = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]], 'notes' => $text]);
    assert_success($order, 201, 'order notes');
    assert_same($text, $order->json['order']['notes'] ?? null, 'order notes stored as sent');
    $batch = $hub->post('/api/batches', ['recipe_id' => 1, 'quantity' => 1, 'notes' => $text]);
    assert_success($batch, 201, 'batch notes');
    assert_same($text, $batch->json['batch']['notes'] ?? null, 'batch notes stored as sent');
    $adj = $hub->post('/api/inventory/adjustments', ['sku' => 'SMP001', 'qty_delta' => 1, 'reason' => $text]);
    assert_success($adj, 201, 'inventory reason');
    assert_success($hub->post('/api/recipes', [
        'finished_sku' => 'CTL-R9', 'name' => $text, 'unit' => 'each', 'lines' => [['sku' => 'SMP001', 'qty' => 0.05]],
    ]), 201, 'recipe name');
    // Surrounding spaces are still trimmed, as before.
    $trim = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 1]], 'notes' => '  Back door please  ']);
    assert_success($trim, 201, 'padded notes');
    assert_same('Back door please', $trim->json['order']['notes'] ?? null, 'padded notes trimmed');
});

run_tests();
