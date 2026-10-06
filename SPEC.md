# SPEC - THE HUB (Xpresso central production kitchen), Phase 1

Source of truth for G1 (scaffold + acceptance tests), G2 module builders, G3 e2e, G4 verifier.
Locked business rules: `SPEC-locked-decisions.md`. Reference findings: `CODEBASE_MAP.md`.
Assumptions: `DECISIONS-LOG.md`. If this file and a locked decision disagree, the locked decision wins.

---

## 0. What Phase 1 does

The Hub orders ingredients from Xpresso HQ **at cost**, records production batches that consume
those ingredients by recipe, and reports the finished goods (quantity + cost per item) to HQ.
Finished food goes into HQ stock and stores order it from HQ as normal (A1). Phase 1 never writes
to FinCon (A2) and never writes to the existing ordering system's database.

Modules: **core** (G1 scaffold, shared), **M1-ordering**, **M2-recipes**, **M3-reports**.

---

## 1. Locations and roles

A user belongs to exactly one location. **The user's role is the location's `type`.**

| Role (`locations.type`) | Who | Can |
|---|---|---|
| `hq` | Xpresso HQ staff | Everything read across all locations; manage ingredient cost prices; update order status; recipes; reports |
| `hub` | Hub kitchen staff | Own location only: stock view, ingredients with cost, place/read own orders, recipes, record batches, own inventory, own reports |
| `franchise` | Franchise store user | Log in, `/api/me`, `/api/logout`, `/api/stock` (status only). **Nothing else. Never any cost price, recipe, batch, order or report.** |

There is no truck role in this repo.

---

## 2. Stack, layout, configuration, commands

### 2.1 Stack (matches the reference - see CODEBASE_MAP.md s1)
- Plain **PHP 8.3**, no framework, **no Composer**, no npm build. Vanilla HTML/JS pages.
- **PDO**: `pdo_mysql` in production (MySQL/MariaDB on xneelo), `pdo_sqlite` for tests and local dev.
  All SQL must run on both: no `ON DUPLICATE KEY`, no `INSERT OR REPLACE`, no `RETURNING`;
  use `SELECT` then `INSERT`/`UPDATE` inside a transaction; `lastInsertId()` for ids.
- Front controller `public/index.php` + `public/.htaccess` rewrite (Apache). Dev/test server:
  `php -S 127.0.0.1:<port> -t public public/index.php`.
- Timezone `Africa/Johannesburg` for all "today" logic. Timestamps in JSON: ISO 8601 with offset.

### 2.2 Configuration
`src/Config.php` resolves each key: **real environment variable** > `config.local.php` at repo root
(gitignored; `return ['KEY' => 'value', ...];` - the shared-hosting equivalent of the reference's
hand-edited `config.php`) > default below. The app never reads `.env`.

| Key | Default | Meaning |
|---|---|---|
| `APP_ENV` | `production` | `production` / `development` / `test` |
| `DB_DRIVER` | `mysql` | `mysql` or `sqlite` |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | `localhost`, `3306`, empty... | MySQL connection |
| `DB_PATH` | `var/hub.sqlite` | SQLite file (relative to repo root or absolute) |
| `AUTH_TOKEN_TTL_HOURS` | `8` | Token lifetime (reference session = 8 h) |
| `FINCON_ADAPTER` | `http` | `http` (real tunnel) or `mock`. `mock` is refused when `APP_ENV=production` (treated as `FINCON_ERROR`) |
| `FINCON_BASE_URL` | empty | Tunnel hostname, e.g. `https://fincon-api.example.invalid` (placeholder only) |
| `FINCON_CF_CLIENT_ID`, `FINCON_CF_CLIENT_SECRET` | empty | Cloudflare Access service token |
| `FINCON_SRV_USER`, `FINCON_SRV_PASS` | empty | HTTP Basic on FinCon's own web server |
| `FINCON_DATA_ID`, `FINCON_USER`, `FINCON_PASS` | empty | FinCon `Login` parameters |
| `FINCON_LOC_NO` | `0` | FinCon stock location |
| `FINCON_TIMEOUT_SECONDS` | `15` | Total request timeout (connect timeout fixed 5 s) |
| `FINCON_MOCK_LOG` | empty | If set, the mock appends one JSON line per call |
| `STOCK_CACHE_TTL_SECONDS` | `900` | Stock cache lifetime; `0` = always fetch |
| `MAIL_TRANSPORT` | `mail` in production, else `log` | `mail` = PHP `mail()` (reference channel), `log` = append JSON line to `MAIL_LOG_PATH` |
| `MAIL_LOG_PATH` | `var/mail.log` | |
| `MAIL_FROM` | `noreply@example.invalid` | Placeholder |
| `HQ_ORDERS_EMAIL` | empty | Where hub orders are emailed. Empty = notification recorded as `failed` |

All of these appear in `.env.example` with placeholder values only. No real values anywhere in git.

### 2.3 Commands (contract - G3/G4/G5 rely on these)
| Command | Does |
|---|---|
| `php bin/migrate.php` | Creates all tables for the configured driver from `db/schema.<driver>.sql`. Idempotent (`CREATE TABLE IF NOT EXISTS`). |
| `php bin/seed.php --samples` | Loads `db/seed_samples.sql` (SAMPLE ingredients + SAMPLE recipes, s5.2). Skips if `recipes` already has rows. Allowed in every env. |
| `php bin/seed.php --fixtures` | `--samples` plus the fixture locations and users (s5.1). **Exits 1 when `APP_ENV=production`.** |
| `php bin/hash_password.php` | Reads a password from stdin, prints a bcrypt hash (for creating real users by SQL on the host). |
| `php bin/import_ingredients.php <file.csv>` | (M1) Upserts ingredients from CSV `sku,name,unit,cost_price,fincon_item_no`. |
| `php tests/run.php` | Runs every test suite (s2.5). This exact line goes in `.pipeline/test-cmd.txt`. |
| Dev server | `php bin/migrate.php && php bin/seed.php --fixtures && php -S 127.0.0.1:8080 -t public public/index.php` with `APP_ENV=development DB_DRIVER=sqlite FINCON_ADAPTER=mock` |

