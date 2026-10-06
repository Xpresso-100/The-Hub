<?php
declare(strict_types=1);

namespace Hub\Http;

/**
 * Throw from any handler to answer with the SPEC s3 error shape:
 * {"success": false, "code": "<CODE>", "message": "...", "errors": {...}?}
 */
final class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $errors = null
    ) {
        parent::__construct($message, $status);
    }

    public static function unauthenticated(string $message = 'Please log in.'): self
    {
        return new self(401, 'UNAUTHENTICATED', $message);
    }

    public static function forbidden(string $message = 'You do not have access to this.'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    public static function badJson(string $message = 'The request body must be a JSON object.'): self
    {
        return new self(400, 'BAD_JSON', $message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    /** @param array<string,string> $errors field => reason */
    public static function validation(array $errors, string $message = 'Some fields are not valid.'): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, $errors);
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'CONFLICT', $message);
    }

    public function toResponse(): Response
    {
        return Response::error($this->status, $this->errorCode, $this->getMessage(), $this->errors);
    }
}
