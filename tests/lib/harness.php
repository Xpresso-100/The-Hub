<?php
declare(strict_types=1);

/*
 * Test harness (SPEC s2.6). Plain PHP, no dependencies.
 *
 *   require __DIR__ . '/../lib/harness.php';
 *   test('name', function (): void {
 *       $app = TestApp::start();                 // fresh SQLite app on a free port, migrate + seed --fixtures
 *       $hub = $app->client('hub.user');         // logged-in API client (fixture credentials, SPEC s5.1)
 *       $r = $hub->get('/api/stock');            // HttpResult: status, json, headers, body
 *       assert_success($r, 200, 'stock');
 *   });
 *   run_tests();                                 // prints PASS/FAIL lines, stops every server, exits 0/1
 *
 * Servers started inside a test are stopped when that test ends. White-box (unit) tests may also
 * require src/bootstrap.php; black-box tests must not.
 */

if (!defined('HUB_TEST_ROOT')) {
    define('HUB_TEST_ROOT', dirname(__DIR__, 2));
}

date_default_timezone_set('Africa/Johannesburg');
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/** Fixture logins (SPEC s5.1). Test-only; not real credentials. */
const HUB_FIXTURE_USERS = [
    'hq.admin' => ['email' => 'hq.admin@example.test', 'password' => 'TestPass!Hq1', 'role' => 'hq'],
    'hub.user' => ['email' => 'hub.user@example.test', 'password' => 'TestPass!Hub1', 'role' => 'hub'],
    'hub2.user' => ['email' => 'hub2.user@example.test', 'password' => 'TestPass!Hub2', 'role' => 'hub'],
    'franchise.user' => ['email' => 'franchise.user@example.test', 'password' => 'TestPass!Fr1', 'role' => 'franchise'],
];

final class TestFailure extends RuntimeException
{
}

final class HttpResult
{
    /** @param array<string,string> $headers lower-case name => value */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?array $json
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function describe(): string
    {
        return $this->method . ' ' . $this->path . ' -> HTTP ' . $this->status . ' ' . hub_snippet($this->body);
    }
}

/** A php -S process on a free port. */
final class ServerProcess
{
    /** @var list<ServerProcess> */
    public static array $running = [];
    private static bool $shutdownRegistered = false;

    /** @param resource $proc */
    private function __construct(
        public readonly string $name,
        public readonly int $port,
        public readonly string $url,
        public readonly string $logFile,
        private mixed $proc
    ) {
    }

    /** @param array<string,string> $env overrides on top of the current environment */
    public static function start(string $name, string $docroot, string $router, array $env, string $logFile): self
    {
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'stopAll']);
            self::$shutdownRegistered = true;
        }
        $problem = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $port = hub_free_port();
            $proc = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $router],
                [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['redirect', 1]],
                $pipes,
                HUB_TEST_ROOT,
                hub_env($env)
            );
            if (!is_resource($proc)) {
                $problem = 'proc_open failed';
                continue;
            }
            fclose($pipes[0]);
            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline) {
                if (!proc_get_status($proc)['running']) {
                    break;
                }
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
                if (is_resource($socket)) {
                    fclose($socket);
                    $server = new self($name, $port, 'http://127.0.0.1:' . $port, $logFile, $proc);
                    self::$running[] = $server;
                    return $server;
                }
                usleep(100000);
            }
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc);
            }
            proc_close($proc);
            $problem = 'no listener on port ' . $port . '; log: ' . hub_snippet((string) @file_get_contents($logFile));
        }
        throw new TestFailure($name . ' server did not start (' . $problem . ')');
    }

    public function stop(): void
    {
        if (!is_resource($this->proc)) {
            return;
        }
        if (proc_get_status($this->proc)['running']) {
            proc_terminate($this->proc);
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && proc_get_status($this->proc)['running']) {
                usleep(50000);
            }
        }
        proc_close($this->proc);
        $this->proc = null;
    }

    public static function stopAll(): void
    {
        foreach (self::$running as $server) {
            $server->stop();
        }
        self::$running = [];
    }

    /** PHP errors and HUB-ERROR lines from the server log (diagnostics for a failing test). */
    public function errorLines(int $max = 15): array
    {
        $lines = @file($this->logFile, FILE_IGNORE_NEW_LINES) ?: [];
        $hits = array_values(array_filter($lines, static fn (string $l): bool =>
            preg_match('/HUB-ERROR|PHP (Fatal|Parse|Warning|Notice)|Fatal error|Parse error|Uncaught/', $l) === 1));
        return array_slice($hits, -$max);
    }
}

/** A fresh instance of the real app: own SQLite file, migrate + seed --fixtures, php -S on a free port. */
final class TestApp
{
    /** @var array<string,ApiClient> */
    private array $clients = [];

