<?php
declare(strict_types=1);

namespace Hub\Http;

/** An HTTP response. Every response carries Cache-Control: no-store and X-Content-Type-Options: nosniff. */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = []
    ) {
    }

    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        return new self($status, $body, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /** {"success": true} + $data. */
    public static function ok(array $data = [], int $status = 200): self
    {
        return self::json(['success' => true] + $data, $status);
    }

    /** 201 {"success": true} + $data. */
    public static function created(array $data = []): self
    {
        return self::ok($data, 201);
    }

    /** @param array<string,string>|null $errors */
    public static function error(int $status, string $code, string $message, ?array $errors = null): self
    {
        $data = ['success' => false, 'code' => $code, 'message' => $message];
        if ($errors !== null) {
            $data['errors'] = $errors;
        }
        return self::json($data, $status);
    }

    public static function csv(string $csv, string $filename): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'export.csv';
        return new self(200, $csv, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
        ]);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $headers = $this->headers + [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
