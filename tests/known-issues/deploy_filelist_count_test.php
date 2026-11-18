<?php
// Known issue, held for a decision by Damian; not auto-run (tests/run.php does not scan tests/known-issues).
// Expected red: the frozen deploy/build-bundle.ps1 prints "<n> files, <bytes> bytes" to stdout only, not to FILE-LIST.txt.
// Run by hand: php tests/known-issues/deploy_filelist_count_test.php
declare(strict_types=1);

require __DIR__ . '/../lib/harness.php';

test('A1 known issue: the first line of deploy/FILE-LIST.txt states the real file count', function (): void {
    $root = dirname(__DIR__, 2);
    $bundle = $root . '/deploy/upload/hub';
    $listFile = $root . '/deploy/FILE-LIST.txt';
    assert_true(is_dir($bundle), 'deploy/upload/hub exists');
    assert_true(is_file($listFile), 'deploy/FILE-LIST.txt exists');
    $real = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bundle, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $real++;
        }
    }
    $first = (string) (preg_split('/\R/', (string) file_get_contents($listFile))[0] ?? '');
    assert_true(
        preg_match('/^(\d+) files/', $first, $m) === 1,
        'first line of FILE-LIST.txt matches /^(\d+) files/: got ' . hub_export($first)
    );
    assert_same($real, (int) $m[1], 'file count stated on the first line of FILE-LIST.txt');
});

run_tests();
