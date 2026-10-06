# CORE_API - the shared PHP API modules may call

Written by G1. **Frozen** after G1: a module that needs a change writes `LANE BREACH: <file> - <reason>` in
`progress.md` and stops that module. Everything below lives under `src/` (namespace `Hub\` -> `src/`,
autoloaded by `src/bootstrap.php`). PHP 8.1+ syntax, no Composer.

## Plugging a module in (SPEC s2.5)

`src/Modules/<Name>/routes.php` **returns** a list of `[METHOD, PATH_PATTERN, callable]`:

```php
<?php
declare(strict_types=1);

use Hub\Modules\Ordering\OrderController;

return [
    ['GET',  '/api/orders',      [OrderController::class, 'list']],    // static method
    ['GET',  '/api/orders/{id}', [OrderController::class, 'show']],
    ['PUT',  '/api/admin/ingredients/{sku}', fn ($req, $params) => IngredientController::put($req, $params)],
];
```

- Handler signature: `function (Hub\Http\Request $req, array $params): Hub\Http\Response`.
  `$params` holds the path params as **strings** (`$params['id']`, `$params['sku']`).
- `{id}` matches `[0-9]+`, `{sku}` matches `[A-Z0-9-]{2,20}`; other `{name}` match `[^/]+`.
- Unknown path -> 404 `NOT_FOUND`; known path + wrong method -> 405 `METHOD_NOT_ALLOWED` (core does this).
- `public/index.php` loads `src/Auth/routes.php`, then every `src/Modules/*/routes.php` in sorted order.
- Module classes use namespace `Hub\Modules\<Name>` (e.g. `Hub\Modules\Ordering\OrderService` in
  `src/Modules/Ordering/OrderService.php`). Modules never call each other's classes.

## Request - `Hub\Http\Request`

| Member | Meaning |
|---|---|
| `$req->method` | `GET`, `POST`, ... |
| `$req->path` | route path, no trailing slash, e.g. `/api/orders/5` |
| `$req->query(string $key, ?string $default = null): ?string` | query value; `$default` when absent; `''` when sent as an array |
| `$req->query` | the whole decoded query array |
| `$req->header(string $name): ?string` | case-insensitive |
| `$req->json(): array` | JSON body as an associative array. Empty body -> `[]`. Malformed or non-object -> throws `HttpError` 400 `BAD_JSON`. **Call it after the auth/role check** (SPEC s3 status order). |
| `$req->user` | set by `Auth::user()`; do not set it yourself |

## Response - `Hub\Http\Response`

| Call | Result |
|---|---|
| `Response::ok(array $data = [], int $status = 200)` | `{"success": true, ...$data}` |
| `Response::created(array $data)` | same, 201 |
| `Response::json(array $data, int $status = 200)` | raw JSON (you supply `success`) |
| `Response::error(int $status, string $code, string $message, ?array $errors = null)` | SPEC s3 error body |
| `Response::csv(string $csv, string $filename)` | `text/csv; charset=utf-8` + attachment header |
| `Response::html(string $html)` | `text/html` |

Every response gets `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`. JSON is encoded with
`JSON_PRESERVE_ZERO_FRACTION` (a float `125.0` stays `125.0`).

## Errors - `Hub\Http\HttpError` (throw it; the router turns it into the response)

| Factory | Status / code |
|---|---|
| `HttpError::unauthenticated()` | 401 `UNAUTHENTICATED` |
| `HttpError::forbidden()` | 403 `FORBIDDEN` |
| `HttpError::badJson()` | 400 `BAD_JSON` |
| `HttpError::notFound()` | 404 `NOT_FOUND` (also for another location's record - SPEC s3 scope rule) |
| `HttpError::validation(['field' => 'reason', ...])` | 422 `VALIDATION_ERROR` with `errors` |
| `HttpError::conflict(string $message)` | 409 `CONFLICT` |
| `new HttpError(int $status, string $code, string $message, ?array $errors = null)` | anything else |

Any other throwable -> 500 `SERVER_ERROR` with a generic message; class, message and file:line (never
arguments) go to the PHP error log prefixed `HUB-ERROR` (the test harness prints these lines on failure).
PHP warnings/notices are converted to `ErrorException`, so they also become 500s.

## Auth - `Hub\Auth\Auth`

| Call | Result |
|---|---|
| `Auth::user(Request $req): array` | the logged-in user or 401 |
| `Auth::requireRole(Request $req, string ...$roles): array` | the user, 401 if not logged in, 403 if the role is not listed. e.g. `Auth::requireRole($req, 'hq', 'hub')` |
| `Auth::publicUser(array $user): array` | USER shape of SPEC s6 |
| `Auth::ROLES` | `['hq', 'hub', 'franchise']` |

User array: `['id' => int, 'username' => string, 'email' => string, 'role' => 'hq'|'hub'|'franchise',
'location_id' => int, 'location_name' => string, 'location_type' => string]`. **Role = location type.**
Scope rule (SPEC s3): for `hub`, always use `$user['location_id']` and ignore any `location_id` sent.

## Database - `Hub\Db` (PDO; MySQL in production, SQLite in tests)

| Call | Result |
|---|---|
| `Db::all(string $sql, array $params = []): array` | all rows (assoc) |
| `Db::one(string $sql, array $params = []): ?array` | first row or null |
| `Db::value(string $sql, array $params = []): mixed` | first column of first row or null |
| `Db::exec(string $sql, array $params = []): int` | affected rows |
| `Db::insert(string $sql, array $params = []): int` | `lastInsertId()` |
| `Db::transaction(callable $fn): mixed` | runs `$fn` in one transaction (joins an open one), rolls back on throw |
| `Db::driver(): string` | `mysql` or `sqlite` |
| `Db::pdo(): PDO` | the connection |
| `Db::execScript(string $sql): int` | run a multi-statement file (schema/seed) |
| `Db::reset(): void` | drop the cached connection (white-box tests that `putenv('DB_PATH=...')`) |

SQL must run on both drivers (SPEC s2.1): no `ON DUPLICATE KEY`, `INSERT OR REPLACE`, `RETURNING`; do
SELECT-then-INSERT/UPDATE in a transaction. Cast fetched numbers with `(int)` - drivers may return strings.
Tables and columns: SPEC s4, `db/schema.sqlite.sql`, `db/schema.mysql.sql`.

## Money and quantities - `Hub\Money` (SPEC s3.1; stored as integer cents / integer milli)

| Call | Result |
|---|---|
| `Money::roundHalfUp(int $a, int $b): int` | `intdiv(2a + b, 2b)` (a >= 0, b > 0) |
| `Money::lineCents(int $qtyMilli, int $unitCostCents): int` | `roundHalfUp(qty_milli * unit_cost_cents, 1000)` |
| `Money::toRands(int $cents): float` | JSON money number, 2 dp |
| `Money::toQty(int $milli): float` | JSON quantity number, 3 dp |
| `Money::format(int $cents): string` | `"245.04"` (CSV, email) |
| `Money::formatQty(int $milli): string` | `"1.440"` |
| `Money::parseCents(mixed $v): ?int` | JSON number >= 0 with <= 2 dp -> cents, else null (strings/bools -> null) |
| `Money::parseMilli(mixed $v): ?int` | JSON number with <= 3 dp -> milli (sign kept), else null |

## Time - `Hub\Clock` (Africa/Johannesburg)

`Clock::now(): DateTimeImmutable`, `Clock::nowDb(): string` (`Y-m-d H:i:s` for DATETIME columns),
`Clock::today(): string` (`Y-m-d`), `Clock::firstOfMonth(): string`, `Clock::iso(?string $dbDateTime): ?string`
(ISO 8601 with offset for JSON), `Clock::isDate(mixed $v): bool` (strict real `YYYY-MM-DD`).

## Config - `Hub\Config`

`Config::get(string $key): string`, `Config::int(string $key): int`, `Config::env(): string`,
`Config::isProduction(): bool`, `Config::path(string $key): string` (repo-relative paths resolved).
Order: environment variable > `config.local.php` > default. Keys: SPEC s2.2 and `.env.example`.

## FinCon (read only) - `Hub\Fincon\*` (SPEC s10)

```php
$result = Hub\Fincon\FinconFactory::create()->readStock(['F-SMP001', 'F-SMP002']);
$result->status;      // FinconResult::OK | UNREACHABLE | AUTH_FAILED | MAINTENANCE | ERROR (string codes FINCON_*)
$result->quantities;  // ['F-SMP001' => 250.0, ...]  items FinCon did not return are absent
$result->error;       // ?string, safe to log (no credentials)
$result->isOk();
```

`FinconStockReader` has **only** `readStock()`. There is no write path and none may be added in Phase 1.
`FINCON_ADAPTER=mock` -> `MockFinconStockReader` (tests; refused in production -> `FINCON_ERROR`),
`http` -> `HttpFinconStockReader` (tunnel, allowlist `Login`, `GetStockQuantitiesForOpt2`, `GetStockQuantities`, `Logout`).

## Mail - `Hub\Mail\*` (SPEC s11)

```php
try {
    Hub\Mail\MailerFactory::create()->send($to, $subject, $plainTextBody);
    // record order_notifications status 'sent'
} catch (\RuntimeException $e) {
    // record status 'failed', error = $e->getMessage() (never fail the order)
}
```

`MAIL_TRANSPORT=log` appends `{"to","from","subject","body","at"}` to `MAIL_LOG_PATH`; `mail` uses PHP `mail()`.
An empty/invalid recipient or a line break in recipient/subject throws `RuntimeException` (`MailGuard`).

## Browser - `public/assets/app.js` (`window.Hub`)

`Hub.api(method, 'api/...', body) -> Promise<{status, data}>` (adds `X-Auth-Token`, 401 -> login.html),
`Hub.me()`, `Hub.requireRole('hq', 'hub')`, `Hub.renderNav(user)` (fills `<nav id="hub-nav">`), `Hub.logout()`,
`Hub.escapeHtml(v)` (**escape every value put into innerHTML**), `Hub.money(n)`, `Hub.qty(n)`.
Use relative URLs (`api/orders`, not `/api/orders`) so the app works from a sub-folder on xneelo.
Page skeleton: copy `public/index.html` (header with `<nav id="hub-nav">`, `<main>`, `assets/app.js`).

## Tests - `tests/lib/harness.php` (SPEC s2.6)

```php
require __DIR__ . '/../../lib/harness.php';           // from tests/unit/<Module>/x_test.php
test('name', function (): void {
    $app = TestApp::start(['HQ_ORDERS_EMAIL' => '']);  // fresh app; env overrides optional
    $hub = $app->client('hub.user');                   // fixture login (hq.admin, hub.user, hub2.user, franchise.user)
    $r = $hub->post('/api/orders', ['lines' => [['sku' => 'SMP001', 'qty' => 10]]]);
    assert_success($r, 201, 'place order');
    assert_money(125.0, $r->json['order']['lines'][0]['line_total'], 'line total');
});
run_tests();
```

Helpers: `assert_true`, `assert_same`, `assert_status`, `assert_success`, `assert_error($r, 403, 'FORBIDDEN', msg)`,
`assert_money`, `assert_qty`, `hub_list($r, 'orders', msg)`, `hub_object`, `hub_find($list, 'sku', 'SMP001')`,
`hub_column`, `hub_keys_matching($data, '/cost|price/i')`, `hub_today()`, `hub_date_plus(2)`,
`hub_json_lines($file)`, `hub_php([...args], $env)`, `$app->dbPath()`, `$app->mockLog()`, `$app->mailLog()`,
`FakeFincon::start()` + `$fake->appEnv()` + `$fake->requests()`.
White-box unit tests may `require HUB_TEST_ROOT . '/src/bootstrap.php'` and `putenv()` `DB_DRIVER=sqlite`,
`DB_PATH=<hub_test_dir(...)>/x.sqlite`, then `Hub\Db::reset()` and `Hub\Db::execScript(file_get_contents(HUB_ROOT . '/db/schema.sqlite.sql'))`.
Run one file: `php tests/run.php <path-substring>`. `HUB_TEST_KEEP=1` keeps the `var/test/` folders.