### 2.4 Directory layout and file ownership

**Core / shared - written by G1, FROZEN after G1** (a module needing a change writes `LANE BREACH` in progress.md):
```
public/index.php            front controller: bootstrap, route discovery, dispatch
public/.htaccess            rewrite everything not a real file to index.php; deny *.log,*.txt,*.sqlite
public/index.html           landing page: links to the module pages by role
public/login.html           login form -> POST /api/login, stores token in sessionStorage
public/assets/app.js        fetch helper adding X-Auth-Token, 401 -> login.html, escapeHtml()
public/assets/app.css       shared styles
src/bootstrap.php           autoloader (namespace Hub\ -> src/), config, error handler (JSON 500, no stack traces)
src/Config.php  src/Db.php  src/Money.php  src/Clock.php
src/Http/Request.php  src/Http/Response.php  src/Http/Router.php  src/Http/HttpError.php
src/Auth/Auth.php  src/Auth/AuthController.php  src/Auth/routes.php
src/Fincon/FinconStockReader.php  src/Fincon/FinconResult.php  src/Fincon/MockFinconStockReader.php
src/Fincon/HttpFinconStockReader.php  src/Fincon/FinconFactory.php
src/Mail/Mailer.php  src/Mail/LogMailer.php  src/Mail/PhpMailMailer.php  src/Mail/MailerFactory.php
src/.htaccess  db/.htaccess  bin/.htaccess  tests/.htaccess  var/.htaccess   (Require all denied)
db/schema.mysql.sql  db/schema.sqlite.sql  db/seed_samples.sql
bin/migrate.php  bin/seed.php  bin/hash_password.php
docs/CORE_API.md            the PHP API modules may call (Request, Response, Auth, Db, Money, Clock, Fincon, Mailer)
tests/run.php  tests/lib/harness.php  tests/support/fake_fincon.php
tests/smoke/*_test.php
tests/acceptance/*_test.php (G1 ONLY - the pipeline reverts anyone else's edits)
.gitignore  .env.example  README.md  .pipeline/test-cmd.txt
```
`var/` is runtime only (gitignored): SQLite files, logs.

**M1-ordering** (only these files):
```
src/Modules/Ordering/routes.php
src/Modules/Ordering/StockController.php      src/Modules/Ordering/StockService.php
src/Modules/Ordering/IngredientController.php
src/Modules/Ordering/OrderController.php      src/Modules/Ordering/OrderService.php
src/Modules/Ordering/OrderNotifier.php
bin/import_ingredients.php
public/ordering.html  public/assets/ordering.js
tests/unit/Ordering/*_test.php
```
**M2-recipes** (only these files):
```
src/Modules/Recipes/routes.php
src/Modules/Recipes/RecipeController.php      src/Modules/Recipes/RecipeService.php
src/Modules/Recipes/BatchController.php       src/Modules/Recipes/BatchService.php
src/Modules/Recipes/InventoryController.php   src/Modules/Recipes/InventoryService.php
public/recipes.html  public/assets/recipes.js
tests/unit/Recipes/*_test.php
```
**M3-reports** (only these files):
```
src/Modules/Reports/routes.php
src/Modules/Reports/ReportController.php      src/Modules/Reports/ReportService.php
src/Modules/Reports/AdminListController.php
public/reports.html  public/assets/reports.js
tests/unit/Reports/*_test.php
```
Modules never call each other's PHP classes (they are built in parallel worktrees). Shared math lives in
`src/Money.php`. A module test that needs another module's data inserts rows directly into the test
SQLite DB using the schema in s4.

### 2.5 Routing contract (how modules plug in without touching shared files)
`public/index.php` loads `src/Auth/routes.php`, then every `src/Modules/*/routes.php` in sorted order.
Each `routes.php` **returns** an array of `[METHOD, PATH_PATTERN, callable(Request $req, array $params): Response]`.
Path params: `{id}` matches `[0-9]+`, `{sku}` matches `[A-Z0-9-]{2,20}`. Unknown path -> 404;
known path, wrong method -> 405. `docs/CORE_API.md` documents the exact class/method names G1 ships.

### 2.6 Test harness contract
- `php tests/run.php` discovers `tests/{smoke,unit,acceptance,e2e,security}/**/*_test.php` (folders that
  exist), runs **each file in its own PHP process**, prints one line per test, and ends with exactly
  `RESULT: <passed> passed, <failed> failed`. Exit 0 only if failed = 0 and passed > 0.
- `tests/lib/harness.php` gives every test: start a **fresh app** (new SQLite file under `var/test/`,
  `migrate` + `seed --fixtures`, `php -S` on a free port, killed at exit) with env overrides; an HTTP
  client returning `status`, decoded JSON, headers, raw body; `login(username)` -> token using s5.1
  credentials; assertion helpers.
- Test server env: `APP_ENV=test DB_DRIVER=sqlite DB_PATH=<fresh file> FINCON_ADAPTER=mock
  FINCON_MOCK_LOG=<file> MAIL_TRANSPORT=log MAIL_LOG_PATH=<file> STOCK_CACHE_TTL_SECONDS=0
  HQ_ORDERS_EMAIL=hq-orders@example.test`.
- `tests/support/fake_fincon.php`: a `php -S` router that imitates the FinCon tunnel (s10.4) and logs
  every request to `FAKE_FINCON_LOG`.
- Acceptance tests are **black-box over HTTP** using only s6-s9 and fixture data; they look ids up
  through the API (never assume an id), except where a test may read the mock/mail/fake-FinCon log files
  whose paths the harness itself set.

---

## 3. API conventions (all routes)