    /** @param array<string,string> $env */
    private function __construct(
        public readonly string $dir,
        public readonly array $env,
        public readonly ServerProcess $server
    ) {
    }

    /**
     * Test env (SPEC s2.6) + $envOverrides. Paths of interest: dbPath(), mockLog(), mailLog().
     * @param array<string,string|int> $envOverrides
     */
    public static function start(array $envOverrides = []): self
    {
        $dir = hub_test_dir('app');
        $env = array_merge([
            'APP_ENV' => 'test',
            'DB_DRIVER' => 'sqlite',
            'DB_PATH' => $dir . '/hub.sqlite',
            'FINCON_ADAPTER' => 'mock',
            'FINCON_MOCK_LOG' => $dir . '/fincon-mock.log',
            'MAIL_TRANSPORT' => 'log',
            'MAIL_LOG_PATH' => $dir . '/mail.log',
            'STOCK_CACHE_TTL_SECONDS' => '0',
            'HQ_ORDERS_EMAIL' => 'hq-orders@example.test',
        ], array_map('strval', $envOverrides));
        foreach ([['bin/migrate.php'], ['bin/seed.php', '--fixtures']] as $args) {
            [$code, $output] = hub_php($args, $env);
            if ($code !== 0) {
                throw new TestFailure('app setup `php ' . implode(' ', $args) . '` failed (exit ' . $code . '): ' . hub_snippet($output));
            }
        }
        $server = ServerProcess::start('app', HUB_TEST_ROOT . '/public', HUB_TEST_ROOT . '/public/index.php', $env, $dir . '/server.log');
        return new self($dir, $env, $server);
    }

    public function baseUrl(): string
    {
        return $this->server->url;
    }

    public function dbPath(): string
    {
        return $this->env['DB_PATH'];
    }

    public function mockLog(): string
    {
        return $this->env['FINCON_MOCK_LOG'];
    }

    public function mailLog(): string
    {
        return $this->env['MAIL_LOG_PATH'];
    }

    /** A client logged in as a fixture user (cached per app). */
    public function client(string $username): ApiClient
    {
        return $this->clients[$username] ??= new ApiClient($this, $this->login($username), $username);
    }

    /** A client without a token. */
    public function anon(): ApiClient
    {
        return new ApiClient($this, null, null);
    }

    /** Logs a fixture user in and returns a new token. */
    public function login(string $username): string
    {
        $creds = HUB_FIXTURE_USERS[$username] ?? null;
        if ($creds === null) {
            throw new TestFailure('unknown fixture user ' . $username);
        }
        $r = $this->request('POST', '/api/login', ['username' => $username, 'email' => $creds['email'], 'password' => $creds['password']]);
        $token = $r->json['token'] ?? null;
        if ($r->status !== 200 || !is_string($token) || $token === '') {
            throw new TestFailure('login as ' . $username . ' failed: ' . $r->describe());
        }
        return $token;
    }

    /**
     * @param mixed $body array/object -> JSON-encoded; string -> sent raw; null -> no body
     * @param array<string,string> $headers
     */
    public function request(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = []): HttpResult
    {
        $lines = ['Accept: application/json', 'Connection: close'];
        if ($token !== null) {
            $lines[] = 'X-Auth-Token: ' . $token;
        }
        $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 90, 'follow_location' => 0, 'protocol_version' => 1.0];
        if ($body !== null) {
            $options['content'] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $lines[] = 'Content-Type: application/json';
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $options['header'] = implode("\r\n", $lines);
        $http_response_header = [];
        $raw = @file_get_contents($this->server->url . $path, false, stream_context_create(['http' => $options]));
        $headerLines = $http_response_header;
        if ($raw === false && $headerLines === []) {
            throw new TestFailure($method . ' ' . $path . ': no HTTP response from the app (' . (error_get_last()['message'] ?? 'unknown error') . ')');
        }
        $status = 0;
        $parsed = [];
        foreach ($headerLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $parsed = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower(trim($name))] = trim($value);
            }
        }
        $raw = $raw === false ? '' : $raw;
        $json = json_decode($raw, true);
        return new HttpResult($method, $path, $status, $parsed, $raw, is_array($json) ? $json : null);
    }
}

final class ApiClient
{
    public function __construct(
        private readonly TestApp $app,
        public readonly ?string $token,
        public readonly ?string $username
    ) {
    }

    public function get(string $path): HttpResult
    {
        return $this->app->request('GET', $path, null, $this->token);
    }

    public function post(string $path, mixed $body = null): HttpResult
    {
        return $this->app->request('POST', $path, $body ?? [], $this->token);
    }

    public function put(string $path, mixed $body = null): HttpResult
    {
        return $this->app->request('PUT', $path, $body ?? [], $this->token);
    }

    public function patch(string $path, mixed $body = null): HttpResult
    {
        return $this->app->request('PATCH', $path, $body ?? [], $this->token);
    }

