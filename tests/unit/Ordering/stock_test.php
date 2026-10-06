<?php
declare(strict_types=1);

// M1-04 (SPEC s7.1, A7): read-only FinCon stock view - statuses, cache, stale, fail-open; no cost or price keys.

require __DIR__ . '/../../lib/harness.php';
require HUB_TEST_ROOT . '/src/bootstrap.php';

use Hub\Clock;
use Hub\Db;
use Hub\Fincon\FinconResult;
use Hub\Fincon\FinconStockReader;
use Hub\Modules\Ordering\StockService;

/** A FinconStockReader that answers from a script and counts calls. */
final class ScriptedReader implements FinconStockReader
{
    public int $calls = 0;

    public function __construct(private FinconResult $result)
    {
    }

    public function readStock(array $itemNos): FinconResult
    {
        $this->calls++;
        return $this->result;
    }
}

/** Fresh SQLite DB with schema + SAMPLE ingredients, for white-box tests. */
function stock_db(array $env = []): void
{
    $dir = hub_test_dir('stock');
    foreach ($env + ['DB_DRIVER' => 'sqlite', 'DB_PATH' => $dir . '/x.sqlite', 'APP_ENV' => 'test', 'STOCK_CACHE_TTL_SECONDS' => '0'] as $k => $v) {
        putenv($k . '=' . $v);
    }
    Db::reset();
    Db::execScript((string) file_get_contents(HUB_TEST_ROOT . '/db/schema.sqlite.sql'));
    Db::execScript((string) file_get_contents(HUB_TEST_ROOT . '/db/seed_samples.sql'));
}

function stock_statuses(array $view): array
{
    $out = [];
    foreach ($view['items'] as $item) {
        $out[$item['sku']] = $item['stock_status'];
    }
    return $out;
}

const STOCK_EXPECTED = ['SMP001' => 'in_stock', 'SMP002' => 'low_stock', 'SMP003' => 'out_of_stock', 'SMP004' => 'in_stock', 'SMP005' => 'in_stock', 'SMP006' => 'in_stock'];
const STOCK_QTY = ['F-SMP001' => 250.0, 'F-SMP002' => 8.0, 'F-SMP003' => 0.0, 'F-SMP004' => 40.0, 'F-SMP005' => 12.0];

test('M1-04: status rule - <= 0 out, <= 10 low, else in; unknown fails open', function (): void {
    assert_same('out_of_stock', StockService::status(0), '0');
    assert_same('out_of_stock', StockService::status(-5000), 'negative');
    assert_same('low_stock', StockService::status(1), '0.001');
    assert_same('low_stock', StockService::status(10000), '10');
    assert_same('in_stock', StockService::status(10001), '10.001');
    assert_same('in_stock', StockService::status(null), 'unknown');
});

test('M1-04: FinCon OK -> statuses as s7.1 and the cache is refreshed', function (): void {
    stock_db();
    $view = (new StockService(new ScriptedReader(FinconResult::ok(STOCK_QTY))))->view();
    assert_same('OK', $view['fincon_status'], 'fincon_status');
    assert_same(false, $view['stale'], 'stale');
    assert_same(STOCK_EXPECTED, stock_statuses($view), 'statuses');
    assert_true(is_string($view['fetched_at']), 'fetched_at set');
    assert_same(5, (int) Db::value('SELECT COUNT(*) FROM stock_cache'), 'five mapped items cached');
    assert_same(8000, (int) Db::value("SELECT in_stock_milli FROM stock_cache WHERE fincon_item_no = 'F-SMP002'"), 'milli stored');
    assert_same([], hub_keys_matching($view, '/cost|price|qty|quantit/i'), 'no cost, price or quantity keys');
});

test('M1-04: FinCon failure serves the cache, stale=true, fincon_status = failure code; never fetched -> all in_stock', function (): void {
    stock_db();
    $down = new ScriptedReader(FinconResult::failure(FinconResult::UNREACHABLE, 'down'));
    $view = (new StockService($down))->view();
    assert_same('FINCON_UNREACHABLE', $view['fincon_status'], 'status with no cache');
    assert_same(true, $view['stale'], 'stale with no cache');
    assert_same(null, $view['fetched_at'], 'never fetched');
    assert_same(array_fill_keys(array_keys(STOCK_EXPECTED), 'in_stock'), stock_statuses($view), 'fail open');

    (new StockService(new ScriptedReader(FinconResult::ok(STOCK_QTY))))->view();
    $fetched = Db::value('SELECT MAX(fetched_at) FROM stock_cache');
    $view = (new StockService(new ScriptedReader(FinconResult::failure(FinconResult::AUTH_FAILED, 'auth'))))->view();
    assert_same('FINCON_AUTH_FAILED', $view['fincon_status'], 'failure code');
    assert_same(true, $view['stale'], 'stale');
    assert_same(Clock::iso((string) $fetched), $view['fetched_at'], 'fetched_at of the cache');
    assert_same(STOCK_EXPECTED, stock_statuses($view), 'cached statuses served');
});

test('M1-04: a reader that throws is FINCON_ERROR and fails open', function (): void {
    stock_db();
    $reader = new class implements FinconStockReader {
        public function readStock(array $itemNos): FinconResult
        {
            throw new RuntimeException('boom');
        }
    };
    $view = (new StockService($reader))->view();
    assert_same('FINCON_ERROR', $view['fincon_status'], 'status');
    assert_same(array_fill_keys(array_keys(STOCK_EXPECTED), 'in_stock'), stock_statuses($view), 'fail open');
});

test('M1-04: a fresh cache within STOCK_CACHE_TTL_SECONDS is served without calling FinCon; 48 h old cache is stale', function (): void {
    stock_db(['STOCK_CACHE_TTL_SECONDS' => '900']);
    $reader = new ScriptedReader(FinconResult::ok(STOCK_QTY));
    (new StockService($reader))->view();
    $view = (new StockService($reader))->view();
    assert_same(1, $reader->calls, 'second view served from cache');
    assert_same(STOCK_EXPECTED, stock_statuses($view), 'cached statuses');
    assert_same(false, $view['stale'], 'fresh cache not stale');

    $old = Clock::now()->modify('-49 hours')->format('Y-m-d H:i:s');
    Db::exec('UPDATE stock_cache SET fetched_at = ?', [$old]);
    $view = (new StockService(new ScriptedReader(FinconResult::failure(FinconResult::MAINTENANCE, 'm'))))->view();
    assert_same(true, $view['stale'], '49 h old cache is stale');
    assert_same('FINCON_MAINTENANCE', $view['fincon_status'], 'status');
});

test('M1-04: GET /api/stock is 200 for hub, hq and franchise with the same payload and no cost/price keys; reads are kind=read', function (): void {
    $app = TestApp::start();
    $bodies = [];
    foreach (['hub.user', 'hq.admin', 'franchise.user'] as $who) {
        $r = $app->client($who)->get('/api/stock');
        assert_success($r, 200, $who . ' GET /api/stock');
        assert_same([], hub_keys_matching($r->json, '/cost|price/i'), $who . ' no cost/price keys');
        assert_same(STOCK_EXPECTED, stock_statuses($r->json), $who . ' statuses');
        $bodies[] = $r->json['items'];
    }
    assert_same($bodies[0], $bodies[2], 'franchise sees the same items as the hub');
    foreach (hub_json_lines($app->mockLog()) as $line) {
        assert_same('read', $line['kind'] ?? null, 'mock log kind');
    }
    assert_error($app->anon()->get('/api/stock'), 401, 'UNAUTHENTICATED', 'anonymous');
});

run_tests();
