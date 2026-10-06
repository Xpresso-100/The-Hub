# CODEBASE_MAP - reference ordering system (read-only) and what The Hub copies from it

Reference studied: `C:\dev\ordering-ref` (Xpresso franchisee ordering system + HQ Control Tower),
read-only, 2026-10-06. Nothing in it was edited. Line numbers are as at that date.

## 1. Stack and versions

| Item | Reference | Evidence |
|---|---|---|
| Language | Plain PHP, no framework, no Composer (no `composer.json` / `package.json` in root) | root listing |
| PHP version | PHP 8.3 most cited in docs (8.1 also mentioned) - live minor version `[UNVERIFIED]` | grep of `*.md` |
| DB | MySQL/MariaDB via `mysqli` prepared statements; DB name `xpresso_clients` | `submit_order.php:13`, `DEPLOY_RUNBOOK.md` s3 |
| DB server version | MariaDB 11.4.5 / 10.2 and MySQL 5.7 each mentioned once - `[UNVERIFIED]` | grep of `*.md` |
| Frontend | Static HTML pages + vanilla JS (`client-api.js`, `ct-api.js`, `products-data.js`) calling flat PHP endpoints with `fetch` | root listing |
| Config | Gitignored `config.php` of `define()` constants (DB_*, ADMIN_EMAIL, FINCON_*), hand-edited on the server | `.gitignore:6,99-104`, `FINCON_TUNNEL_RUNBOOK.md:131-157` |
| Tests | Plain PHP CLI runners (`tests/<suite>/run.php`) that execute the real endpoint file with stubbed DB / FinCon, plus Node `*.test.js` for JS | `tests/g67/run.php:1-40`, `tests/ct/*.test.js` |
| Routing | One flat PHP file per endpoint in the web root (`submit_order.php`, `get_stock.php`, ...), `?action=` switch inside some | root listing, `admin_api.php:24-26` |

**Local machine (pipeline host), checked 2026-10-06:** PHP 8.3.32 CLI with `pdo_mysql`, `pdo_sqlite`,
`mysqli`, `curl`, `openssl`, `mbstring`; Node 24; no MySQL server; no Composer.

## 2. Login and roles

Two separate identity systems:

1. **Franchisee (client) login** - `admin_api.php` `action=client_login` (`admin_api.php:26-85`).
   - Requires **username + email + password**; `password_verify()` against a bcrypt hash in `clients.password` (`:37-46`).
   - On success sets `$_SESSION['client_id']` - "authoritative identity for all client endpoints" (`:48`).
   - Session cookie: 8 h lifetime, `secure`, `httponly`, `SameSite=Strict` (`require_client_auth.php:6-16`).
   - Guard: `require_client_id()` returns the session id or 401 JSON (`require_client_auth.php:23-30`). "Identity NEVER comes from the request body."
   - `forceChangePassword` flag returned when `password_changed = 0` (`admin_api.php:76`).
   - Logout destroys session and expires the cookie (`admin_api.php:173-196`).
2. **Admin (HQ / regional) login** - `admin_auth.php` `action=login`.
   - Token = `bin2hex(random_bytes(32))`, stored in `admin_users.last_token` with `token_expiry` (`admin_auth.php:44-48`).
   - Sent by the browser as header **`X-Admin-Token`**; guards in `require_admin_auth.php`:
     - `require_admin_token()` - any valid unexpired token, else 401 (`:10-35`).
     - `require_hq_admin()` - token valid AND `admin_users.region_id IS NULL`, else 403 "HQ access required." (`:43-73`).
     - `require_admin_context()` - returns `{id, region_id}` so endpoints scope by region server-side (`:77-103`).
   - Capability flags read fresh per request: `admin_users.can_manage_products` (`admin_region_prices_api.php` header), `admin_users.is_ops_only` (`get_stock.php:132`).
   - **Roles = HQ super-admin (`region_id NULL`) vs regional admin (`region_id` set) vs franchisee client.** There is no "location type" column today; a "location" is a `shops` row with a `region_id` (`admin_api.php:51`).
