# progress.md - The Hub pipeline

## 2026-10-06 - G0 DISCOVER (done)
What I did:
- Studied the reference ordering system (C:\dev\ordering-ref) read-only: stack, login/roles, order flow, FinCon tunnel read path and auth layers, price sources, dev and deploy method.
- Wrote CODEBASE_MAP.md (findings with file:line evidence).
- Wrote SPEC.md: stack, config keys, commands, test-harness contract, file ownership per module, data model for all modules, fixtures and SAMPLE data, hand-calculated values, public HTTP API for core/M1/M2/M3, FinCon read-only interface (mock, HTTP adapter, fake server), integration method, acceptance checks A1-A7, E2E flow.
- Wrote feature_list.json (31 features: core C-01..C-10, M1-01..M1-10, M2-01..M2-06, M3-01..M3-05), all passes=false.
- Started DECISIONS-LOG.md with every assumption so far.
- No application code written.

What is next:
- G1: scaffold the core in SPEC.md s2.4 (frozen afterwards), schema for all modules (SQLite + MySQL), seeds, auth, FinCon interface + mock + HTTP adapter + fake server, mailer, test runner, smoke test, black-box acceptance tests A1-A7 (SPEC s12), docs/CORE_API.md, and .pipeline/test-cmd.txt = `php tests/run.php`.
- Then G2 builds M1-ordering, M2-recipes, M3-reports in parallel, each only inside its own file list.

