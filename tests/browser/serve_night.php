<?php
declare(strict_types=1);

// Browser-test server for the overnight end-to-end script (task T-09, tests/browser/e2e_night.cjs).
// Same approach as tests/browser/serve.php, plus one flag:
//
//   php tests/browser/serve_night.php --root repo                 serve the working copy's public/ folder
//   php tests/browser/serve_night.php --root bundle               serve deploy/upload/hub (the upload bundle)
//   php tests/browser/serve_night.php --root repo --fincon-down   FinCon HTTP adapter pointed at a closed localhost port
//
// Boots the app with the tests/lib/harness.php machinery (TestApp::start: fresh SQLite file, migrate,
// seed --fixtures, php -S on a free port), prints "PORT=<n>" and "DB=<sqlite path>" and stays up until stdin
// is closed. Then it stops the server and removes its scratch folders. Test database only; never a real one.
// The FINCON_* values below are the harness's test placeholders (tests/lib/harness.php FakeFincon::appEnv and
// tests/acceptance/a7_hub_reads_fincon_stock_test.php); nothing listens on the port, so every read fails.

$root = null;
$finconDown = false;
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--root' && isset($args[$i + 1])) {
        $root = $args[++$i];
    } elseif (str_starts_with($args[$i], '--root=')) {
        $root = substr($args[$i], 7);
    } elseif ($args[$i] === '--fincon-down') {
        $finconDown = true;
    } else {
        fwrite(STDERR, "serve_night.php: unknown argument " . $args[$i] . "\n");
        exit(2);
    }
}
if ($root !== 'repo' && $root !== 'bundle') {
    fwrite(STDERR, "Usage: php tests/browser/serve_night.php --root repo|bundle [--fincon-down]\n");
    exit(2);
}

$repo = dirname(__DIR__, 2);
$appRoot = $root === 'repo' ? $repo : $repo . '/deploy/upload/hub';
foreach (['/public/index.php', '/bin/migrate.php', '/bin/seed.php'] as $needed) {
    if (!is_file($appRoot . $needed)) {
        fwrite(STDERR, "serve_night.php: " . $appRoot . $needed . " is missing\n");
        exit(1);
    }
}

define('HUB_TEST_ROOT', $appRoot);
require $repo . '/tests/lib/harness.php';

$cleanup = static function (): void {
    ServerProcess::stopAll();
    foreach ($GLOBALS['hub_test_dirs'] ?? [] as $dir) {
        hub_rmdir($dir);
    }
    $GLOBALS['hub_test_dirs'] = [];
    // Remove <root>/var/test again only when empty (a concurrent `php tests/run.php` may be using it).
    @rmdir(HUB_TEST_ROOT . '/var/test');
};

$env = [];
if ($finconDown) {
    $env = [
        'FINCON_ADAPTER' => 'http',
        'FINCON_BASE_URL' => 'http://127.0.0.1:' . hub_free_port(),
        'FINCON_CF_CLIENT_ID' => 'test-cf-client-id',
        'FINCON_CF_CLIENT_SECRET' => 'test-cf-client-secret',
        'FINCON_SRV_USER' => 'test-srv-user',
        'FINCON_SRV_PASS' => 'test-srv-pass',
        'FINCON_DATA_ID' => 'TESTDATA',
        'FINCON_USER' => 'test-user',
        'FINCON_PASS' => 'test-pass',
        'FINCON_LOC_NO' => '0',
        'FINCON_TIMEOUT_SECONDS' => '5',
    ];
}

try {
    $app = TestApp::start($env);
} catch (Throwable $e) {
    fwrite(STDERR, 'serve_night.php: could not start the app: ' . hub_one_line($e->getMessage()) . "\n");
    $cleanup();
    exit(1);
}

echo 'DB=' . $app->dbPath() . "\n";
echo 'PORT=' . $app->server->port . "\n";
fflush(STDOUT);

while (fgets(STDIN) !== false) {
    // ignore any input
}

$cleanup();
exit(0);
