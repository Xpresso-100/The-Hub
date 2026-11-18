<?php
declare(strict_types=1);

// Hardening item 5: Config::isProduction() fails closed.
// True for 'production' in any capitalisation and padding, and for EVERY other value: 'Prod', 'PROD', 'prd',
// 'staging', typos, whitespace, empty. False ONLY for the explicit non-production names local, dev, development,
// test, testing (any capitalisation, surrounding whitespace ignored).
// Consequences checked: the FinCon mock adapter is refused (FinconFactory) and `bin/seed.php --fixtures` is
// refused whenever isProduction() is true, i.e. for APP_ENV=Prod too.
// How APP_ENV is read: Config::get() takes the real environment variable first (getenv), then config.local.php,
// then the default 'production'. The harness starts apps with APP_ENV=test. These tests set the variable with
// putenv() in this process (as tests/smoke/core_units_test.php does) and restore it in finally.
// Note: a child process cannot be given an EMPTY variable (proc_open drops empty entries), so the empty value is
// tested in-process only; in a child it behaves like "unset" and falls to the default 'production'.

require __DIR__ . '/../../lib/harness.php';
require HUB_TEST_ROOT . '/src/bootstrap.php';

use Hub\Config;
use Hub\Fincon\FinconFactory;
use Hub\Fincon\FinconResult;
use Hub\Fincon\MockFinconStockReader;

/** Runs $fn with APP_ENV set to $value, then restores the previous state. */
function cfg_with_env(string $value, callable $fn): mixed
{
    $old = getenv('APP_ENV');
    putenv('APP_ENV=' . $value);
    try {
        return $fn();
    } finally {
        putenv($old === false ? 'APP_ENV' : 'APP_ENV=' . $old);
    }
}

/** Values that must NOT count as production (the complete allow-list, with capitalisation and padding variants). */
function cfg_non_production_values(): array
{
    $out = [];
    foreach (['local', 'dev', 'development', 'test', 'testing'] as $name) {
        foreach ([$name, strtoupper($name), ucfirst($name), ' ' . $name . ' ', "\t" . strtoupper($name) . "\n"] as $variant) {
            $out[] = $variant;
        }
    }
    return $out;
}

/** Values that must count as production: the real name in any shape, plus every unknown or empty value. */
function cfg_production_values(): array
{
    return [
        'production', 'Production', 'PRODUCTION', ' production ', "\tPRODUCTION\n", 'pRoDuCtIoN',
        'Prod', 'PROD', 'prod', 'prd', 'PRD',
        'live', 'staging', 'stage', 'uat', 'preprod', 'qa',
        'developement', 'devel', 'tests', 'test1', 'local-prod', 'production2', 'prod ',
        'deve lopment', 'null', 'false', '0', '1', 'off',
        '', ' ', "\n",
    ];
}

test('C5-01: isProduction() is true for production in any shape and for every unknown or empty value', function (): void {
    $wrong = [];
    foreach (cfg_production_values() as $value) {
        if (!cfg_with_env($value, static fn (): bool => Config::isProduction())) {
            $wrong[] = json_encode($value);
        }
    }
    assert_same([], $wrong, 'APP_ENV values that should count as production (fail closed) but do not');
});

test('C5-02: isProduction() is false ONLY for local, dev, development, test, testing (any capitalisation)', function (): void {
    $wrong = [];
    foreach (cfg_non_production_values() as $value) {
        if (cfg_with_env($value, static fn (): bool => Config::isProduction())) {
            $wrong[] = json_encode($value);
        }
    }
    assert_same([], $wrong, 'explicit non-production names that wrongly count as production');
});

test('C5-03: with APP_ENV unset (and no config.local.php) the default is production', function (): void {
    if (is_file(HUB_ROOT . '/config.local.php')) {
        return; // a local override file makes "unset" environment-specific; skipped on such a machine
    }
    $old = getenv('APP_ENV');
    putenv('APP_ENV');
    try {
        assert_same(false, getenv('APP_ENV'), 'APP_ENV is unset for this check');
        assert_true(Config::isProduction(), 'unset APP_ENV is production');
    } finally {
        putenv($old === false ? 'APP_ENV' : 'APP_ENV=' . $old);
    }
});

test('C5-04: FinCon mock adapter: refused for production and every non-explicit value; allowed only for the non-production names', function (): void {
    $oldAdapter = getenv('FINCON_ADAPTER');
    $oldLog = getenv('FINCON_MOCK_LOG');
    putenv('FINCON_ADAPTER=mock');
    putenv('FINCON_MOCK_LOG');
    try {
        $mockedWrongly = [];
        foreach (['Prod', 'PROD', 'prd', 'staging', 'Production', ' production ', 'developement', '', ' '] as $value) {
            $reader = cfg_with_env($value, static fn () => FinconFactory::create());
            $result = $reader->readStock(['F-SMP001']);
            if ($reader instanceof MockFinconStockReader || $result->status !== FinconResult::ERROR) {
                $mockedWrongly[] = json_encode($value);
            }
        }
        assert_same([], $mockedWrongly, 'APP_ENV values for which the FinCon MOCK adapter was served (must be refused)');

        $refusedWrongly = [];
        foreach (['local', 'Dev', 'DEVELOPMENT', 'test', ' Testing '] as $value) {
            $reader = cfg_with_env($value, static fn () => FinconFactory::create());
            if (!$reader instanceof MockFinconStockReader) {
                $refusedWrongly[] = json_encode($value);
            }
        }
        assert_same([], $refusedWrongly, 'explicit non-production names for which the mock adapter was wrongly refused');
    } finally {
        putenv($oldAdapter === false ? 'FINCON_ADAPTER' : 'FINCON_ADAPTER=' . $oldAdapter);
        putenv($oldLog === false ? 'FINCON_MOCK_LOG' : 'FINCON_MOCK_LOG=' . $oldLog);
    }
});

test('C5-05: bin/seed.php --fixtures is refused (exit 1, no users) for Prod, PROD, prd, staging and production; allowed for Testing', function (): void {
    $accepted = [];
    foreach (['Prod', 'PROD', 'prd', 'staging', ' Production '] as $value) {
        $env = ['APP_ENV' => $value, 'DB_DRIVER' => 'sqlite', 'DB_PATH' => hub_test_dir('seed') . '/seed.sqlite'];
        [$code, $out] = hub_php(['bin/migrate.php'], $env);
        assert_same(0, $code, 'migrate for APP_ENV=' . json_encode($value) . ': ' . hub_snippet($out));
        [$code, $out] = hub_php(['bin/seed.php', '--fixtures'], $env);
        $pdo = new PDO('sqlite:' . $env['DB_PATH']);
        $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $pdo = null;
        if ($code !== 1 || $users !== 0 || !str_contains($out, 'Refused')) {
            $accepted[] = json_encode($value) . ' (exit ' . $code . ', ' . $users . ' users created)';
        }
    }
    assert_same([], $accepted, 'APP_ENV values for which --fixtures was not refused');

    $env = ['APP_ENV' => 'Testing', 'DB_DRIVER' => 'sqlite', 'DB_PATH' => hub_test_dir('seed') . '/seed.sqlite'];
    [$code, $out] = hub_php(['bin/migrate.php'], $env);
    assert_same(0, $code, 'migrate: ' . hub_snippet($out));
    [$code, $out] = hub_php(['bin/seed.php', '--fixtures'], $env);
    assert_same(0, $code, '--fixtures is allowed for APP_ENV=Testing: ' . hub_snippet($out));
});

run_tests();