- JSON in, JSON out. Responses: `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`,
  `X-Content-Type-Options: nosniff`. Every body has `"success": true|false`.
- Errors: `{"success": false, "code": "<CODE>", "message": "<human text>"}`. Validation errors add
  `"errors": {"<field>": "<reason>"}`.
- **Authentication:** header `X-Auth-Token: <token>` (the reference admin pattern). Missing/unknown/
  expired token -> **401** `UNAUTHENTICATED`. Only `GET /api/health` and `POST /api/login` are public.
- **Check order (status precedence):** 401 auth -> 403 role (`FORBIDDEN`) -> 400 malformed JSON
  (`BAD_JSON`) -> 404 not found / outside caller's scope (`NOT_FOUND`) -> 422 validation
  (`VALIDATION_ERROR`) -> 409 conflict (`CONFLICT`). 500 = `SERVER_ERROR`, never leaks internals.
- **Scope rule:** for a `hub` user every read and write is limited to the user's own location. A
  `location_id` sent by a hub user (query or body) is **ignored**. A record of another location answers
  **404**, never 403 (do not reveal it exists).
- Numbers: money is a JSON number rounded to 2 dp (rands, excl. VAT). Quantities are JSON numbers with up
  to 3 dp, in the ingredient's unit. Inputs must be JSON numbers (strings -> 422). A quantity with more
  than 3 dp, or money with more than 2 dp -> 422.
- Dates `YYYY-MM-DD`; "today" = Africa/Johannesburg.
- Hub/HQ orders are **internal transfers at cost - no VAT is added or stored** (locked decision).

### 3.1 Money and rounding (exact - tests depend on it)
Stored as integers: money in **cents**, quantities in **thousandths ("milli")** of the unit.
`round_half_up(a, b)` for non-negative integers = `intdiv(2a + b, 2b)`.
- Line cost (orders, recipe preview, batches): `line_cents = round_half_up(qty_milli * unit_cost_cents, 1000)`.
- Recipe `cost_per_unit` = sum of line costs with `qty_milli` = recipe quantity for ONE finished unit.
- Batch: `qty_used_milli = recipe_qty_milli * batch_quantity`; `line_cost_cents` as above;
  `total_cost_cents = sum(lines)`; `cost_per_item_cents = round_half_up(total_cost_cents, batch_quantity)`.
- Report: `avg_cost_per_item_cents = round_half_up(sum(total_cost_cents), sum(quantity))`.
- Order: `line_total_cents` as line cost; `total_cents = sum(lines)`.

---

## 4. Data model (all modules; created by G1 in both schema files)

Types given for MySQL; SQLite uses INTEGER/TEXT equivalents. All tables InnoDB, utf8mb4.

