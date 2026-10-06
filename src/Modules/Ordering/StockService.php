<?php
declare(strict_types=1);

namespace Hub\Modules\Ordering;

use Hub\Clock;
use Hub\Config;
use Hub\Db;
use Hub\Fincon\FinconFactory;
use Hub\Fincon\FinconResult;
use Hub\Fincon\FinconStockReader;

/**
 * Read-only FinCon stock view (SPEC s7.1). Same visibility as franchisees: a status per ingredient,
 * never a quantity or a price. FinCon is only ever READ through FinconStockReader; the quantities
 * read are cached in The Hub's own stock_cache table. Every uncertainty fails open to in_stock.
 */
final class StockService
{
    public const LOW_STOCK_MAX = 10;
    public const STALE_AFTER_SECONDS = 48 * 3600;

    public function __construct(private readonly ?FinconStockReader $reader = null)
    {
    }

    /** The SPEC s7.1 payload without "success". */
    public function view(): array
    {
        $ingredients = Db::all('SELECT sku, name, unit, fincon_item_no FROM ingredients WHERE active = 1 ORDER BY sku');
        $itemNos = [];
        foreach ($ingredients as $row) {
            if ($row['fincon_item_no'] !== null && $row['fincon_item_no'] !== '') {
                $itemNos[(string) $row['fincon_item_no']] = true;
            }
        }
        $itemNos = array_keys($itemNos);

        [$status, $quantities, $fetchedAt] = $this->quantities($itemNos);

        $stale = $status !== FinconResult::OK;
        if ($fetchedAt !== null) {
            $age = Clock::now()->getTimestamp() - self::timestamp($fetchedAt);
            if ($age > self::STALE_AFTER_SECONDS) {
                $stale = true;
            }
        }

        $items = [];
        foreach ($ingredients as $row) {
            $itemNo = $row['fincon_item_no'] === null ? '' : (string) $row['fincon_item_no'];
            $items[] = [
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'unit' => (string) $row['unit'],
                'stock_status' => self::status($itemNo !== '' && array_key_exists($itemNo, $quantities) ? $quantities[$itemNo] : null),
            ];
        }

        return [
            'source' => 'fincon',
            'fincon_status' => $status,
            'stale' => $stale,
            'fetched_at' => Clock::iso($fetchedAt),
            'items' => $items,
        ];
    }

    /** in_stock | low_stock | out_of_stock from a quantity in milli; null (unknown) fails open. */
    public static function status(?int $milli): string
    {
        if ($milli === null) {
            return 'in_stock';
        }
        if ($milli <= 0) {
            return 'out_of_stock';
        }
        return $milli <= self::LOW_STOCK_MAX * 1000 ? 'low_stock' : 'in_stock';
    }

    /**
     * [fincon_status, [itemNo => milli], ?fetched_at DATETIME]. Fresh cache -> no FinCon call.
     * FinCon OK -> cache refreshed. FinCon failure -> the existing cache, with the failure code.
     *
     * @param list<string> $itemNos
     */
    private function quantities(array $itemNos): array
    {
        if ($itemNos === []) {
            return [FinconResult::OK, [], null];
        }

        $ttl = max(0, Config::int('STOCK_CACHE_TTL_SECONDS'));
        if ($ttl > 0) {
            [$cached, $fetchedAt] = self::cache($itemNos);
            if ($fetchedAt !== null && Clock::now()->getTimestamp() - self::timestamp($fetchedAt) < $ttl) {
                return [FinconResult::OK, $cached, $fetchedAt];
            }
        }

        try {
            $result = ($this->reader ?? FinconFactory::create())->readStock($itemNos);
        } catch (\Throwable $e) {
            error_log('HUB-ERROR stock read failed: ' . get_class($e));
            $result = FinconResult::failure(FinconResult::ERROR, 'stock read failed');
        }

        if ($result->isOk()) {
            $now = Clock::nowDb();
            $milli = [];
            foreach ($result->quantities as $itemNo => $qty) {
                if (in_array((string) $itemNo, $itemNos, true) && is_numeric($qty)) {
                    $milli[(string) $itemNo] = (int) round((float) $qty * 1000);
                }
            }
            self::store($milli, $now);
            return [FinconResult::OK, $milli, $now];
        }

        [$cached, $fetchedAt] = self::cache($itemNos);
        return [$result->status, $cached, $fetchedAt];
    }

    /** Cached [itemNo => milli] for $itemNos and the latest fetched_at overall (null = never fetched). */
    private static function cache(array $itemNos): array
    {
        $placeholders = implode(', ', array_fill(0, count($itemNos), '?'));
        $rows = Db::all('SELECT fincon_item_no, in_stock_milli FROM stock_cache WHERE fincon_item_no IN (' . $placeholders . ')', $itemNos);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['fincon_item_no']] = (int) $row['in_stock_milli'];
        }
        $fetchedAt = Db::value('SELECT MAX(fetched_at) FROM stock_cache');
        return [$out, $fetchedAt === null ? null : (string) $fetchedAt];
    }

    /** Upserts the quantities just read (SELECT then UPDATE/INSERT; runs on MySQL and SQLite). */
    private static function store(array $milli, string $now): void
    {
        if ($milli === []) {
            return;
        }
        Db::transaction(static function () use ($milli, $now): void {
            foreach ($milli as $itemNo => $qty) {
                $exists = (int) Db::value('SELECT COUNT(*) FROM stock_cache WHERE fincon_item_no = ?', [$itemNo]);
                if ($exists > 0) {
                    Db::exec('UPDATE stock_cache SET in_stock_milli = ?, fetched_at = ? WHERE fincon_item_no = ?', [$qty, $now, $itemNo]);
                } else {
                    Db::exec('INSERT INTO stock_cache (fincon_item_no, in_stock_milli, fetched_at) VALUES (?, ?, ?)', [$itemNo, $qty, $now]);
                }
            }
        });
    }

    private static function timestamp(string $dbDateTime): int
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($dbDateTime, 0, 19), Clock::tz());
        return $dt === false ? 0 : $dt->getTimestamp();
    }
}
