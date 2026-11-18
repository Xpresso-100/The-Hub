<?php
declare(strict_types=1);

// A1 (BUILD-SPEC 2026-11-18 s10, Lane A): the upload copy deploy/upload/hub/ is exactly what
// deploy/build-bundle.ps1 would build from the code on disk now. Read only: this test writes nothing.

require __DIR__ . '/../../lib/harness.php';

function parity_root(): string
{
    return dirname(__DIR__, 3);
}

function parity_bundle(): string
{
    return parity_root() . '/deploy/upload/hub';
}

/** Every file under $dir (dotfiles included), as relative path with forward slashes => absolute path. */
function parity_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $out[$rel] = $file->getPathname();
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** Fails with a list of the problems, capped so the line stays readable. */
function parity_report(array $problems, string $what): void
{
    if ($problems === []) {
        return;
    }
    $shown = array_slice($problems, 0, 25);
    $more = count($problems) > 25 ? ' ... and ' . (count($problems) - 25) . ' more' : '';
    hub_fail($what . ': ' . count($problems) . ' problem(s): ' . implode('; ', $shown) . $more);
}

function parity_same_file(string $bundleRel, string $source): void
{
    $bundled = parity_bundle() . '/' . $bundleRel;
    assert_true(is_file($source), 'source exists: ' . $source);
    assert_true(is_file($bundled), 'bundle holds ' . $bundleRel);
    assert_same(sha1_file($source), sha1_file($bundled), 'SHA-1 of bundle ' . $bundleRel . ' equals its source');
}

test('A1: bundle public, src, db and bin hold the same files as the repo, with the same SHA-1', function (): void {
    assert_true(is_dir(parity_bundle()), 'deploy/upload/hub exists');
    $problems = [];
    foreach (['public', 'src', 'db', 'bin'] as $dir) {
        $repo = parity_files(parity_root() . '/' . $dir);
        $bundle = parity_files(parity_bundle() . '/' . $dir);
        assert_true($repo !== [], 'repo ' . $dir . '/ has files');
        foreach ($repo as $rel => $path) {
            if (!isset($bundle[$rel])) {
                $problems[] = 'missing ' . $dir . '/' . $rel;
            } elseif (sha1_file($path) !== sha1_file($bundle[$rel])) {
                $problems[] = 'different ' . $dir . '/' . $rel;
            }
        }
        foreach (array_keys(array_diff_key($bundle, $repo)) as $rel) {
            $problems[] = 'extra ' . $dir . '/' . $rel;
        }
    }
    parity_report($problems, 'bundle code parity');
});

test('A1: bundle .htaccess equals deploy/htaccess-root.txt', function (): void {
    parity_same_file('.htaccess', parity_root() . '/deploy/htaccess-root.txt');
});

test('A1: bundle var/.htaccess equals the repo var/.htaccess', function (): void {
    parity_same_file('var/.htaccess', parity_root() . '/var/.htaccess');
});

test('A1: bundle config.local.php.TEMPLATE equals deploy/config.local.php.TEMPLATE', function (): void {
    parity_same_file('config.local.php.TEMPLATE', parity_root() . '/deploy/config.local.php.TEMPLATE');
});

test('A1: bundle holds no config.local.php, no .env and no tests/ folder, at any depth', function (): void {
    assert_true(is_dir(parity_bundle()), 'deploy/upload/hub exists');
    $problems = [];
    foreach (array_keys(parity_files(parity_bundle())) as $rel) {
        $parts = explode('/', $rel);
        $name = end($parts);
        if ($name === 'config.local.php') {
            $problems[] = 'secret config ' . $rel;
        }
        if ($name === '.env' || str_starts_with($name, '.env.')) {
            $problems[] = 'env file ' . $rel;
        }
        if (in_array('tests', array_slice($parts, 0, -1), true)) {
            $problems[] = 'tests folder file ' . $rel;
        }
    }
    $dirs = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(parity_bundle(), FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($dirs as $item) {
        if ($item->isDir() && $item->getFilename() === 'tests') {
            $problems[] = 'tests folder ' . str_replace('\\', '/', substr($item->getPathname(), strlen(parity_bundle()) + 1));
        }
    }
    parity_report(array_values(array_unique($problems)), 'bundle forbidden content');
});

test('A1: deploy/FILE-LIST.txt has one "<size>  <path>" line per bundle file, matching size and path', function (): void {
    $listFile = parity_root() . '/deploy/FILE-LIST.txt';
    assert_true(is_file($listFile), 'deploy/FILE-LIST.txt exists');
    $real = parity_files(parity_bundle());
    $lines = preg_split('/\R/', (string) file_get_contents($listFile)) ?: [];
    while ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }
    $problems = [];
    $listed = [];
    foreach ($lines as $i => $line) {
        if (!preg_match('/^(\d+)  (\S.*)$/', $line, $m)) {
            $problems[] = 'line ' . ($i + 1) . ' is not "<size>  <path>": ' . hub_snippet($line, 80);
            continue;
        }
        [$size, $path] = [(int) $m[1], $m[2]];
        if (isset($listed[$path])) {
            $problems[] = 'listed twice ' . $path;
        }
        $listed[$path] = true;
        if (!isset($real[$path])) {
            $problems[] = 'listed but not in bundle ' . $path;
        } elseif (filesize($real[$path]) !== $size) {
            $problems[] = 'size ' . $path . ' listed ' . $size . ', real ' . filesize($real[$path]);
        }
    }
    foreach (array_keys(array_diff_key($real, $listed)) as $path) {
        $problems[] = 'not listed ' . $path;
    }
    if (count($lines) !== count($real)) {
        array_unshift($problems, 'line count ' . count($lines) . ', real file count ' . count($real));
    }
    parity_report($problems, 'FILE-LIST.txt');
});

run_tests();