```
locations
  id INT PK AUTO_INCREMENT
  name VARCHAR(100) NOT NULL
  type VARCHAR(20) NOT NULL            -- 'hq' | 'hub' | 'franchise' (CHECK)
  active TINYINT NOT NULL DEFAULT 1
  created_at DATETIME NOT NULL

users
  id INT PK AUTO_INCREMENT
  location_id INT NOT NULL FK locations.id
  username VARCHAR(60) NOT NULL UNIQUE
  email VARCHAR(190) NOT NULL
  password_hash VARCHAR(255) NOT NULL  -- password_hash(PASSWORD_BCRYPT)
  active TINYINT NOT NULL DEFAULT 1
  created_at DATETIME NOT NULL

auth_tokens
  id INT PK AUTO_INCREMENT
  user_id INT NOT NULL FK users.id
  token_hash CHAR(64) NOT NULL UNIQUE  -- sha256 hex of the token; raw token never stored
  expires_at DATETIME NOT NULL
  created_at DATETIME NOT NULL

ingredients                            -- the hub ingredient catalogue, priced AT COST
  sku VARCHAR(20) PK                   -- ^[A-Z0-9-]{2,20}$
  name VARCHAR(150) NOT NULL
  unit VARCHAR(20) NOT NULL            -- kg, g, l, ml, each ...
  cost_cents INT NOT NULL              -- warehouse cost per unit, excl VAT, >= 0
  fincon_item_no VARCHAR(25) NULL      -- FinCon ItemNo for the stock view; NULL = unmapped
  is_sample TINYINT NOT NULL DEFAULT 0
  active TINYINT NOT NULL DEFAULT 1
  created_at DATETIME NOT NULL
  updated_at DATETIME NOT NULL

stock_cache                            -- last FinCon quantities read (never written back to FinCon)
  fincon_item_no VARCHAR(25) PK
  in_stock_milli BIGINT NOT NULL
  fetched_at DATETIME NOT NULL

orders                                 -- hub -> HQ ingredient orders
  id INT PK AUTO_INCREMENT
  location_id INT NOT NULL FK locations.id
  created_by_user_id INT NOT NULL FK users.id
  status VARCHAR(20) NOT NULL DEFAULT 'pending'   -- pending|in_process|out_for_delivery|delivered|cancelled
  price_basis VARCHAR(10) NOT NULL DEFAULT 'cost' -- always 'cost' for hub orders
  total_cents BIGINT NOT NULL
  requested_delivery_date DATE NULL
  notes VARCHAR(500) NULL
  created_at DATETIME NOT NULL
  status_updated_at DATETIME NULL
  delivered_at DATETIME NULL
  INDEX (location_id, created_at)

order_lines
  id INT PK AUTO_INCREMENT
  order_id INT NOT NULL FK orders.id
  sku VARCHAR(20) NOT NULL
  name_snapshot VARCHAR(150) NOT NULL
  unit_snapshot VARCHAR(20) NOT NULL
  qty_milli BIGINT NOT NULL
  unit_cost_cents INT NOT NULL         -- ingredients.cost_cents at order time
  line_total_cents BIGINT NOT NULL

order_notifications                    -- outbox: proof each order was sent to HQ
  id INT PK AUTO_INCREMENT
  order_id INT NOT NULL FK orders.id
  channel VARCHAR(20) NOT NULL         -- 'email'
  recipient VARCHAR(190) NOT NULL
  status VARCHAR(10) NOT NULL          -- 'sent' | 'failed'
  error VARCHAR(255) NULL
  created_at DATETIME NOT NULL

recipes                                -- one finished item = one recipe, quantities per ONE finished unit
  id INT PK AUTO_INCREMENT
  finished_sku VARCHAR(20) NOT NULL UNIQUE
  name VARCHAR(150) NOT NULL
  unit VARCHAR(20) NOT NULL            -- unit of the finished item: each, tub, tray ...
  is_sample TINYINT NOT NULL DEFAULT 0
  active TINYINT NOT NULL DEFAULT 1
  created_at DATETIME NOT NULL
  updated_at DATETIME NOT NULL

recipe_lines
  id INT PK AUTO_INCREMENT
  recipe_id INT NOT NULL FK recipes.id
  sku VARCHAR(20) NOT NULL FK ingredients.sku
  qty_milli BIGINT NOT NULL            -- > 0
  UNIQUE (recipe_id, sku)

batches                                -- a production run; costs SNAPSHOTTED at record time
  id INT PK AUTO_INCREMENT
  location_id INT NOT NULL FK locations.id
  recipe_id INT NOT NULL FK recipes.id
  created_by_user_id INT NOT NULL FK users.id
  finished_sku VARCHAR(20) NOT NULL
  name_snapshot VARCHAR(150) NOT NULL
  unit_snapshot VARCHAR(20) NOT NULL
  quantity INT NOT NULL                -- finished units, >= 1
  produced_on DATE NOT NULL
  total_cost_cents BIGINT NOT NULL
  cost_per_item_cents BIGINT NOT NULL
  notes VARCHAR(500) NULL
  created_at DATETIME NOT NULL
  INDEX (location_id, produced_on)

batch_lines
  id INT PK AUTO_INCREMENT
  batch_id INT NOT NULL FK batches.id
  sku VARCHAR(20) NOT NULL
  name_snapshot VARCHAR(150) NOT NULL
  unit_snapshot VARCHAR(20) NOT NULL
  qty_used_milli BIGINT NOT NULL
  unit_cost_cents INT NOT NULL
  line_cost_cents BIGINT NOT NULL

inventory_movements                    -- hub ingredient ledger; on_hand = SUM(qty_delta_milli)
  id INT PK AUTO_INCREMENT
  location_id INT NOT NULL FK locations.id
  sku VARCHAR(20) NOT NULL
  qty_delta_milli BIGINT NOT NULL      -- + in, - out
  reason VARCHAR(20) NOT NULL          -- 'order_delivered' | 'batch' | 'adjustment'
  ref_id INT NULL                      -- orders.id or batches.id
  note VARCHAR(200) NULL
  created_by_user_id INT NULL FK users.id
  created_at DATETIME NOT NULL
  INDEX (location_id, sku)
```
Writers: `orders`, `order_lines`, `order_notifications`, `stock_cache`, `ingredients`, `inventory_movements`
reason `order_delivered` -> M1. `recipes`, `recipe_lines`, `batches`, `batch_lines`, `inventory_movements`
reasons `batch`/`adjustment` -> M2. M3 is read-only. Core writes `locations`, `users`, `auth_tokens`.

---

## 5. Seed data

### 5.1 Fixtures (`seed --fixtures`; test/dev only; NOT real credentials)
| Location (id order) | type | User | Email | Password |
|---|---|---|---|---|
| Xpresso HQ | hq | `hq.admin` | hq.admin@example.test | `TestPass!Hq1` |
| The Hub | hub | `hub.user` | hub.user@example.test | `TestPass!Hub1` |
| Hub Two (TEST) | hub | `hub2.user` | hub2.user@example.test | `TestPass!Hub2` |
| Franchise Store (TEST) | franchise | `franchise.user` | franchise.user@example.test | `TestPass!Fr1` |
Inventory starts empty (all on_hand 0).

### 5.2 Samples (`db/seed_samples.sql`; all marked SAMPLE, `is_sample = 1`; real recipes loaded later per A3)
Ingredients:
| sku | name | unit | cost | fincon_item_no |
|---|---|---|---|---|
| SMP001 | SAMPLE Cake flour | kg | 12.50 | F-SMP001 |
| SMP002 | SAMPLE Castor sugar | kg | 18.00 | F-SMP002 |
| SMP003 | SAMPLE Liquid eggs | l | 45.00 | F-SMP003 |
| SMP004 | SAMPLE Butter | kg | 120.00 | F-SMP004 |
| SMP005 | SAMPLE Cocoa powder | kg | 160.00 | F-SMP005 |
| SMP006 | SAMPLE Full cream milk | l | 20.00 | NULL |

Recipes (quantity per ONE finished unit):
| id | finished_sku | name | unit | lines | cost_per_unit |
|---|---|---|---|---|---|
| 1 | SMP-F01 | SAMPLE Choc muffin | each | SMP001 0.060, SMP002 0.030, SMP003 0.020, SMP004 0.015, SMP005 0.010 | 5.59 |
| 2 | SMP-F02 | SAMPLE Plain scone | each | SMP001 0.080, SMP004 0.020, SMP006 0.040, SMP002 0.010 | 4.38 |
| 3 | SMP-F03 | SAMPLE Vanilla custard 1 l | tub | SMP006 0.800, SMP003 0.100, SMP002 0.120 | 22.66 |

In `APP_ENV=production`, `POST /api/orders` rejects any line whose ingredient has `is_sample = 1`
(422, field `lines.<n>.sku`, reason `sample ingredient`) so SAMPLE data can never reach HQ as a real order.