## 2026-10-06 - G1 SCAFFOLD + ACCEPTANCE TESTS (done)
What I did:
- Core (SPEC s2.4, now FROZEN): public/index.php front controller + .htaccess files, src/bootstrap.php, Config, Db (PDO mysql|sqlite), Money (s3.1 rounding), Clock, Http/{Request,Response,Router,HttpError}, Auth (login/logout/me, X-Auth-Token, sha256-stored tokens, role = location type), Fincon (read-only interface, mock, HTTP adapter with the reference tunnel protocol and a function allowlist, factory refusing mock in production), Mail (log + PHP mail() transports, MailGuard), public login/landing pages, assets/app.js + app.css.
- db/schema.mysql.sql + db/schema.sqlite.sql for ALL modules (s4), db/seed_samples.sql (3 SAMPLE recipes on 6 SAMPLE ingredients), bin/migrate.php, bin/seed.php (--samples/--fixtures, fixtures refused in production), bin/hash_password.php.
- Tests: tests/run.php (one process per file, "RESULT: p passed, f failed"), tests/lib/harness.php (fresh app per test, API client, fake FinCon, assertions), tests/support/fake_fincon.php.
- Smoke (tests/smoke, 18 tests): app starts, pages served, fixture logins, 404/405, auth contract, Money, FinCon adapters incl. failure mapping, mailer, CLI. All pass: RESULT 18 passed, 0 failed.
- Acceptance (tests/acceptance, 7 files, 19 tests, black-box over HTTP): A1-A7 of SPEC s12 plus s11 HQ notification, FinCon-down fail-open, location_id-ignored scope checks. All fail now with 404 (modules not built), as expected.
- Validated the frozen tests: an independent throwaway implementation of M1-M3 (built by a separate agent in C:\dev\hub-g1-proto, outside the repo, never committed) passes the full suite: RESULT 37 passed, 0 failed. Four injected defects (hub reads another location's order by id, franchise lists recipes, batch line cost off by a cent, hub order list wrong scope) each turned the matching acceptance test red.
- docs/CORE_API.md (exact PHP API modules call), README.md, .env.example (placeholders only), .gitignore (.env, config.local.php, var/, vendor/, node_modules/, build/, dist/, .run/), .pipeline/test-cmd.txt = `php tests/run.php`.
- DECISIONS-LOG.md: 10 g1 lines.

What is next:
- G2: build M1-ordering, M2-recipes, M3-reports in parallel, only inside the SPEC s2.4 file lists, against docs/CORE_API.md. Module unit tests go in tests/unit/<Module>/. Run one file with `php tests/run.php <substring>`.
- Human housekeeping: the scratch folders C:\dev\hub-g1-proto and C:\dev\hub-g1-check (outside the repo) can be deleted.

## 2026-10-06 - G2 BUILD M1-ordering (done, awaiting pipeline test run)
What I did (only files in the SPEC s2.4 M1 list; no shared file changed, no LANE BREACH):
- M1-01 routes.php + hub login module test. M1-02/03 IngredientController (GET /api/ingredients, PUT /api/admin/ingredients/{sku}).
- M1-04 StockService/StockController: GET /api/stock through FinconStockReader only, stock_cache with TTL, stale flag (failure or > 48 h), fail-open to in_stock, no cost/price keys.
- M1-05..08 OrderService/OrderController/OrderNotifier: POST /api/orders at cost (server-side cost_cents, client prices ignored, all s7.4 422 cases, SAMPLE refused in production), HQ notification + order_notifications outbox (failure never loses the order), location-scoped GET /api/orders and /{id}, PATCH status (forward-only, compare-and-set, delivered credits the hub ledger exactly once).
- M1-09 public/ordering.html + assets/ordering.js (franchise: stock only; hub: stock, order form, history; hq: all orders + status control; all values escaped).
- M1-10 bin/import_ingredients.php (all-or-nothing, line-numbered errors).
- tests/unit/Ordering: 8 files, 30 tests, all pass.
- Full `php tests/run.php` in this worktree: RESULT 53 passed, 12 failed. All 12 failures are A1(batch)/A2/A4/A5/A6 tests stopping at M2/M3 routes (/api/recipes, /api/inventory, /api/batches, /api/reports) that are built in the other worktrees. M1's acceptance checks pass: A1 orders, A3 (3/3), A7 (3/3).
- Not done: a real-browser check of ordering.html (browser tool permission not granted in this headless run); the page is covered by an HTTP-served + escaping-lint unit test only.
- 5 m1 lines added to DECISIONS-LOG.md.

What is next:
- Pipeline: merge M1/M2/M3 worktrees and run the full suite; A2 and A6 need all three modules.
- G3 e2e may drive ordering.html in a headless browser.
## 2026-10-06 - G2 BUILD M2-recipes (done, pending pipeline grading)
What I did:
- M2-01..M2-06 implemented, one commit each (M2-03 + M2-04 share one commit because they share BatchService), only inside the SPEC s2.4 M2 file list:
  src/Modules/Recipes/{routes,RecipeController,RecipeService,BatchController,BatchService,InventoryController,InventoryService}.php,
  public/recipes.html, public/assets/recipes.js, tests/unit/Recipes/*_test.php (5 files, 15 tests).
- Recipes: list/detail with cost at current ingredient cost; create/edit (replace lines, deactivate), 409 duplicate finished_sku, 422 field errors.
- Batches: hub-only POST, one transaction (snapshot lines, batches + batch_lines, -qty ledger rows reason batch), cost_per_item = round_half_up(total, qty), negative on-hand allowed and warned; GET scoped (hub own else 404, hq any).
- Inventory: ledger sum per sku for every active ingredient; adjustments (reason adjustment, note = reason text); hub location_id ignored, hq must name a hub location.
- UI: recipe list/editor, record batch with cost result and warnings, inventory + adjustment form; everything escaped (unit test scans recipes.js).
- Local run of `php tests/run.php` in this worktree: RESULT 36 passed, 16 failed. Passing: smoke 18/18, M2 unit 15/15, A4 3/3. All 16 failures are 404s on M1/M3 routes (/api/orders, /api/ingredients, /api/stock, /api/reports/*, /api/admin/*), which are built in the other worktrees. No M2 test fails.
- No LANE BREACH. No shared/frozen file or tests/acceptance edited. Nothing touches FinCon.
- UI not driven in a browser: the Playwright tool permission was not granted in this session; covered by HTTP-level tests only.

What is next:
- Pipeline merges M1 + M2 + M3 and runs the full suite; A1, A2, A5, A6 exercise M2 routes together with M1/M3.
## 2026-10-06 - G2 BUILD M3-reports (done)
What I did:
- M3-01..M3-04 API (src/Modules/Reports/{routes,ReportController,ReportService,AdminListController}.php): GET /api/reports/finished-goods (JSON + format=csv), GET /api/admin/recipes, GET /api/admin/batches. Read-only, built from batch snapshots, hub scoped to own location (location_id ignored), hq all or ?location_id=, franchise 403, 401 before 403 before 422.
- M3-05 UI: public/reports.html + public/assets/reports.js (date-range report with totals and CSV download, admin recipe list, admin batch list with filters; every value escaped).
- tests/unit/Reports/reports_test.php (7 tests): inserts the s5.3 batch rows straight into the test DB (M2 is in another worktree) and checks totals 46/245.04/3 and 51/358.34/4, date range, 422s, CSV rows incl. TOTAL and formula guard, roles, admin lists.
- 5 g2-M3 lines in DECISIONS-LOG.md.
Evidence:
- In this worktree: `php tests/run.php` -> RESULT: 25 passed, 19 failed. Smoke 18/18 and M3 unit 7/7 pass; all 19 acceptance failures are 404s on M1/M2 routes not in this lane.
- Scratch copy outside the repo (C:\dev\hub-m3-scratch-187210) = this module + the G1 throwaway M1/M2 from C:\dev\hub-g1-proto: `php tests/run.php acceptance` -> RESULT: 19 passed, 0 failed (A1, A2, A5, A6 exercise M3 routes).
- No LANE BREACH: no shared file needed changing.

What is next:
- Merge with the M1-ordering and M2-recipes branches; the pipeline runs the full suite.
- Human housekeeping: delete C:\dev\hub-m3-scratch-187210 (the delete guard blocks agents from removing it).
## 2026-10-06 - G3 E2E (done)
What I did:
- tests/e2e/hub_smoke_flow_test.php (2 tests, black-box over HTTP, harness TestApp on a free port):
  1. SPEC s13 flow as hub.user: health + login.html served -> POST /api/login (role hub, location type hub) -> /api/me -> GET /api/stock (fincon_status OK, s7.1 statuses, no cost/price keys) -> GET /api/ingredients -> POST /api/orders SMP001 x10 + SMP004 x2.5 with fake client prices (unit_price = cost_price, 125.00 / 300.00 / total 425.00, price_basis cost, HQ notification sent) -> order in history + detail -> POST /api/batches muffin x24 (134.16 / 5.59, inventory deltas exactly -1.440..-0.240, SMP006 unchanged) -> GET /api/reports/finished-goods shows the batch, items and totals 24 / 134.16 / 1, CSV header + TOTAL row -> FinCon mock log all kind=read -> logout, token then 401.
  2. Every page in the flow (login, index, ordering, recipes, reports) is served as text/html and every asset it references returns 200.
- No product bugs found: the e2e passed on the first run. No product code changed. tests/acceptance untouched.
- .pipeline/test-cmd.txt unchanged (`php tests/run.php` already runs tests/e2e; see DECISIONS-LOG g3-e2e).
- Evidence: `php tests/run.php e2e` -> RESULT: 2 passed, 0 failed; full `php tests/run.php` -> RESULT: 89 passed, 0 failed, exit 0.
- Not done: real-browser drive of the UI pages (Playwright tool permission not granted in this headless run).

What is next:
- G4 verifier grades the build against SPEC.md.
- Human: a manual browser pass on login -> ordering -> recipes -> reports. Scratch files under var/ (gitignored: var/e2e-serve.php, var/e2e-full-run.log, var/e2e-serve-url.txt, var/test/) can be deleted.
## 2026-10-06 - G4 VERIFY (done)
What I did:
- Ran php tests/run.php: RESULT: 89 passed, 0 failed. Checked A1-A7, locked rules, secrets grep, dependency audit (none). Wrote .run/VERDICT.md: SHIP.
What is next:
- Human: manual browser pass on the UI.
