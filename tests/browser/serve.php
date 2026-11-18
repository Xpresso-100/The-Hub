<?php
declare(strict_types=1);

// Browser-test server (build spec 2026-11-18 E2.1).
//
//   php tests/browser/serve.php --root repo     serve the working copy's public/ folder
//   php tests/browser/serve.php --root bundle   serve deploy/upload/hub (the upload bundle), same checks
//
// Boots the app with the tests/lib/harness.php machinery (TestApp::start: fresh SQLite file, migrate,
// seed --fixtures, php -S on a free port), prints "PORT=<n>" on one line and stays up until stdin is closed.
// Then it stops the server and removes its scratch folders. Test database only; never a real one.
// Fixture logins are the ones in bin/seed.php (see HUB_FIXTURE_USERS in the harness).

$root = null;
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--root' && isset($args[$i + 1])) {
        $root = $args[++$i];
    } elseif (str_starts_with($args[$i], '--root=')) {
        $root = substr($args[$i], 7);
    } else {
        fwrite(STDERR, "serve.php: unknown argument " . $args[$i] . "\n");
        exit(2);
    }
}
if ($root !== 'repo' && $root !== 'bundle') {
    fwrite(STDERR, "Usage: php tests/browser/serve.php --root repo|bundle\n");
    exit(2);
}

$repo = dirname(__DIR__, 2);
$appRoot = $root === 'repo' ? $repo : $repo . '/deploy/upload/hub';
foreach (['/public/index.php', '/bin/migrate.php', '/bin/seed.php'] as $needed) {
    if (!is_file($appRoot . $needed)) {
        fwrite(STDERR, "serve.php: " . $appRoot . $needed . " is missing\n");
        exit(1);
    }
}

// The harness takes its root from this constant (app folder, scratch folder, php -S docroot).
define('HUB_TEST_ROOT', $appRoot);
require $repo . '/tests/lib/harness.php';

// A run that was killed can leave <root>/var/test behind; remove that exact folder (and nothing else) first.
// Bundle mode only: in repo mode var/test also holds the scratch folders of a concurrent `php tests/run.php`.
$staleTestDir = HUB_TEST_ROOT . '/var/test';
if ($root === 'bundle' && is_dir($staleTestDir) && !is_link($staleTestDir)) {
    hub_rmdir($staleTestDir);
}

$cleanup = static function (): void {
    ServerProcess::stopAll();
    foreach ($GLOBALS['hub_test_dirs'] ?? [] as $dir) {
        hub_rmdir($dir);
    }
    $GLOBALS['hub_test_dirs'] = [];
    // The harness makes <root>/var/test; remove it again when empty so the bundle folder stays as built.
    @rmdir(HUB_TEST_ROOT . '/var/test');
};

try {
    $app = TestApp::start();
} catch (Throwable $e) {
    fwrite(STDERR, 'serve.php: could not start the app: ' . hub_one_line($e->getMessage()) . "\n");
    $cleanup();
    exit(1);
}

echo 'PORT=' . $app->server->port . "\n";
fflush(STDOUT);

// Block until the parent closes our stdin (or exits).
while (fgets(STDIN) !== false) {
    // ignore any input
}

$cleanup();
exit(0);
