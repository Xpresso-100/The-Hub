<?php
declare(strict_types=1);

// php bin/load_hub_list.php [--dry-run] [--deactivate-samples] <file.csv>
// Loads the Hub ingredient list (docs/BUILD-SPEC-2026-11-18-go-live.md, Lane C).
// Header, exactly: sku,name,unit,pack_size,pack_price,fincon_item_no   (delimiter , or ; taken from the header)
// - pack_size in the unit (> 0, at most 3 decimals); pack_price in rands excl. VAT (>= 0, at most 2 decimals)
// - with ; a decimal comma is accepted; spaces and non-breaking spaces in numbers are ignored; UTF-8 BOM stripped
// - blank lines and lines with both sku and name empty are skipped; line numbers are physical lines
// - size and price > 0  -> cost per unit = round_half_up(price_cents * 1000, size_milli), active, not a sample (ACTIVE)
// - price/size blank or price 0 -> new sku inserted switched off at cost 0 (OFF); existing sku keeps cost and
//   active, only name, unit and fincon_item_no are updated (KEPT). A row is never made active at R0.00.
// - all or nothing: any invalid row -> every problem listed with its line number, nothing changed, exit 1
// - --dry-run writes nothing; --deactivate-samples sets active = 0 on every is_sample = 1 row
// Exit 0 OK, 1 invalid, 2 usage. Writes only The Hub's own ingredients table. Never touches FinCon.

require dirname(__DIR__) . '/src/bootstrap.php';

use Hub\Clock;
use Hub\Db;
use Hub\Money;

const HLL_COLUMNS = ['sku', 'name', 'unit', 'pack_size', 'pack_price', 'fincon_item_no'];
const HLL_USAGE = "Usage: php bin/load_hub_list.php [--dry-run] [--deactivate-samples] <file.csv>\n";
const HLL_DOT = "\u{00B7}";

function hll_usage(string $problem): never
{
    fwrite(STDERR, $problem . "\n" . HLL_USAGE);
    exit(2);
}

/** Cents -> "R1 234.56". */
function hll_rands(int $cents): string
{
    $abs = abs($cents);
    $whole = (string) intdiv($abs, 100);
    $grouped = '';
    while (strlen($whole) > 3) {
        $grouped = ' ' . substr($whole, -3) . $grouped;
        $whole = substr($whole, 0, -3);
    }
    return ($cents < 0 ? '-' : '') . 'R' . $whole . $grouped . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
}

/** Difference in cents -> "R0.00", "+R1.50", "-R0.25". */
function hll_diff(int $cents): string
{
    return $cents > 0 ? '+' . hll_rands($cents) : hll_rands($cents);
}

/** Milli -> "24", "12.5", "0.125". */
function hll_qty(int $milli): string
{
    $frac = rtrim(str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT), '0');
    return intdiv($milli, 1000) . ($frac === '' ? '' : '.' . $frac);
}

/**
 * A decimal number as an integer scaled by 10^$decimals, with integer arithmetic only.
 * '' -> null (blank); not a number >= 0 with at most $decimals decimals -> false.
 */
function hll_number(string $raw, int $decimals, string $delimiter): int|false|null
{
    $value = str_replace([' ', "\t", "\xC2\xA0", "\xE2\x80\xAF"], '', $raw);
    if ($value === '') {
        return null;
    }
    $point = $delimiter === ';' ? '[.,]' : '\.';
    if (preg_match('/^(\d{1,9})(?:' . $point . '(\d{1,' . $decimals . '}))?$/', $value, $m) !== 1) {
        return false;
    }
    return (int) $m[1] * (10 ** $decimals) + (int) str_pad($m[2] ?? '', $decimals, '0');
}

function hll_text_ok(string $value, int $min, int $max): bool
{
    $len = mb_strlen($value, 'UTF-8');
    return $len >= $min && $len <= $max && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
}

// ---------------------------------------------------------------- arguments

$dryRun = false;
$deactivateSamples = false;
$file = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--deactivate-samples') {
        $deactivateSamples = true;
    } elseif (str_starts_with($arg, '--')) {
        hll_usage('Unknown option: ' . $arg);
    } elseif ($file !== null) {
        hll_usage('Only one file, please.');
    } else {
        $file = $arg;
    }
}
if ($file === null || $file === '') {
    hll_usage('No file given.');
}
if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Cannot read file: $file\nNothing changed.\n");
    exit(1);
}
$content = file_get_contents($file);
if ($content === false) {
    fwrite(STDERR, "Cannot read file: $file\nNothing changed.\n");
    exit(1);
}