### 5.3 Hand-calculated samples (acceptance tests assert these exact values)
**Batch of 24 x SAMPLE Choc muffin:**
| sku | qty_used | unit_cost | line_cost |
|---|---|---|---|
| SMP001 | 1.440 | 12.50 | 18.00 |
| SMP002 | 0.720 | 18.00 | 12.96 |
| SMP003 | 0.480 | 45.00 | 21.60 |
| SMP004 | 0.360 | 120.00 | 43.20 |
| SMP005 | 0.240 | 160.00 | 38.40 |
total_cost **134.16**, cost_per_item **5.59**; inventory of SMP001..SMP005 falls by exactly the qty_used; SMP006 unchanged.

**Batch of 12 x Choc muffin:** lines 9.00, 6.48, 10.80, 21.60, 19.20 -> total **67.08**, per item **5.59**.
**Batch of 10 x SAMPLE Plain scone:** SMP001 0.800 -> 10.00, SMP004 0.200 -> 24.00, SMP006 0.400 -> 8.00, SMP002 0.100 -> 1.80 -> total **43.80**, per item **4.38**.
**Batch of 5 x SAMPLE Vanilla custard:** SMP006 4.000 -> 80.00, SMP003 0.500 -> 22.50, SMP002 0.600 -> 10.80 -> total **113.30**, per item **22.66**.

**Finished-goods report**, hub.user records muffin x24, scone x10, muffin x12 today; hub2.user records custard x5 today:
- hub.user report: SMP-F01 qty 36, total 201.24, avg 5.59, batch_count 2; SMP-F02 qty 10, total 43.80, avg 4.38, batch_count 1; **totals qty 46, total_cost 245.04, batch_count 3**.
- hq.admin report (all locations): adds SMP-F03 qty 5, total 113.30, avg 22.66 -> **totals qty 51, total_cost 358.34, batch_count 4**.

**Order at cost:** hub.user orders SMP001 qty 10 and SMP004 qty 2.5 -> unit prices 12.50 and 120.00,
line totals 125.00 and 300.00, **total 425.00**, even if the request body carries other prices.

---

## 6. Core API (G1)

| Method | Path | Roles | Success |
|---|---|---|---|
| GET | `/api/health` | public | 200 `{"success":true,"status":"ok"}` |
| POST | `/api/login` | public | 200 |
| POST | `/api/logout` | any | 200 `{"success":true}`; token deleted; reuse -> 401 |
| GET | `/api/me` | any | 200 `{"success":true,"user":USER}` |

`POST /api/login` body `{"username": str, "email": str, "password": str}` - all three required, as in the
reference (`admin_api.php:26-46`).
- 200 `{"success":true,"token":"<64 hex>","expires_at":"<ISO>","user":USER}`
- 422 if any field missing/empty. 401 `INVALID_CREDENTIALS` with ONE generic message for wrong
  username, wrong email, wrong password, inactive user or inactive location (no account enumeration).

`USER` = `{"id":int,"username":str,"role":"hq"|"hub"|"franchise","location":{"id":int,"name":str,"type":str}}`.
Token: `bin2hex(random_bytes(32))`, stored as sha256, expires after `AUTH_TOKEN_TTL_HOURS`.

---

## 7. M1-ordering API

| Method | Path | hq | hub | franchise |
|---|---|---|---|---|
| GET | `/api/stock` | yes | yes | yes (same payload; it has no cost) |
| GET | `/api/ingredients` | yes | yes | 403 |
| PUT | `/api/admin/ingredients/{sku}` | yes | 403 | 403 |
| POST | `/api/orders` | 403 | yes | 403 |
| GET | `/api/orders` | all locations | own | 403 |
| GET | `/api/orders/{id}` | any | own, else 404 | 403 |
| PATCH | `/api/orders/{id}/status` | yes | 403 | 403 |

### 7.1 `GET /api/stock` - read-only FinCon stock view (same visibility as franchisees: status, no quantity, no price)
200:
```json
{"success":true,"source":"fincon","fincon_status":"OK","stale":false,"fetched_at":"2026-10-06T10:00:00+02:00",
 "items":[{"sku":"SMP001","name":"SAMPLE Cake flour","unit":"kg","stock_status":"in_stock"}]}
```
- `items` = all active ingredients, sorted by sku. `stock_status` in `in_stock|low_stock|out_of_stock`.
- Rule (reference `get_stock.php:38-39`): qty `<= 0` -> `out_of_stock`; `<= 10` -> `low_stock`; else `in_stock`.
  **Every uncertainty fails open to `in_stock`**: no `fincon_item_no`, item not returned, FinCon down, no cache.
- Reads through `FinconStockReader` only (s10). Cache in `stock_cache` for `STOCK_CACHE_TTL_SECONDS`.
  On a FinCon failure: serve existing cache, `fincon_status` = the failure code, `stale: true`.
  `stale` is also true when `fetched_at` is older than 48 h. `fetched_at` null if never fetched.
- Never contains any key matching `/cost|price/i`.
- With the mock (s10.3) the fixture result is: SMP001 in_stock, SMP002 low_stock, SMP003 out_of_stock,
  SMP004 in_stock, SMP005 in_stock, SMP006 in_stock (unmapped).

### 7.2 `GET /api/ingredients`
200 `{"success":true,"ingredients":[{"sku","name","unit","cost_price","fincon_item_no","is_sample","active"}]}`
active only, sorted by sku; `hq` may add `?include_inactive=1`. `cost_price` = cost per unit (2 dp).

### 7.3 `PUT /api/admin/ingredients/{sku}` (hq)
Body `{"name": str 1-150, "unit": str 1-20, "cost_price": number >= 0 max 2 dp, "fincon_item_no": str|null (<=25), "active": bool (optional, default true)}`.
201 when created, 200 when updated: `{"success":true,"ingredient":{...as 7.2}}`. 422 on validation.
Changing a cost never changes existing order lines or batches (they hold snapshots).