    public function delete(string $path): HttpResult
    {
        return $this->app->request('DELETE', $path, null, $this->token);
    }

    /** USER from GET /api/me (must be 200). */
    public function me(): array
    {
        $r = $this->get('/api/me');
        assert_success($r, 200, 'GET /api/me as ' . $this->username);
        return $r->json['user'];
    }

    public function locationId(): int
    {
        return (int) $this->me()['location']['id'];
    }
}

/** The fake FinCon tunnel (tests/support/fake_fincon.php, SPEC s10.4). */
final class FakeFincon
{
    private function __construct(public readonly ServerProcess $server, public readonly string $log)
    {
    }

    /** @param array<string,string> $env e.g. ['FAKE_FINCON_FORCE_STATUS' => '403'] */
    public static function start(array $env = []): self
    {
        $dir = hub_test_dir('fincon');
        $log = $dir . '/fake-fincon.log';
        touch($log);
        $server = ServerProcess::start(
            'fake-fincon',
            HUB_TEST_ROOT . '/tests/support',
            HUB_TEST_ROOT . '/tests/support/fake_fincon.php',
            ['FAKE_FINCON_LOG' => $log] + $env,
            $dir . '/server.log'
        );
        return new self($server, $log);
    }

    /** App env that points the real HTTP adapter at this fake (placeholder credentials). */
    public function appEnv(): array
    {
        return [
            'FINCON_ADAPTER' => 'http',
            'FINCON_BASE_URL' => $this->server->url,
            'FINCON_CF_CLIENT_ID' => 'test-cf-client-id',
            'FINCON_CF_CLIENT_SECRET' => 'test-cf-client-secret',
            'FINCON_SRV_USER' => 'test-srv-user',
            'FINCON_SRV_PASS' => 'test-srv-pass',
            'FINCON_DATA_ID' => 'TESTDATA',
            'FINCON_USER' => 'test-user',
            'FINCON_PASS' => 'test-pass',
            'FINCON_LOC_NO' => '0',
            'FINCON_TIMEOUT_SECONDS' => '10',
        ];
    }

    /** Logged requests: list of {method, function, has_cf_id, has_cf_secret, has_basic, path}. */
    public function requests(): array
    {
        return hub_json_lines($this->log);
    }
}

// ---------------------------------------------------------------- test registration

function test(string $name, callable $fn): void
{
    $GLOBALS['hub_tests'][] = [$name, $fn];
}

function run_tests(): never
{
    $file = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? 'test.php'));
    $failed = 0;
    foreach ($GLOBALS['hub_tests'] ?? [] as [$name, $fn]) {
        $label = $file . ' :: ' . $name;
        try {
            $fn();
            echo 'PASS ' . $label . "\n";
        } catch (Throwable $e) {
            $failed++;
            $where = $e instanceof TestFailure ? '' : ' [' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            echo 'FAIL ' . $label . ': ' . hub_one_line($e->getMessage()) . $where . "\n";
            foreach (ServerProcess::$running as $server) {
                foreach ($server->errorLines() as $line) {
                    echo '    ' . $server->name . ' log: ' . hub_one_line($line) . "\n";
                }
            }
        } finally {
            ServerProcess::stopAll();
        }
    }
    if ($failed === 0 && getenv('HUB_TEST_KEEP') !== '1') {
        foreach ($GLOBALS['hub_test_dirs'] ?? [] as $dir) {
            hub_rmdir($dir);
        }
    } elseif ($failed > 0) {
        echo "    test folders kept under var/test/ for inspection\n";
    }
    exit($failed > 0 ? 1 : 0);
}

// ---------------------------------------------------------------- assertions

function hub_fail(string $message): never
{
    throw new TestFailure($message);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        hub_fail($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        hub_fail($message . ': expected ' . hub_export($expected) . ', got ' . hub_export($actual));
    }
}

function assert_status(int $expected, HttpResult $r, string $message): void
{
    if ($r->status !== $expected) {
        hub_fail($message . ': expected HTTP ' . $expected . '; ' . $r->describe());
    }
}

/** Status + JSON body with "success": true. */
function assert_success(HttpResult $r, int $status, string $message): void
{
    assert_status($status, $r, $message);
    if (!is_array($r->json) || ($r->json['success'] ?? null) !== true) {
        hub_fail($message . ': expected a JSON body with "success": true; ' . $r->describe());
    }
}

/** Status + SPEC s3 error body {"success": false, "code": $code, "message": ...}. */
function assert_error(HttpResult $r, int $status, string $code, string $message): void
{
    assert_status($status, $r, $message);
    if (!is_array($r->json) || ($r->json['success'] ?? null) !== false || ($r->json['code'] ?? null) !== $code) {
        hub_fail($message . ': expected {"success": false, "code": "' . $code . '"}; ' . $r->describe());
    }
}