// ---------------------------------------------------------------- parse and validate (no database access)

$content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
$lines = explode("\n", $content);
$errors = [];
$rows = [];
$seen = [];
$delimiter = null;

foreach ($lines as $index => $text) {
    $lineNo = $index + 1;
    $text = rtrim($text, "\r");
    if (trim($text) === '') {
        continue;
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $errors[] = "line $lineNo: not valid UTF-8 (save the file as CSV UTF-8)";
        if ($delimiter === null) {
            break;
        }
        continue;
    }

    if ($delimiter === null) {
        foreach ([',', ';'] as $candidate) {
            $cells = array_map('trim', str_getcsv($text, $candidate, '"', ''));
            if ($cells === HLL_COLUMNS) {
                $delimiter = $candidate;
                break;
            }
        }
        if ($delimiter === null) {
            $errors[] = "line $lineNo: header must be exactly " . implode(',', HLL_COLUMNS) . ' (or the same with ;)';
            break;
        }
        continue;
    }

    $cells = array_map('trim', str_getcsv($text, $delimiter, '"', ''));
    if (($cells[0] ?? '') === '' && ($cells[1] ?? '') === '') {
        continue; // sku and name both empty
    }
    if (count($cells) !== 6) {
        $errors[] = "line $lineNo: expected 6 columns (" . implode($delimiter, HLL_COLUMNS) . '), got ' . count($cells);
        continue;
    }
    [$sku, $name, $unit, $rawSize, $rawPrice, $itemNo] = $cells;

    $problems = [];
    if (preg_match('/^[A-Z0-9-]{2,20}$/', $sku) !== 1) {
        $problems[] = 'sku must be 2-20 characters A-Z, 0-9 or -';
    } elseif (isset($seen[$sku])) {
        $problems[] = 'sku ' . $sku . ' already on line ' . $seen[$sku];
    } else {
        $seen[$sku] = $lineNo;
    }
    if (!hll_text_ok($name, 1, 150)) {
        $problems[] = 'name must be 1-150 characters';
    }
    if (!hll_text_ok($unit, 1, 20)) {
        $problems[] = 'unit must be 1-20 characters';
    }
    $sizeMilli = hll_number($rawSize, 3, $delimiter);
    if ($sizeMilli === false || $sizeMilli === 0) {
        $problems[] = 'pack_size must be blank or a number > 0 with at most 3 decimals';
    }
    $priceCents = hll_number($rawPrice, 2, $delimiter);
    if ($priceCents === false) {
        $problems[] = 'pack_price must be blank or a number >= 0 with at most 2 decimals (e.g. 1234.56)';
    }
    if (!hll_text_ok($itemNo, 0, 25)) {
        $problems[] = 'fincon_item_no must be blank or at most 25 characters';
    }

    $costCents = null;
    if ($problems === [] && is_int($sizeMilli) && is_int($priceCents) && $priceCents > 0) {
        $costCents = Money::roundHalfUp($priceCents * 1000, $sizeMilli);
        if ($costCents === 0) {
            $problems[] = 'cost per ' . $unit . ' rounds to R0.00; check pack_size and pack_price';
        }
    }

    if ($problems !== []) {
        $errors[] = "line $lineNo: " . implode('; ', $problems);
        continue;
    }
    $rows[] = [
        'sku' => $sku,
        'name' => $name,
        'unit' => $unit,
        'size' => $sizeMilli,
        'price' => $priceCents,
        'cost' => $costCents, // null = no usable price: OFF or KEPT
        'item' => $itemNo === '' ? null : $itemNo,
    ];
}

if ($delimiter === null && $errors === []) {
    $errors[] = 'no header line found; expected ' . implode(',', HLL_COLUMNS);
} elseif ($errors === [] && $rows === []) {
    $errors[] = 'no ingredient rows found';
}
if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, $e . "\n");
    }
    fwrite(STDERR, "Nothing changed.\n");
    exit(1);
}

// ---------------------------------------------------------------- plan (reads) and apply (writes, one transaction)

