<?php
declare(strict_types=1);

/*
 * Syncs the repo into the upload bundle (default deploy/upload/hub) without deleting anything.
 * Same content rules as deploy/build-bundle.ps1: public/, src/, db/, bin/ recursively, plus
 * var/.htaccess, deploy/htaccess-root.txt -> .htaccess, deploy/config.local.php.TEMPLATE.
 *
 * Usage: php deploy/sync-bundle.php [--root <repo>] [--out <bundle dir>] [--list <file>]
 *   --list defaults to <repo>/deploy/FILE-LIST.txt only when --out is not given.
 *
 * Files in the bundle with no source are reported as "EXTRA <path>" and the exit code is 1
 * (the copy and the list still happen). Never copies config.local.php, .env, tests/,
 * *.sqlite or *.db-journal.
 */

function fail(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(2);
}

function skipName(string $name): bool
{
    $n = strtolower($name);
    return $n === 'config.local.php' || $n === '.env' || $n === 'tests'
        || str_ends_with($n, '.sqlite') || str_ends_with($n, '.db-journal');
}

/** @return array<string,string> relative path (forward slashes) => absolute source path */
function collect(string $dir, string $rel, bool $skip): array
{
    $found = [];
    $items = scandir($dir);
    if ($items === false) {
        fail("cannot read $dir");
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $abs = $dir . '/' . $item;
        $r = $rel . '/' . $item;
        if ($skip && skipName($item)) {
            continue;
        }
        if (is_dir($abs)) {
            $found += collect($abs, $r, $skip);
        } elseif (is_file($abs)) {
            $found[$r] = $abs;
        }
    }
    return $found;
}

$opts = getopt('', ['root:', 'out:', 'list:']);
$root = isset($opts['root']) ? (string)$opts['root'] : dirname(__DIR__);
$rootReal = realpath($root);
if ($rootReal === false || !is_dir($rootReal)) {
    fail("root not found: $root");
}
$root = str_replace('\\', '/', $rootReal);
$outGiven = isset($opts['out']);
$out = $outGiven ? (string)$opts['out'] : $root . '/deploy/upload/hub';
$out = rtrim(str_replace('\\', '/', $out), '/');
if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
    fail("cannot create $out");
}
$list = isset($opts['list']) ? (string)$opts['list'] : ($outGiven ? null : $root . '/deploy/FILE-LIST.txt');

// Source map: relative path in bundle => source file.
$map = [];
foreach (['public', 'src', 'db', 'bin'] as $d) {
    if (!is_dir("$root/$d")) {
        fail("missing source dir $root/$d");
    }
    foreach (collect("$root/$d", $d, true) as $r => $abs) {
        $map[ltrim($r, '/')] = $abs;
    }
}
$special = [
    'var/.htaccess' => "$root/var/.htaccess",
    '.htaccess' => "$root/deploy/htaccess-root.txt",
    'config.local.php.TEMPLATE' => "$root/deploy/config.local.php.TEMPLATE",
];
foreach ($special as $r => $abs) {
    if (!is_file($abs)) {
        fail("missing source file $abs");
    }
    $map[$r] = $abs;
}

// Copy byte-for-byte, overwriting.
$copied = 0;
foreach ($map as $r => $abs) {
    $dest = "$out/$r";
    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail("cannot create $dir");
    }
    if (!copy($abs, $dest)) {
        fail("copy failed: $r");
    }
    $copied++;
}

// Everything now in the bundle (dotfiles included); anything without a source is EXTRA.
$present = [];
foreach (collect($out, '', false) as $r => $abs) {
    $present[ltrim($r, '/')] = $abs;
}
$paths = array_keys($present);
usort($paths, 'strcmp');
$extra = [];
foreach ($paths as $p) {
    if (!isset($map[$p])) {
        $extra[] = $p;
        echo "EXTRA $p\n";
    }
}

if ($list !== null) {
    $bytes = 0;
    $lines = [];
    foreach ($paths as $p) {
        $size = filesize($present[$p]);
        $bytes += $size;
        $lines[] = $size . '  ' . $p;
    }
    // Like build-bundle.ps1: the "<n> files, <bytes> bytes" header goes to the console only;
    // the file holds one "<size>  <path>" line per file (the Deploy parity test requires this).
    echo count($paths) . " files, $bytes bytes\n";
    $text = implode("\r\n", $lines) . "\r\n";
    if (file_put_contents($list, $text) === false) {
        fail("cannot write $list");
    }
}

echo "Copied $copied files to $out; " . count($paths) . " files in bundle; " . count($extra) . " extra\n";
exit($extra === [] ? 0 : 1);
