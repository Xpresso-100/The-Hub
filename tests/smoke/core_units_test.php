<?php
declare(strict_types=1);

// Smoke (white-box, core only): Money, FinCon adapters, mailer, migrate/seed CLI (SPEC s2.3, s3.1, s10, s11).

require __DIR__ . '/../lib/harness.php';
require HUB_TEST_ROOT . '/src/bootstrap.php';

use Hub\Fincon\FinconFactory;
use Hub\Fincon\FinconResult;
use Hub\Fincon\FinconStockReader;
use Hub\Fincon\HttpFinconStockReader;
use Hub\Fincon\MockFinconStockReader;
use Hub\Mail\LogMailer;
use Hub\Mail\MailerFactory;
use Hub\Money;

function http_reader(string $baseUrl): HttpFinconStockReader
{
    return new HttpFinconStockReader([
        'base_url' => $baseUrl, 'cf_client_id' => 'test-cf-client-id', 'cf_client_secret' => 'test-cf-client-secret',
        'srv_user' => 'test-srv-user', 'srv_pass' => 'test-srv-pass', 'data_id' => 'TESTDATA', 'user' => 'test-user',
        'pass' => 'test-pass', 'loc_no' => '0', 'timeout' => 10,
    ]);
}

const SAMPLE_ITEMS = ['F-SMP001', 'F-SMP002', 'F-SMP003', 'F-SMP004', 'F-SMP005', 'F-UNKNOWN'];

test('Money: SPEC s3.1 rounding reproduces the s5.3 hand calculations', function (): void {
    assert_same(1800, Money::lineCents(1440, 1250), 'flour line 24 muffins');
    assert_same(1296, Money::lineCents(720, 1800), 'sugar line 24 muffins');
    assert_same(559, Money::roundHalfUp(13416, 24), 'muffin cost per item');
    assert_same(559, Money::roundHalfUp(20124, 36), 'report average');
    assert_same(3, Money::roundHalfUp(5, 2), 'half rounds up');
    assert_same(1250, Money::parseCents(12.5), 'parseCents 12.5');
    assert_same(1300, Money::parseCents(13), 'parseCents int');
    assert_same(null, Money::parseCents(1.005), 'parseCents rejects 3 dp');
    assert_same(null, Money::parseCents('12.50'), 'parseCents rejects strings');
    assert_same(null, Money::parseCents(-1), 'parseCents rejects negatives');
    assert_same(2500, Money::parseMilli(2.5), 'parseMilli 2.5');
    assert_same(-1440, Money::parseMilli(-1.44), 'parseMilli keeps sign');
    assert_same(null, Money::parseMilli(1.0005), 'parseMilli rejects 4 dp');
    assert_same(null, Money::parseMilli(true), 'parseMilli rejects bool');
    assert_same('245.04', Money::format(24504), 'format');
    assert_same('-1.440', Money::formatQty(-1440), 'formatQty');
    assert_same(134.16, Money::toRands(13416), 'toRands');
});

test('FinCon interface is read-only: exactly one method, readStock', function (): void {
    $methods = array_map(static fn ($m) => $m->getName(), (new ReflectionClass(FinconStockReader::class))->getMethods());
    assert_same(['readStock'], $methods, 'FinconStockReader methods');
    assert_same(['Login', 'GetStockQuantitiesForOpt2', 'GetStockQuantities', 'Logout'], HttpFinconStockReader::ALLOWED_FUNCTIONS, 'allowlist');
});

test('mock adapter: s10.3 quantities, unknown items absent, logs kind=read', function (): void {
    $log = hub_test_dir('mock') . '/mock.log';
    $result = (new MockFinconStockReader($log))->readStock(SAMPLE_ITEMS);
    assert_same(FinconResult::OK, $result->status, 'status');
    assert_same(['F-SMP001' => 250.0, 'F-SMP002' => 8.0, 'F-SMP003' => 0.0, 'F-SMP004' => 40.0, 'F-SMP005' => 12.0], $result->quantities, 'quantities');
    $lines = hub_json_lines($log);
    assert_same(1, count($lines), 'one log line');
    assert_same('read', $lines[0]['kind'] ?? null, 'kind');
    assert_same(6, $lines[0]['items'] ?? null, 'items');
});

test('factory: mock in test, mock refused in production, http otherwise', function (): void {
    putenv('APP_ENV=test');
    putenv('FINCON_ADAPTER=mock');
    putenv('FINCON_MOCK_LOG=');
    assert_true(FinconFactory::create() instanceof MockFinconStockReader, 'mock adapter in test');
    putenv('APP_ENV=production');
    $result = FinconFactory::create()->readStock(['F-SMP001']);
    assert_same(FinconResult::ERROR, $result->status, 'mock refused in production');
    putenv('FINCON_ADAPTER=http');
    assert_true(FinconFactory::create() instanceof HttpFinconStockReader, 'http adapter');
    putenv('FINCON_BASE_URL=');
    assert_same(FinconResult::ERROR, FinconFactory::create()->readStock(['F-SMP001'])->status, 'unconfigured http adapter');
    putenv('APP_ENV=test');
});