3. Error shape everywhere: `{"success": false, "message": "..."}` with 401/403/500; many validation failures return HTTP 200 with `success:false` (`submit_order.php:23-26`).

**The Hub copies:** bcrypt + username/email/password login, 8 h lifetime, server-side identity only, the
token-in-custom-header pattern (`X-Auth-Token`, the admin pattern) for every user, and server-side scoping.

## 3. How a franchisee order reaches the warehouse today

`submit_order.php` (whole file):
1. JSON body with `clientId`, `shopId`, `cart[]` (`id`, `name`, `qty`, `price`, `unit`), `totals`, optional `deliveryDate` (`:21-33`).
   Note: `clientId` and every `price` are taken from the **request body** (`:28`, `:89-94`) - a known weakness The Hub must not copy.
2. Delivery-date cutoff: 10:00 Africa/Johannesburg, Mon-Fri working days (`:35-64`).
3. `INSERT INTO orders (... status 'pending' ...)` then one `INSERT INTO order_items` per line (`:66-97`), in the shared MySQL DB.
4. PHP `mail()` of an HTML order confirmation to the client **and to `ADMIN_EMAIL`** (`:108-201`).
5. HQ / warehouse see it in the Control Tower (`xpresso_hq_control_tower_pc.html` via `get_all_orders.php`, region-scoped by `require_admin_context`, `get_all_orders.php:21-44`) and move it through
   `orders.status ENUM('pending','in_process','out_for_delivery','delivered')` (`create_orders_table.sql:70`) via `update_order_status.php`.
6. Prices include 15% VAT on the email (`submit_order.php:183-185`); orders are franchise sales.

So the reference supports two delivery channels for an order: **(a) a row in its own MySQL `orders` table**, and **(b) an email to `ADMIN_EMAIL`**.

## 4. How FinCon stock is read (Cloudflare Tunnel) and where auth is checked

```
xneelo PHP --HTTPS + CF-Access headers + HTTP Basic--> Cloudflare edge (Access check) --> cloudflared on FinCon box --> FinconRESTServer localhost:4090
```
(`FINCON_TUNNEL_RUNBOOK.md:8-11`)

- **Client:** `fincon_client.php` - DataSnap REST at `{FINCON_BASE_URL}/datasnap/rest/FinconAPI/"<Function>"/<params>/` (method name quoted literally, trailing slash) (`fincon_client.php:68-74`).
- **Auth layers, all on every call:**
  1. Cloudflare Access service token: headers `CF-Access-Client-Id` / `CF-Access-Client-Secret` (`:96-100`). Rejected -> HTTP 403 -> `FINCON_AUTH_FAILED` (`:168-170`).
  2. FinCon's own web server: HTTP Basic `FINCON_SRV_USER:FINCON_SRV_PASS` (`:92-95`). Rejected -> 401 (`:175-177`).
  3. FinCon session: `POST .../"Login"/` with JSON `{"_parameters":[FINCON_DATA_ID, FINCON_USER, FINCON_PASS, false]}` -> `{"result":[{"Connected":true,"ConnectID":"..."}]}` (`:219-241`). `GET .../"Logout"/<ConnectID>/` releases it (`:261-267`).
- **Stock read calls** (both read-only): `POST .../"GetStockQuantitiesForOpt2"/<ConnectID>/0/` body `{"ItemList":[{"ItemNo":"..."}]}` (batches of 100), fallback `GET .../"GetStockQuantities"/<ConnectID>/""/zzzzzzzz/0/false/false/<recNo>/<batch>/` (`fincon_stock_sync.php:85-88,384-423`).
  Response `result[0].Stock[]` items `{ItemNo, StockLoc:[{LocNo:"00", InStock, SalesOrders, PurchaseOrders}]}` (`fincon_stock_sync.php:480-490`). One FinCon location exists (`LocNo "0"` -> `"00"`).
