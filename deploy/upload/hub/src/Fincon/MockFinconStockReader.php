<?php
declare(strict_types=1);

namespace Hub\Fincon;

use Hub\Clock;

/** Fixed quantities for tests and local dev (SPEC s10.3). Refused in production by FinconFactory. */
final class MockFinconStockReader implements FinconStockReader
{
    public const QUANTITIES = [
        'F-SMP001' => 250.0,
        'F-SMP002' => 8.0,
        'F-SMP003' => 0.0,
        'F-SMP004' => 40.0,
        'F-SMP005' => 12.0,
    ];

    public function __construct(private readonly ?string $logPath = null)
    {
    }

    public function readStock(array $itemNos): FinconResult
    {
        $quantities = [];
        foreach ($itemNos as $itemNo) {
            $itemNo = (string) $itemNo;
            if (array_key_exists($itemNo, self::QUANTITIES)) {
                $quantities[$itemNo] = self::QUANTITIES[$itemNo];
            }
        }
        if ($this->logPath !== null && $this->logPath !== '') {
            $line = json_encode([
                'op' => 'readStock',
                'kind' => 'read',
                'items' => count($itemNos),
                'at' => Clock::now()->format(DATE_ATOM),
            ]);
            file_put_contents($this->logPath, $line . "\n", FILE_APPEND | LOCK_EX);
        }
        return FinconResult::ok($quantities);
    }
}