### 7.4 `POST /api/orders` (hub) - order ingredients from HQ at COST
Body:
```json
{"lines":[{"sku":"SMP001","qty":10},{"sku":"SMP004","qty":2.5}],
 "requested_delivery_date":"2026-10-08","notes":"optional, <=500 chars"}
```
- `unit_price` is **always** the ingredient's current `cost_cents`, server-side. Any client price field
  (`price`, `unit_price`, `cost_price`, `total`, ...) is ignored.
- 422: `lines` missing/empty/>200 lines; unknown or inactive sku; qty not a number, <= 0, > 100000 or > 3 dp;
  duplicate sku; `requested_delivery_date` not `YYYY-MM-DD` or before today; notes > 500; sample
  ingredient in production (s5.2).
- Order is saved (`orders` + `order_lines`, status `pending`, `price_basis` `cost`) in one transaction,
  then HQ is notified (s11). A failed notification does **not** fail the order.
- 201:
```json
{"success":true,"order":{"id":1,"location_id":2,"location_name":"The Hub","status":"pending","price_basis":"cost",
 "requested_delivery_date":"2026-10-08","notes":null,"created_at":"...","created_by":"hub.user",
 "lines":[{"sku":"SMP001","name":"SAMPLE Cake flour","unit":"kg","qty":10,"unit_price":12.5,"line_total":125.0}],
 "total":425.0,"hq_notification":{"channel":"email","status":"sent"}}}
```
`ORDER` below = the `order` object above (`hq_notification` = latest notification row).

### 7.5 `GET /api/orders`
200 `{"success":true,"orders":[{"id","location_id","location_name","status","total","line_count","created_at"}]}`
newest first. hub: own location only (any `location_id` param ignored). hq: all; optional
`?location_id=<int>` and `?status=<status>` filters (invalid value -> 422).

### 7.6 `GET /api/orders/{id}`
200 `{"success":true,"order":ORDER}`. hub + other location's order -> **404**. Unknown id -> 404.

### 7.7 `PATCH /api/orders/{id}/status` (hq) - HQ fulfils the order
Body `{"status": "in_process"|"out_for_delivery"|"delivered"|"cancelled"}`.
Forward-only along `pending -> in_process -> out_for_delivery -> delivered` (skipping forward allowed).
`cancelled` only from `pending` or `in_process`. Same status, backwards, or from `delivered`/`cancelled` -> 409.
On the transition to `delivered`: set `delivered_at` and add one `inventory_movements` row per line
(`+qty_milli`, reason `order_delivered`, `ref_id` = order id) for the order's location - exactly once.
200 `{"success":true,"order":ORDER}`.

### 7.8 UI `public/ordering.html`
Hub: stock table (status badges), ingredient list with cost price and qty inputs, place order, order
history with detail. HQ: all orders with a status dropdown. Uses `public/assets/app.js`; all text escaped.

---

## 8. M2-recipes API

| Method | Path | hq | hub | franchise |
|---|---|---|---|---|
| GET | `/api/recipes` | yes | yes | 403 |
| GET | `/api/recipes/{id}` | yes | yes | 403 |
| POST | `/api/recipes` | yes | yes | 403 |
| PUT | `/api/recipes/{id}` | yes | yes | 403 |
| POST | `/api/batches` | 403 | yes | 403 |
| GET | `/api/batches/{id}` | any | own, else 404 | 403 |
| GET | `/api/inventory` | `?location_id=` required | own | 403 |
| POST | `/api/inventory/adjustments` | body `location_id` required | own | 403 |

Recipes are shared by all hub locations; batches and inventory belong to a location.

### 8.1 `GET /api/recipes`, `GET /api/recipes/{id}`
`RECIPE` = `{"id","finished_sku","name","unit","is_sample","active","cost_per_unit",
"lines":[{"sku","name","unit","qty","unit_cost","line_cost"}]}` using CURRENT ingredient costs (s3.1).
List: `{"success":true,"recipes":[RECIPE...]}` active only, sorted by finished_sku (`?include_inactive=1` allowed).
Detail: `{"success":true,"recipe":RECIPE}`; unknown id -> 404.

### 8.2 `POST /api/recipes`, `PUT /api/recipes/{id}`
Body `{"finished_sku": ^[A-Z0-9-]{2,20}$, "name": 1-150, "unit": 1-20, "active": bool (PUT only, optional),
"lines":[{"sku": existing active ingredient, "qty": >0, <=100000, <=3 dp}]}` (1-50 lines, no duplicate sku).
POST -> 201 `{"success":true,"recipe":RECIPE}`; PUT -> 200 and replaces all lines. Duplicate
`finished_sku` -> 409. Past batches are unaffected (snapshots). No delete; deactivate with `"active": false`.

### 8.3 `POST /api/batches` (hub) - record a production batch
Body `{"recipe_id": int, "quantity": integer 1-100000, "produced_on": "YYYY-MM-DD" (optional, default today, not in the future), "notes": optional <=500}`.
- 422 if recipe unknown or inactive, quantity not a positive integer, bad/future date.
- In ONE transaction: snapshot every recipe line at the current ingredient cost (s3.1), insert `batches`
  + `batch_lines`, insert one `inventory_movements` row per line (`-qty_used_milli`, reason `batch`,
  `ref_id` batch id) for the user's location.
- Negative on-hand is **allowed** (production is never blocked by the ledger); each ingredient whose
  on_hand is below 0 after the batch is listed in `warnings`.
- 201:
```json
{"success":true,"batch":{"id":1,"location_id":2,"recipe_id":1,"finished_sku":"SMP-F01","name":"SAMPLE Choc muffin",
 "unit":"each","quantity":24,"produced_on":"2026-10-06","total_cost":134.16,"cost_per_item":5.59,"notes":null,
 "created_at":"...","created_by":"hub.user",
 "lines":[{"sku":"SMP001","name":"SAMPLE Cake flour","unit":"kg","qty_used":1.44,"unit_cost":12.5,"line_cost":18.0}]},
 "warnings":[{"sku":"SMP001","on_hand_after":-1.44}]}
```
`BATCH` = the `batch` object.