test('http adapter against the fake FinCon: quantities, read functions only, CF-Access + Basic on every call', function (): void {
    $fake = FakeFincon::start();
    $result = http_reader($fake->server->url)->readStock(SAMPLE_ITEMS);
    assert_same(FinconResult::OK, $result->status, 'status (' . $result->error . ')');
    assert_same(['F-SMP001' => 250.0, 'F-SMP002' => 8.0, 'F-SMP003' => 0.0, 'F-SMP004' => 40.0, 'F-SMP005' => 12.0], $result->quantities, 'quantities');
    $requests = $fake->requests();
    assert_same(['Login', 'GetStockQuantitiesForOpt2', 'Logout'], hub_column($requests, 'function'), 'call sequence');
    assert_same(['POST', 'POST', 'GET'], hub_column($requests, 'method'), 'methods');
    foreach ($requests as $req) {
        assert_true($req['has_cf_id'] && $req['has_cf_secret'] && $req['has_basic'], 'auth layers on ' . hub_export($req));
    }
});

test('http adapter maps 403/401 to AUTH_FAILED, 500 and no response to UNREACHABLE', function (): void {
    foreach (['403' => FinconResult::AUTH_FAILED, '401' => FinconResult::AUTH_FAILED, '500' => FinconResult::UNREACHABLE] as $code => $expected) {
        $fake = FakeFincon::start(['FAKE_FINCON_FORCE_STATUS' => (string) $code]);
        assert_same($expected, http_reader($fake->server->url)->readStock(['F-SMP001'])->status, 'HTTP ' . $code);
    }
    $closed = 'http://127.0.0.1:' . hub_free_port();
    $status = http_reader($closed)->readStock(['F-SMP001'])->status;
    assert_true(in_array($status, [FinconResult::UNREACHABLE, FinconResult::MAINTENANCE], true), 'closed port gives UNREACHABLE, got ' . $status);
});

test('http adapter refuses any function outside the read allowlist', function (): void {
    $call = new ReflectionMethod(HttpFinconStockReader::class, 'call');
    try {
        $call->invoke(http_reader('http://127.0.0.1:9'), 'POST', 'PostSalesOrder', [], []);
        hub_fail('a non-allowlisted function was called');
    } catch (LogicException $e) {
        assert_true(str_contains($e->getMessage(), 'allowlist'), 'allowlist message');
    }
});

test('mailer: log transport writes one JSON line; bad recipients are refused', function (): void {
    $log = hub_test_dir('mail') . '/mail.log';
    putenv('APP_ENV=test');
    putenv('MAIL_TRANSPORT=log');
    putenv('MAIL_LOG_PATH=' . $log);
    $mailer = MailerFactory::create();
    assert_true($mailer instanceof LogMailer, 'log transport selected');
    $mailer->send('hq-orders@example.test', 'Hub order #1 - The Hub', "line 1\nline 2");
    $lines = hub_json_lines($log);
    assert_same('hq-orders@example.test', $lines[0]['to'] ?? null, 'to');
    assert_same('Hub order #1 - The Hub', $lines[0]['subject'] ?? null, 'subject');
    foreach (['', 'not-an-email', "a@example.test\r\nBcc: x@example.test"] as $bad) {
        try {
            $mailer->send($bad, 'subject', 'body');
            hub_fail('recipient ' . json_encode($bad) . ' was accepted');
        } catch (RuntimeException $e) {
            assert_true(!$e instanceof TestFailure, 'refused ' . json_encode($bad));
        }
    }
});

test('CLI: migrate is idempotent, --samples skips when recipes exist, --fixtures refused in production', function (): void {
    $env = ['APP_ENV' => 'development', 'DB_DRIVER' => 'sqlite', 'DB_PATH' => hub_test_dir('cli') . '/cli.sqlite'];
    foreach ([['bin/migrate.php'], ['bin/migrate.php'], ['bin/seed.php', '--samples']] as $args) {
        [$code, $out] = hub_php($args, $env);
        assert_same(0, $code, implode(' ', $args) . ' exit code (' . hub_snippet($out) . ')');
    }
    [$code, $out] = hub_php(['bin/seed.php', '--samples'], $env);
    assert_true($code === 0 && str_contains($out, 'skipped'), 'second --samples is skipped: ' . hub_snippet($out));
    [$code] = hub_php(['bin/seed.php', '--fixtures'], ['APP_ENV' => 'production'] + $env);
    assert_same(1, $code, '--fixtures exit code in production');
    $pdo = new PDO('sqlite:' . $env['DB_PATH']);
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'no users created in production');
    assert_same(3, (int) $pdo->query('SELECT COUNT(*) FROM recipes WHERE is_sample = 1')->fetchColumn(), 'three SAMPLE recipes');
    $pdo = null;
});

run_tests();
