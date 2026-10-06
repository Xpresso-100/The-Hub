<?php
declare(strict_types=1);

namespace Hub\Fincon;

use Hub\Config;

/** Picks the FinCon adapter from FINCON_ADAPTER (SPEC s10.1). */
final class FinconFactory
{
    public static function create(): FinconStockReader
    {
        $adapter = strtolower(trim(Config::get('FINCON_ADAPTER')));
        if ($adapter === 'mock') {
            if (Config::isProduction()) {
                return self::refused('FINCON_ADAPTER=mock is refused when APP_ENV=production');
            }
            $log = Config::get('FINCON_MOCK_LOG');
            return new MockFinconStockReader($log !== '' ? Config::resolvePath($log) : null);
        }
        if ($adapter === 'http') {
            return HttpFinconStockReader::fromConfig();
        }
        return self::refused('Unknown FINCON_ADAPTER');
    }

    /** An adapter that answers every read with FINCON_ERROR. */
    private static function refused(string $reason): FinconStockReader
    {
        return new class ($reason) implements FinconStockReader {
            public function __construct(private readonly string $reason)
            {
            }

            public function readStock(array $itemNos): FinconResult
            {
                return FinconResult::failure(FinconResult::ERROR, $this->reason);
            }
        };
    }
}