### 8.4 `GET /api/batches/{id}`
200 `{"success":true,"batch":BATCH}`; hub + other location -> 404.

### 8.5 `GET /api/inventory`
200 `{"success":true,"location_id":2,"items":[{"sku","name","unit","on_hand"}]}` - every active ingredient
(0 when no movements), sorted by sku; may be negative. hq without valid hub `location_id` -> 422.

### 8.6 `POST /api/inventory/adjustments`
Body `{"sku": active ingredient, "qty_delta": non-zero number, <=3 dp, |x| <= 100000, "reason": str 1-200, "location_id": int (hq only)}`.
201 `{"success":true,"movement":{"id","location_id","sku","qty_delta","reason":"adjustment","note","created_at"},"on_hand":<new>}`.
(`note` stores the given reason text.) For opening balances and stock counts.

### 8.7 UI `public/recipes.html`
Recipe list with cost per unit, recipe editor, record-batch form showing the cost result and warnings,
inventory table with adjustment form.

---

## 9. M3-reports API (read-only)

| Method | Path | hq | hub | franchise |
|---|---|---|---|---|
| GET | `/api/reports/finished-goods` | all, or `?location_id=` | own | 403 |
| GET | `/api/admin/recipes` | yes | yes | 403 |
| GET | `/api/admin/batches` | all, or `?location_id=` | own | 403 |

### 9.1 `GET /api/reports/finished-goods` - "finished goods for HQ"
Query `from`, `to` (`YYYY-MM-DD`, inclusive, on `produced_on`; default first day of the current month ..
today), `location_id` (hq only), `format=json|csv` (default json). Bad date or `from > to` -> 422.
200 JSON:
```json
{"success":true,"from":"2026-10-01","to":"2026-10-06","location_id":null,
 "items":[{"finished_sku":"SMP-F01","name":"SAMPLE Choc muffin","unit":"each","quantity":36,"total_cost":201.24,"avg_cost_per_item":5.59,"batch_count":2}],
 "totals":{"quantity":46,"total_cost":245.04,"batch_count":3},
 "batches":[{"id","produced_on","location_id","location_name","finished_sku","name","quantity","cost_per_item","total_cost"}]}
```
- Built only from `batches` snapshots (never recomputed from current costs). `items` grouped by
  `finished_sku`, sorted by finished_sku; `batches` sorted by produced_on then id.
- Invariants: `totals.quantity = sum(items.quantity) = sum(batches.quantity)`;
  `totals.total_cost = sum(items.total_cost) = sum(batches.total_cost)`; `totals.batch_count = count(batches)`.
- `format=csv`: `Content-Type: text/csv`, header row
  `finished_sku,name,unit,quantity,total_cost,avg_cost_per_item,batch_count`, one row per item, then a
  `TOTAL,,,<qty>,<cost>,,<count>` row. Values formatted 2 dp for money. (For HQ to capture into FinCon by hand - Phase 1 never posts it.)

### 9.2 `GET /api/admin/recipes`
200 `{"success":true,"recipes":[{"id","finished_sku","name","unit","is_sample","active","line_count","cost_per_unit","batch_count","last_produced_on"}]}`
including inactive, sorted by finished_sku. For hub, `batch_count`/`last_produced_on` count own location only.

### 9.3 `GET /api/admin/batches`
Query `from`, `to`, `location_id` (hq only), `recipe_id`. 200 `{"success":true,"batches":[...same fields as 9.1 batches plus "created_by"]}` newest first.

### 9.4 UI `public/reports.html`
Date-range finished-goods report with totals and CSV download; admin recipe list; admin batch list.

---

## 10. FinCon - read-only interface

### 10.1 Interface (core, `src/Fincon/FinconStockReader.php`)
One method only: `readStock(array $itemNos): FinconResult` where `FinconResult` has `status`
(`OK|FINCON_UNREACHABLE|FINCON_AUTH_FAILED|FINCON_MAINTENANCE|FINCON_ERROR`), `quantities`
(`array<string itemNo, float>`), `error` (`?string`). **There is no write method, and none may be added in Phase 1.**
`FinconFactory` picks the adapter from `FINCON_ADAPTER`.

### 10.2 HTTP adapter (`HttpFinconStockReader`) - same protocol as the reference (CODEBASE_MAP.md s4)
1. `POST {FINCON_BASE_URL}/datasnap/rest/FinconAPI/"Login"/` body `{"_parameters":[DATA_ID, USER, PASS, false]}` -> `result[0].ConnectID`.
2. `POST .../"GetStockQuantitiesForOpt2"/<ConnectID>/<FINCON_LOC_NO>/` body `{"ItemList":[{"ItemNo":"..."}]}`, 100 items per call. Quantity = first `StockLoc[].InStock` of each `result[0].Stock[]` item.
3. `GET .../"Logout"/<ConnectID>/` always (finally).
- Every request carries `CF-Access-Client-Id`, `CF-Access-Client-Secret`, HTTP Basic `FINCON_SRV_USER:FINCON_SRV_PASS`, `Accept: application/json`.
- Status mapping as reference: 403 or 401 -> `FINCON_AUTH_FAILED`; no response -> `FINCON_UNREACHABLE` (or `FINCON_MAINTENANCE` 15:25-15:30 SAST); other non-2xx -> `FINCON_UNREACHABLE`; unparseable/`ErrorInfo` -> `FINCON_ERROR`.
- Function names are a fixed allowlist in code: **`Login`, `GetStockQuantitiesForOpt2`, `GetStockQuantities`, `Logout`** (all reads or session handling). Never log credentials, headers or ConnectID.

