<?php
declare(strict_types=1);

namespace Hub\Http;

/** The incoming request. The JSON body is parsed lazily so 401/403 are decided before 400 (SPEC s3). */
final class Request
{
    /** Set by Auth::user() once the token is resolved. */
    public ?array $user = null;

    private ?array $json = null;

    /**
     * @param array<string,mixed>  $query   decoded query string
     * @param array<string,string> $headers lower-case header name => value
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        private readonly array $headers = [],
        public readonly string $rawBody = ''
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        if (PHP_SAPI !== 'cli-server') {
            // Deployed in a sub-folder (e.g. /hub/index.php): strip the folder from the route path.
            $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            if ($base !== '' && str_starts_with($path, $base . '/')) {
                $path = substr($path, strlen($base));
            }
        }
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $raw = file_get_contents('php://input');
        return new self($method, $path, $_GET, $headers, $raw === false ? '' : $raw);
    }

    /** Header value (case-insensitive name) or null. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Query-string value; $default when absent; '' when sent as an array (so validation rejects it). */
    public function query(string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $this->query)) {
            return $default;
        }
        $value = $this->query[$key];
        return is_string($value) ? $value : '';
    }

    /**
     * The JSON body as an associative array. Empty body -> []. Malformed JSON or a non-object
     * body throws HttpError 400 BAD_JSON. Call it AFTER the auth/role checks.
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $raw = trim($this->rawBody);
        if ($raw === '') {
            return $this->json = [];
        }
        $data = json_decode($raw, true, 64);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpError::badJson();
        }
        return $this->json = $data;
    }
}
