<?php
declare(strict_types=1);

// php bin/import_ingredients.php <file.csv>   (M1, SPEC s2.3)
// Upserts the hub ingredient catalogue from CSV rows: sku,name,unit,cost_price,fincon_item_no
// - optional header row (first cell "sku"), optional UTF-8 BOM, blank lines ignored
// - cost_price in rands excl. VAT, at most 2 decimals; fincon_item_no may be empty (unmapped)
// - all or nothing: any invalid row -> every problem listed with its line number, nothing imported, exit 1
// Writes only The Hub's own ingredients table. Never touches FinCon.

require dirname(__DIR__) . '/src/bootstrap.php';

use Hub\Clock;
use Hub\Db;

$file = $argv[1] ?? '';
if ($file === '' || count($argv) > 2) {
    fwrite(STDERR, "Usage: php bin/import_ingredients.php <file.csv>\n");
    exit(2);
}
if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Cannot read file: $file\n");
    exit(1);
}

$handle = fopen($file, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Cannot open file: $file\n");
    exit(1);
}

$rows = [];
$errors = [];
$seen = [];
$line = 0;
while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
    $line++;
    if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
        continue;
    }
    if ($line === 1 && isset($cells[0])) {
        $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
    }
    $cells = array_map(static fn ($c): string => trim((string) $c), $cells);
    if ($rows === [] && $errors === [] && $seen === [] && strtolower($cells[0]) === 'sku') {
        continue; // header
    }

    $problems = [];
    if (count($cells) < 4 || count($cells) > 5) {
        $errors[] = "line $line: expected 4 or 5 columns (sku,name,unit,cost_price,fincon_item_no), got " . count($cells);
        continue;
    }
    [$sku, $name, $unit, $cost] = $cells;
    $itemNo = $cells[4] ?? '';

    if (preg_match('/^[A-Z0-9-]{2,20}$/', $sku) !== 1) {
        $problems[] = 'sku must be 2-20 characters A-Z, 0-9 or -';
    } elseif (isset($seen[$sku])) {
        $problems[] = 'sku ' . $sku . ' already on line ' . $seen[$sku];
    } else {
        $seen[$sku] = $line;
    }
    $nameLen = mb_strlen($name);
    if ($nameLen < 1 || $nameLen > 150 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        $problems[] = 'name must be 1-150 characters';
    }
    $unitLen = mb_strlen($unit);
    if ($unitLen < 1 || $unitLen > 20 || preg_match('/[\x00-\x1F\x7F]/', $unit) === 1) {
        $problems[] = 'unit must be 1-20 characters';
    }
    $cents = null;
    if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $cost, $m) === 1) {
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    } else {
        $problems[] = 'cost_price must be a number >= 0 with at most 2 decimals (e.g. 12.50)';
    }
    if (mb_strlen($itemNo) > 25 || preg_match('/[\x00-\x1F\x7F]/', $itemNo) === 1) {
        $problems[] = 'fincon_item_no must be at most 25 characters';
    }

    if ($problems !== []) {
        $errors[] = "line $line: " . implode('; ', $problems);
        continue;
    }
    $rows[] = [$sku, $name, $unit, $cents, $itemNo === '' ? null : $itemNo];
}
fclose($handle);

if ($errors === [] && $rows === []) {
    $errors[] = 'no ingredient rows found';
}
if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, $e . "\n");
    }
    fwrite(STDERR, "Nothing imported.\n");
    exit(1);
}

[$created, $updated] = Db::transaction(static function () use ($rows): array {
    $now = Clock::nowDb();
    $created = 0;
    $updated = 0;
    foreach ($rows as [$sku, $name, $unit, $cents, $itemNo]) {
        if ((int) Db::value('SELECT COUNT(*) FROM ingredients WHERE sku = ?', [$sku]) > 0) {
            Db::exec(
                'UPDATE ingredients SET name = ?, unit = ?, cost_cents = ?, fincon_item_no = ?, active = 1, updated_at = ? WHERE sku = ?',
                [$name, $unit, $cents, $itemNo, $now, $sku]
            );
            $updated++;
        } else {
            Db::exec(
                'INSERT INTO ingredients (sku, name, unit, cost_cents, fincon_item_no, is_sample, active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, 1, ?, ?)',
                [$sku, $name, $unit, $cents, $itemNo, $now, $now]
            );
            $created++;
        }
    }
    return [$created, $updated];
});

echo "import_ingredients: $created created, $updated updated\n";