/** Reads current state and returns [report lines, counts, actions]. Writes nothing. */
function hll_plan(array $rows, bool $deactivateSamples): array
{
    $report = [];
    $actions = [];
    $counts = ['active' => 0, 'off' => 0, 'kept' => 0, 'samples' => 0];
    $priced = [];
    foreach ($rows as $row) {
        $old = Db::one('SELECT cost_cents, active FROM ingredients WHERE sku = ?', [$row['sku']]);
        if ($row['cost'] !== null) {
            $diff = $old === null ? 0 : $row['cost'] - (int) $old['cost_cents'];
            $report[] = $row['sku'] . ' ACTIVE per ' . $row['unit'] . ' ' . hll_rands($row['cost'])
                . ' ' . HLL_DOT . ' pack ' . hll_qty($row['size']) . ' ' . $row['unit'] . ' = ' . hll_rands($row['price'])
                . ' ' . HLL_DOT . ' diff ' . hll_diff($diff);
            $actions[] = [$old === null ? 'insert-active' : 'update-active', $row];
            $priced[$row['sku']] = true;
            $counts['active']++;
        } elseif ($old === null) {
            $report[] = $row['sku'] . ' OFF ' . HLL_DOT . ' no pack price or size; added switched off at R0.00';
            $actions[] = ['insert-off', $row];
            $counts['off']++;
        } else {
            $report[] = $row['sku'] . ' KEPT ' . HLL_DOT . ' no pack price or size; cost per ' . $row['unit'] . ' '
                . hll_rands((int) $old['cost_cents']) . ' and ' . ((int) $old['active'] === 1 ? 'active' : 'switched off') . ' unchanged';
            $actions[] = ['update-kept', $row];
            $counts['kept']++;
        }
    }
    if ($deactivateSamples) {
        foreach (Db::all('SELECT sku FROM ingredients WHERE is_sample = 1 AND active = 1 ORDER BY sku') as $sample) {
            if (!isset($priced[$sample['sku']])) {
                $report[] = $sample['sku'] . ' SAMPLE switched off';
                $counts['samples']++;
            }
        }
    }
    return [$report, $counts, $actions];
}

function hll_apply(array $actions, bool $deactivateSamples): int
{
    $now = Clock::nowDb();
    foreach ($actions as [$action, $r]) {
        if ($action === 'insert-active' || $action === 'insert-off') {
            Db::exec(
                'INSERT INTO ingredients (sku, name, unit, cost_cents, fincon_item_no, is_sample, active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)',
                [$r['sku'], $r['name'], $r['unit'], $r['cost'] ?? 0, $r['item'], $action === 'insert-active' ? 1 : 0, $now, $now]
            );
        } elseif ($action === 'update-active') {
            Db::exec(
                'UPDATE ingredients SET name = ?, unit = ?, cost_cents = ?, fincon_item_no = ?, is_sample = 0, active = 1, updated_at = ? WHERE sku = ?',
                [$r['name'], $r['unit'], $r['cost'], $r['item'], $now, $r['sku']]
            );
        } else {
            Db::exec(
                'UPDATE ingredients SET name = ?, unit = ?, fincon_item_no = ?, updated_at = ? WHERE sku = ?',
                [$r['name'], $r['unit'], $r['item'], $now, $r['sku']]
            );
        }
    }
    if (!$deactivateSamples) {
        return 0;
    }
    return Db::exec('UPDATE ingredients SET active = 0, updated_at = ? WHERE is_sample = 1 AND active = 1', [$now]);
}

if ($dryRun) {
    [$report, $counts] = hll_plan($rows, $deactivateSamples);
} else {
    [$report, $counts] = Db::transaction(static function () use ($rows, $deactivateSamples): array {
        [$report, $counts, $actions] = hll_plan($rows, $deactivateSamples);
        $switchedOff = hll_apply($actions, $deactivateSamples);
        if ($switchedOff !== $counts['samples']) {
            throw new \RuntimeException('sample count changed during the load (' . $switchedOff . ' vs ' . $counts['samples'] . '); nothing changed');
        }
        return [$report, $counts];
    });
}

foreach ($report as $line) {
    echo $line, "\n";
}
if ($dryRun) {
    echo "dry run: nothing written\n";
}
echo 'load_hub_list: ' . $counts['active'] . ' active, ' . $counts['off'] . ' off, ' . $counts['kept'] . ' kept, '
    . $counts['samples'] . " samples off\n";
