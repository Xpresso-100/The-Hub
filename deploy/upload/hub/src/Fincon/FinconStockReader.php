<?php
declare(strict_types=1);

namespace Hub\Fincon;

/**
 * Read-only FinCon access (SPEC s10.1). There is no write method, and none may be added in Phase 1.
 * Get an instance from FinconFactory::create().
 */
interface FinconStockReader
{
    /**
     * Current stock quantities for the given FinCon item numbers. Items FinCon does not return are
     * simply absent from FinconResult::$quantities.
     *
     * @param list<string> $itemNos
     */
    public function readStock(array $itemNos): FinconResult;
}