- **Failure taxonomy:** `OK`, `FINCON_UNREACHABLE`, `FINCON_AUTH_FAILED`, `FINCON_MAINTENANCE` (15:25-15:30 SAST daily window), `FINCON_ERROR` (`fincon_client.php:20-27,41-52`). Timeouts 5 s connect / 15 s total (`:90-91`).
- **Where the data lands:** a cron (`fincon_stock_sync.php`, CLI or `?token=FINCON_SYNC_TOKEN` web call, `:50-57`) pulls nightly and upserts MySQL `stock_levels` (`:456-458`). Nothing is written to FinCon.
- **Who can read it:** `get_stock.php` is admin-token gated (`get_stock.php:112-113`) and derives `in_stock / low_stock / out_of_stock` from `stock_levels`: qty `<= 0` out, `<= 10` low, else in (`:38-39`), stale after 48 h (`:52`), and **every uncertainty fails open to `in_stock`** (`:23-28`). Franchisees see only the status map via `get_stock_public.php` (no quantities, no prices).
- SKU <-> FinCon mapping: `products.fincon_item_no` (`G11_Phase1_products_table.sql`).

## 5. Where cost and franchise prices come from

- **Franchise price:** `products.price DECIMAL(10,2)` (global) served by `get_products.php`, overridden per region by `product_region_prices` (`admin_region_prices_api.php` header). Static fallbacks in `products-data.js`.
- **Cost price:** **not stored anywhere in the reference DB.** The G28 pricing audit took "warehouse COST EXCL" from an offline `WAREHOUSE STOCK LIST.xlsx` (`tasks/G28_PART3_PRICING_AUDIT.md:1-25`) and notes franchise prices were being moved to cost "at-cost model", gated on HQ confirmation (`G28_Part3_price_corrections.sql:6-16`).
- The catalogue already has a **"Hub Ingredients" category, SKUs `HUB001`...** (`G11_Phase1_products_table.sql`), i.e. HQ already stocks the hub's ingredients.
- FinCon is not read for cost by the reference. Whether FinCon exposes a cost field is `[UNVERIFIED]`.

**The Hub consequence:** The Hub keeps its own ingredient catalogue with a `cost` column, loaded by HQ
(admin endpoint or CSV import). The franchise price is never stored or served by The Hub.

## 6. Dev command

The reference has **no local dev server, build step or package manager**; changes are tested by stub
harnesses (`php tests/<suite>/run.php`) and then on the live host. The Hub adds one:
`php bin/migrate.php && php bin/seed.php --fixtures && php -S 127.0.0.1:8080 -t public public/index.php`
with `DB_DRIVER=sqlite` (see SPEC.md s2).

## 7. How the reference is deployed

`DEPLOY_RUNBOOK.md` (whole file) and `_DEPLOY_*` bundle folders:
- Host: **xneelo shared hosting** (Apache + PHP; cPanel-style control panel). Files live in `public_html/orders/`.
- A human (sole deployer) uploads files with **xneelo File Manager**: navigate manually, **delete the old file first** (upload-over creates `name(1).php` and leaves the old one live), upload, check exact name and byte size (`DEPLOY_RUNBOOK.md` s1).
- **OPcache flush after every PHP deploy**: PHP Config -> switch PHP version -> save -> switch back -> save.
- SQL via **phpMyAdmin SQL tab, one statement at a time** (multi-statement submissions fail with #1109), `USE xpresso_clients;` prefix, back up tables first (s3).
- Secrets: `config.php` edited by hand on the server, never committed.
- Staging: deploy bundles are assembled in `_DEPLOY_<gate>/` folders with deploy notes (`G55_DEPLOY_NOTES.md` etc.). No CI, no Docker, no SSH (cron runs by web `?token=`).

## 8. Decisions this map drives (details in SPEC.md, logged in DECISIONS-LOG.md)

1. Stack: plain PHP 8.3, no framework, no Composer; PDO (`pdo_mysql` live, `pdo_sqlite` for tests/dev).
2. Orders reach HQ by: own `orders` table in The Hub's DB + email notification to HQ (same `mail()` channel as `submit_order.php:201`) + HQ admin view in The Hub. **No write into the reference DB.**
3. FinCon: read-only interface; mock for tests; HTTP adapter that speaks exactly the reference's tunnel protocol with an allowlist of read functions.
4. Deploy: same xneelo upload method (G5).
