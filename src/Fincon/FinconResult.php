<?php
declare(strict_types=1);

namespace Hub\Fincon;

/** Outcome of a FinCon read, using the reference failure taxonomy (CODEBASE_MAP s4). */
final class FinconResult
{
    public const OK = 'OK';
    public const UNREACHABLE = 'FINCON_UNREACHABLE';
    public const AUTH_FAILED = 'FINCON_AUTH_FAILED';
    public const MAINTENANCE = 'FINCON_MAINTENANCE';
    public const ERROR = 'FINCON_ERROR';

    /** @param array<string,float> $quantities itemNo => quantity in stock */
    public function __construct(
        public readonly string $status,
        public readonly array $quantities = [],
        public readonly ?string $error = null
    ) {
    }

    /** @param array<string,float> $quantities */
    public static function ok(array $quantities): self
    {
        return new self(self::OK, $quantities, null);
    }

    public static function failure(string $status, string $error): self
    {
        return new self($status, [], $error);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
