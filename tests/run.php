<?php
declare(strict_types=1);

// php tests/run.php [filter]
// Runs every *_test.php under tests/{smoke,unit,acceptance,e2e,security} (folders that exist), each in its
// own PHP process, prints one line per test and ends with exactly "RESULT: <passed> passed, <failed> failed".
// Exit 0 only if failed = 0 and passed > 0 (SPEC s2.6). Optional [filter] = substring of the file path.

$root = dirname(__DIR__);
$suites = ['smoke', 'unit', 'acceptance', 'e2e', 'security'];
$filter = $argv[1] ?? null;
$timeout = (int) (getenv('HUB_TEST_FILE_TIMEOUT') ?: 300);

// Fresh scratch space for the test apps of this run.
hub_runner_rmdir($root . '/var/test');

$files = [];
foreach ($suites as $suite) {
    $dir = $root . '/tests/' . $suite;
    if (!is_dir($dir)) {
        continue;
    }
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '_test.php')) {
            $found[] = $file->getPathname();
        }
    }
    sort($found, SORT_STRING);
    array_push($files, ...$found);
}

$passed = 0;
$failed = 0;
foreach ($files as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    if ($filter !== null && !str_contains($rel, $filter)) {
        continue;
    }
    echo "== $rel\n";
    [$code, $output, $timedOut] = hub_runner_exec($file, $root, $timeout);
    $filePassed = 0;
    $fileFailed = 0;
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, 'PASS ')) {
            $filePassed++;
        } elseif (str_starts_with($line, 'FAIL ')) {
            $fileFailed++;
        }
        echo $line, "\n";
    }
    if ($timedOut) {
        echo "FAIL $rel: timed out after {$timeout}s\n";
        $fileFailed++;
    } elseif ($code !== 0 && $fileFailed === 0) {
        echo "FAIL $rel: exited with code $code without reporting a failing test\n";
        $fileFailed++;
    } elseif ($filePassed === 0 && $fileFailed === 0) {
        echo "FAIL $rel: ran no tests\n";
        $fileFailed++;
    }
    $passed += $filePassed;
    $failed += $fileFailed;
}

echo "RESULT: $passed passed, $failed failed\n";
exit(($failed === 0 && $passed > 0) ? 0 : 1);

/** @return array{0:int,1:string,2:bool} exit code, combined output, timed out */
function hub_runner_exec(string $file, string $root, int $timeout): array
{
    $out = tempnam(sys_get_temp_dir(), 'hubtest');
    $proc = proc_open([PHP_BINARY, $file], [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['redirect', 1]], $pipes, $root);
    if (!is_resource($proc)) {
        return [1, 'FAIL ' . basename($file) . ': could not start PHP', false];
    }
    fclose($pipes[0]);
    $start = microtime(true);
    $code = 1;
    $timedOut = false;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $code = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) - $start > $timeout) {
            proc_terminate($proc);
            $timedOut = true;
            break;
        }
        usleep(50000);
    }
    proc_close($proc);
    $output = (string) @file_get_contents($out);
    @unlink($out);
    return [$code, $output, $timedOut];
}

function hub_runner_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}