/** Money: a JSON number equal to $expected at 2 dp. */
function assert_money(float|int $expected, mixed $actual, string $message): void
{
    if (!is_int($actual) && !is_float($actual)) {
        hub_fail($message . ': expected the JSON number ' . $expected . ', got ' . hub_export($actual));
    }
    if ((int) round($actual * 100) !== (int) round($expected * 100)) {
        hub_fail($message . ': expected ' . number_format($expected, 2, '.', '') . ', got ' . hub_export($actual));
    }
}

/** Quantity: a JSON number equal to $expected at 3 dp. */
function assert_qty(float|int $expected, mixed $actual, string $message): void
{
    if (!is_int($actual) && !is_float($actual)) {
        hub_fail($message . ': expected the JSON number ' . $expected . ', got ' . hub_export($actual));
    }
    if ((int) round($actual * 1000) !== (int) round($expected * 1000)) {
        hub_fail($message . ': expected ' . number_format($expected, 3, '.', '') . ', got ' . hub_export($actual));
    }
}

/** $r->json[$key] as a list (asserted). */
function hub_list(HttpResult $r, string $key, string $message): array
{
    $value = is_array($r->json) ? ($r->json[$key] ?? null) : null;
    if (!is_array($value) || !array_is_list($value)) {
        hub_fail($message . ': expected "' . $key . '" to be a JSON array; ' . $r->describe());
    }
    return $value;
}

/** $r->json[$key] as an object (asserted). */
function hub_object(HttpResult $r, string $key, string $message): array
{
    $value = is_array($r->json) ? ($r->json[$key] ?? null) : null;
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
        hub_fail($message . ': expected "' . $key . '" to be a JSON object; ' . $r->describe());
    }
    return $value;
}

/** First element of $list whose [$key] === $value, else null. */
function hub_find(array $list, string $key, mixed $value): ?array
{
    foreach ($list as $item) {
        if (is_array($item) && array_key_exists($key, $item) && $item[$key] === $value) {
            return $item;
        }
    }
    return null;
}

/** The [$key] values of a list, ids as ints. */
function hub_column(array $list, string $key): array
{
    return array_map(static fn ($item) => is_array($item) ? ($item[$key] ?? null) : null, $list);
}

/** Every key path in $data (any depth) whose key matches $regex. */
function hub_keys_matching(mixed $data, string $regex, string $path = '$'): array
{
    $hits = [];
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $here = $path . '.' . $key;
            if (is_string($key) && preg_match($regex, $key) === 1) {
                $hits[] = $here;
            }
            array_push($hits, ...hub_keys_matching($value, $regex, $here));
        }
    }
    return $hits;
}

// ---------------------------------------------------------------- utilities

function hub_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
}

function hub_date_plus(int $days): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/** Decoded JSON lines of a log file (missing file = []). */
function hub_json_lines(string $file): array
{
    $out = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        $out[] = is_array($decoded) ? $decoded : ['_unparseable' => $line];
    }
    return $out;
}

/** Runs `php <args>` from the repo root with env overrides. @return array{0:int,1:string} */
function hub_php(array $args, array $env = [], int $timeout = 120): array
{
    $out = tempnam(sys_get_temp_dir(), 'hubphp');
    $proc = proc_open(
        array_merge([PHP_BINARY], $args),
        [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['redirect', 1]],
        $pipes,
        HUB_TEST_ROOT,
        hub_env($env)
    );
    if (!is_resource($proc)) {
        return [1, 'proc_open failed'];
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + $timeout;
    $code = 1;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $code = (int) $status['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc);
            break;
        }
        usleep(20000);
    }
    proc_close($proc);
    $output = (string) @file_get_contents($out);
    @unlink($out);
    return [$code, $output];
}

/** Current environment + overrides (proc_open needs the full block, e.g. SystemRoot on Windows). */
function hub_env(array $overrides): array
{
    $env = getenv();
    foreach ($overrides as $key => $value) {
        $env[$key] = (string) $value;
    }
    return $env;
}

function hub_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new TestFailure('no free TCP port: ' . $errstr);
    }
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/** A new scratch folder under var/test/, removed after a fully passing file. */
function hub_test_dir(string $kind): string
{
    $dir = HUB_TEST_ROOT . '/var/test/' . date('His') . '-' . $kind . '-' . bin2hex(random_bytes(4));
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $GLOBALS['hub_test_dirs'][] = $dir;
    return $dir;
}

function hub_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function hub_snippet(string $text, int $max = 400): string
{
    $text = hub_one_line($text);
    return strlen($text) > $max ? substr($text, 0, $max) . '...' : $text;
}

function hub_one_line(string $text): string
{
    return trim((string) preg_replace('/\s*\R\s*/', ' | ', $text));
}

function hub_export(mixed $value): string
{
    return hub_snippet((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR), 300);
}