### 10.3 Mock adapter (`MockFinconStockReader`) - used by all tests
Fixed quantities: `F-SMP001` 250, `F-SMP002` 8, `F-SMP003` 0, `F-SMP004` 40, `F-SMP005` 12. Unknown item numbers are not returned.
Status `OK`. If `FINCON_MOCK_LOG` is set, appends `{"op":"readStock","kind":"read","items":<n>,"at":"<ISO>"}` per call.

### 10.4 Fake FinCon server (`tests/support/fake_fincon.php`) - for the "nothing writes to FinCon" test
A `php -S` router answering the s10.2 protocol with the s10.3 quantities: `Login` -> `{"result":[{"Connected":true,"ConnectID":"TEST-CONNECT"}]}`,
`GetStockQuantitiesForOpt2` / `GetStockQuantities` -> `{"result":[{"Stock":[{"ItemNo":"F-SMP001","StockLoc":[{"LocNo":"00","InStock":250}]}...]}]}`,
`Logout` -> `{"result":[{"Disconnected":true}]}`, anything else -> 404. Appends one JSON line per request to
`FAKE_FINCON_LOG`: `{"method","function","has_cf_id","has_cf_secret","has_basic"}`.

---

## 11. Integration - how hub orders reach HQ

**Chosen: least-risk option the reference supports (CODEBASE_MAP.md s3).**
1. The order is stored in **The Hub's own database** (`orders`, `order_lines`). Nothing is written into the
   existing ordering system's database or into FinCon.
2. HQ is **emailed** at `HQ_ORDERS_EMAIL` - the same channel `submit_order.php:201` uses to tell `ADMIN_EMAIL`
   about franchise orders. Subject `Hub order #<id> - <location name>`; body lists sku, name, qty, unit,
   unit cost, line total, total, requested delivery date, notes, and the line "Internal transfer at cost - not a VAT invoice."
   Sent through `Mailer` (`mail` in production, `log` in test). Each attempt writes an `order_notifications`
   row (`sent` / `failed` + error). A failure never loses the order.
3. HQ staff (`hq` role) see every hub order in The Hub (`GET /api/orders`, ordering.html) and move it
   through `pending -> in_process -> out_for_delivery -> delivered` (same statuses as the reference).

Rejected for Phase 1: inserting into the reference `orders`/`order_items` tables (couples two apps to one
schema, `client_id`/`shop_id` FKs, VAT totals and region scoping that would expose cost prices to regional
admins; needs a reference change, which is out of bounds) and any FinCon sales-order posting (A2).

---

## 12. Acceptance checks (copied from the role spec; G1 writes one or more black-box tests per check)

| # | Check (verbatim) | How it is tested (fixtures s5, values s5.3) |
|---|---|---|
| A1 | Hub user cannot read other locations' orders. | hub2.user places an order. hub.user: `GET /api/orders/{that id}` -> 404; `GET /api/orders` and `GET /api/orders?location_id=<hub2 location>` do not contain it; `PATCH .../status` -> 403. Same 404 for hub2's batch via `GET /api/batches/{id}`. hq.admin sees both orders. |
| A2 | Franchise users never see cost prices or recipes. | franchise.user gets 403 on every route in s7-s9 except `GET /api/stock`. `GET /api/me` and `GET /api/stock` return 200 with no key matching `/cost\|price\|recipe/i` at any depth and no recipe name. |
| A3 | Hub order lines use cost price. | hub.user order from s5.3 (body also sends `unit_price: 1.00`, `price: 1.00`) -> lines 12.50 / 120.00, totals 125.00 / 300.00, total 425.00, `price_basis` `cost`; each `unit_price` equals `cost_price` from `GET /api/ingredients`. After hq.admin changes SMP001 cost to 13.00, a new order uses 13.00 and the first order still shows 12.50. |
| A4 | A production batch deducts the right ingredient quantities; cost per item matches a hand-calculated sample. | Read `GET /api/inventory`, `POST /api/batches` muffin x24, read again: deltas exactly -1.440, -0.720, -0.480, -0.360, -0.240 for SMP001..SMP005, SMP006 unchanged; `total_cost` 134.16; `cost_per_item` 5.59; line costs as s5.3. |
| A5 | Finished-goods report totals match the batches. | Batches from s5.3; hub.user report totals 46 / 245.04 / 3 and per-item rows as s5.3; hq.admin report totals 51 / 358.34 / 4; in every report `totals` equal the sums over `batches` and over `items`. |
| A6 | Nothing writes to FinCon. | App started with `FINCON_ADAPTER=http` against the fake FinCon (s10.4). Run the whole E2E flow plus an order status change and an inventory adjustment. Fake log: at least one request, every `function` in {Login, GetStockQuantitiesForOpt2, GetStockQuantities, Logout}, every request had CF-Access headers and Basic auth. Mock run: every `FINCON_MOCK_LOG` line has `"kind":"read"`. |
| A7 | (locked rule) Hub users CAN read FinCon stock. | hub.user `GET /api/stock` -> 200, `fincon_status` `OK`, statuses exactly as s7.1 for the mock and for the fake FinCon. |

Also required by the verifier (`.claude/agents/verifier.md`): no real secrets committed; dependency audit
(no Composer/npm dependencies - report "none").

## 13. E2E smoke flow (G3, `tests/e2e/`)
Start the real app on a free port, then through the HTTP API: hub.user logs in -> `GET /api/stock` ->
`POST /api/orders` -> `POST /api/batches` -> `GET /api/reports/finished-goods` shows that batch. Optionally
drive `public/login.html` with a headless browser if one is already installed.

## 14. Out of scope for Phase 1
Posting anything to FinCon (finished goods, sales orders, stock adjustments); writing to the reference
system; VAT invoices; truck features; password reset/change UI (users created by HQ via SQL with
`bin/hash_password.php`); multi-unit conversion (orders and recipes use the ingredient's own unit).
