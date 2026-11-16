# The Hub: build spec, live operations (warehouse queue, ingredient screen, account creation)

Version 1.1 · 16 Nov 2026 07:50 SAST · Base commit: the commit on `feat/hub-live-ops` that adds this file (branched from `main` at `1851d9d`) · Data as at 16 Nov 2026 (repo read, live health check)
**DRAFT, not verified. It becomes final only when gate G5 below gives PASS.**
Business: Xpresso Cafe, The Hub central production kitchen. Not The Hub Distributors.

**ASK (read first):** this run is UNATTENDED. Damian is not watching and will not answer. Build on the branch `feat/hub-live-ops`, push it, write `docs/BUILD-REPORT-2026-11-16.md`, then STOP. Do not merge to `main`. Do not deploy. Do not ask questions: take the stated assumption, write it in the lane notes, keep going. If a step needs a human, write it in the report under WAITING FOR DAMIAN and continue with everything that does not depend on it.

**Changes in v1.1 (16 Nov, Damian):** recipes, batches and reports are not needed. The Hub only needs stock. Section 8b adds a Hub stock page and section 9 hides recipes and reports from every menu. Time box is now 2 hours (section 12). The task board is section 15.

---

## 1. Goal and "done"

When the Hub places an order, head office (`hq` users, which includes the warehouse) sees what must be done, in one screen, without opening orders one by one. Head office can also load and maintain ingredient costs, and create user accounts (Nicole first), without SQL.

**Done means all of these, each proven by a command or test named in section 10:**
1. `GET /api/warehouse/queue` returns the open orders and a pick list, for `hq` only.
2. A page `warehouse.html` shows the queue, the pick list, and moves an order forward one step at a time. It refreshes itself.
3. A page `ingredients.html` lists, adds, edits and deactivates ingredients through the existing API.
4. `POST /api/admin/users` creates a login (`hq` or `hub` locations only; franchise locations are refused by the server). A page `accounts.html` uses it.
5. `php tests/run.php` ends `RESULT: <n> passed, 0 failed` with `n` at least 89 plus every new test.
6. A page `stock.html` shows the Hub's stock on hand and records a stock count, and no menu or home tile links to recipes or reports (section 8b, section 9).
7. No file outside the allowed list in section 4 changed.

## 2. Facts at the base commit (read from the repo on 16 Nov 2026)

- Live health check `https://xpressocafe.co.za/hub/public/api/health` returned `{"success":true,"status":"ok"}` on 10 Nov and 16 Nov 2026.
- Working tree clean except two untracked zips in `deploy/upload/`. `main` equals `origin/main`.
- HQ can already: list orders (`GET /api/orders`, status filter), open one (`GET /api/orders/{id}`), and move status (`PATCH /api/orders/{id}/status`) in `OrderService`. Status moves forward only; `cancelled` only from `pending` or `in_process`; `delivered` credits the Hub's stock ledger once and cannot be undone.
- The order list does NOT return lines, requested delivery date or notes, and the default view is all orders newest first. That is the gap.
- The only way to set an ingredient cost is `PUT /api/admin/ingredients/{sku}` (hq). No screen calls it.
- `Money::parseCents` accepts only a JSON **number** (not a string), at most 2 decimals, at least 0.
- In production (`APP_ENV=production`, the default) an order line for a sample ingredient is refused (`OrderService::validate`). So the Hub cannot order until real ingredients exist.
- Routes are auto-loaded from `src/Modules/*/routes.php` (`public/index.php`). Tests are auto-discovered from `tests/{smoke,unit,acceptance,e2e,security}/*_test.php`.
- `users` has no `created_by` column and there is no change-log table. Adding either needs SQL, which is out of scope.
- [Unverified] the live database contents (users, real ingredients, costs), the live PHP version, whether email sending works on the live host, whether PHP CLI exists on this laptop. Preflight checks the last one.

## 3. Scope

**IN:** lanes A, B, C, D and the integration node (sections 6 to 9).

**Recipes are HIDDEN, not deleted.** The stock-on-hand endpoints (`GET /api/inventory`, `POST /api/inventory/adjustments`) live in `src/Modules/Recipes/`, and frozen acceptance tests A4 and A5 plus `tests/e2e/hub_smoke_flow_test.php` exercise recipes, batches and the recipe and report pages. Deleting any of it breaks frozen tests. So: keep every recipe, batch and report file and endpoint; remove them only from navigation. Removing the code is a later change with its own spec.

**OUT. Do not build. If you believe one is needed, write `NEED-OUT-OF-SCOPE: <what> - <why>` in your lane notes and continue:**
- Any database schema change, SQL script, or config change. This build is files only.
- A per-user "can manage ingredients" flag, an ingredient change log, the "not tracked in FinCon" status (it changes frozen test A7).
- Single sign-on or any change to the ordering system or its database.
- A password change or reset screen.
- Email or notification changes.
- Recipe, batch or report changes.
- Any change to an existing endpoint's request or response shape.
- Any new access for `franchise` users. They get 403 on everything new.

## 4. Frozen files and ownership

**Never edit** (gate G2 diffs these against `main` and must show nothing):
`SPEC.md`, `SPEC-locked-decisions.md`, everything under `tests/acceptance/`, every existing test file, `db/*`, `deploy/sql/*`, `src/Config.php`, `src/Auth/*`, `src/Money.php`, `src/Http/*`, `src/Modules/Ordering/*`, `src/Modules/Recipes/*`, `src/Modules/Reports/*`, `src/Fincon/*`, `bin/*`.

**Lane-owned (only that lane writes them):**

| Lane | Owns |
|---|---|
| A warehouse queue | `src/Modules/Warehouse/*`, `public/warehouse.html`, `public/assets/warehouse.js`, `public/assets/warehouse.css`, `tests/unit/Warehouse/*`, `docs/lane-notes/lane-a.md` |
| B ingredient screen | `public/ingredients.html`, `public/assets/ingredients.js`, `tests/unit/IngredientScreen/*`, `docs/lane-notes/lane-b.md` |
| C accounts | `src/Modules/Accounts/*`, `public/accounts.html`, `public/assets/accounts.js`, `tests/unit/Accounts/*`, `docs/lane-notes/lane-c.md` |
| D Hub stock page | `public/stock.html`, `public/assets/stock.js`, `tests/unit/StockScreen/*`, `docs/lane-notes/lane-d.md` |

**Coordinator-only (shared, edited once, in the integration node):** `public/assets/app.js` (the `LINKS` list), `public/index.html` (the `TILES` list), `DECISIONS-LOG.md` (append lines), `CLAUDE.md`, this spec, `docs/BUILD-REPORT-2026-11-16.md`.

**Never edit (added in v1.1):** `public/recipes.html`, `public/reports.html`, `public/assets/recipes.js`, `public/assets/reports.js`. They stay on disk and stay served; they are only unlinked.

## 5. How to work: harness, context, loop, graph (applied to this build)

**Harness.** The environment that keeps the model honest: this spec, `CLAUDE.md`, the test command, git worktrees, the frozen-file diff, and verifier agents. "Done" is decided by `php tests/run.php` and the gates, never by an agent saying so. Hooks and rules in `~/.claude/CLAUDE.md` and `~/.claude/rules/` govern git and deploy; do not restate or contradict them.

**Context.** Each lane gets only its own section of this spec plus the files it names. Write findings and assumptions to the lane notes file, not into chat. Compact at 50% context fill with a directive that names the lane, the current test and the next step. Long files (this spec, `DECISIONS-LOG.md`) are append-and-amend only.

**Loop (per lane).** Write the failing test first, then the code, run `php tests/run.php <lane folder>`, fix, repeat.
- Stop conditions: green; or 5 loop rounds; or the same failure text twice in a row (two-strike: stop, write the failure into lane notes, report to the Coordinator; do not keep patching); or the lane wall-clock budget in section 12.
- No-progress check: if a round changes no test result, count it as a strike.
- Completion check before a lane may report done: its tests pass AND `git diff --name-only` for the lane touches only owned files.

**Graph.** Decision, as the graph-engineering gate requires:

```
[GRAPH DECISION: GRAPH-LITE, user-directed, for clock time only]
GATE:   Q1 yes (four lanes, disjoint files)  Q2 NO (about 900 lines fits one context)
        Q3 yes (shape known)  Q4 yes (worktrees + owned files)
PATTERN: isolated parallel lanes + clean-context verification on each edge
WHY:    one line has to finish inside 2 hours unattended; no context-width reason exists
SHAPE:  4 builders + 1 auditor + 1 coordinator = 6 agents, at most 6 concurrent, size small
FALLBACK: if two lanes collide on a file, or any lane takes two strikes, collapse to a single line
          (Coordinator builds the remaining lanes serially)
VERIFY: auditor reads the lane diff and this spec only, never the builder's reasoning
FROZEN: section 4 list
```

**Agents.** Run `/agents` first and use the installed Bench v5 roster by ROLE (Coordinator, Builder, Auditor, Researcher). Do not invent agent names. Bring in an installed specialist (security, UI, test) only for the matching gate: security review for lane C before integration, UI review for lanes A and B. Agents step in when their gate arrives; do not run the whole roster at once.

## 6. Lane A: warehouse queue

**Files and endpoint.** `src/Modules/Warehouse/routes.php` returns `[['GET', '/api/warehouse/queue', [QueueController::class, 'show']]]`. `QueueController::show` calls `Auth::requireRole($req, 'hq')` (401 not logged in, 403 for hub and franchise). Logic in `QueueService`. Read-only: no INSERT, UPDATE or DELETE anywhere in this lane.

**Selection.** Orders with status in `pending`, `in_process`, `out_for_delivery`. Order by: requested delivery date ascending with NULL last (`ORDER BY (o.requested_delivery_date IS NULL), o.requested_delivery_date, o.created_at, o.id`), so the earliest need and then the oldest order comes first. Fetch all lines for those orders in ONE query (`WHERE order_id IN (...)`), not one query per order. Latest notification status per order from `order_notifications`.

**Response (no money field anywhere):**
```json
{
  "success": true,
  "generated_at": "<ISO>",
  "counts": {"pending": 0, "in_process": 0, "out_for_delivery": 0},
  "orders": [{
    "id": 12, "location_name": "The Hub", "status": "pending",
    "created_at": "<ISO>", "created_by": "<username>",
    "requested_delivery_date": "2026-11-17", "notes": null,
    "hq_notification": "sent|failed|none",
    "lines": [{"sku": "HUB001", "name": "...", "unit": "kg", "qty": 10.0}]
  }],
  "picklist": [{"sku": "HUB001", "name": "...", "unit": "kg", "qty": 12.5, "order_ids": [12, 14]}]
}
```
- `counts` always has all three keys.
- `picklist` is built from orders in `pending` and `in_process` only (what is still to be pulled). Sum `qty_milli` as integers in PHP, then `Money::toQty`. Sort by `sku`. Name and unit come from the snapshot on the newest order's line.
- Never include `unit_cost_cents`, `line_total_cents`, `total_cents`, or any key matching `/cost|price|total/i`.

**Page `warehouse.html` + `assets/warehouse.js` + `assets/warehouse.css`.** Same look and helpers as `ordering.html` (`assets/app.css`, `assets/app.js`, `Hub.api`, `Hub.requireRole('hq')`, `Hub.renderNav`). Required element ids: `hub-nav`, `queue-counts`, `queue-meta`, `picklist-body`, `queue-orders`, `queue-message`, `refresh-btn`, `print-btn`.
- Three count badges: Waiting (pending), Being picked (in_process), Out for delivery.
- "To pull now" table from `picklist`.
- One card per order, ordered as the API returns: location, order number, placed time, who placed it, requested delivery date (or "none given"), notes, email status, the lines. Show the age as text ("waiting 3 h 10 min"). **No colour or SLA thresholds: the business has not set any.**
- One forward button per order: `pending` -> "Start picking" (`in_process`); `in_process` -> "Out for delivery"; `out_for_delivery` -> "Mark delivered". The delivered button asks `window.confirm`: "Mark order #N delivered? This adds the items to the Hub's stock and cannot be undone." Calls the existing `PATCH api/orders/{id}/status`. A 409 shows "Someone else changed this order. Refreshing." and reloads.
- Auto-refresh every 60 seconds; skip while the tab is hidden; refresh at once when it becomes visible; never two requests in flight. Show "Last updated hh:mm".
- `document.title` = `(<pending count>) Warehouse queue`.
- "Print pick list" calls `window.print()`; `warehouse.css` has an `@media print` rule that hides the nav and all buttons.
- Empty state: "Nothing waiting."
- API paths are relative (`'api/warehouse/queue'`), never `'/api/...'` (sub-folder deploy).
- Every API value placed into markup goes through `Hub.escapeHtml` (the existing lint test pattern is in `tests/unit/Ordering/ordering_ui_test.php`; copy it for `warehouse.js`). No `eval`, no `document.write`.

**Tests (`tests/unit/Warehouse/`, new files only):**
1. 401 without a token; 403 for `hub.user` and `franchise.user`; 200 for `hq.admin`.
2. Only the three open statuses appear. Create orders in all five statuses (use the existing harness and `PATCH` to move them) and assert `delivered` and `cancelled` are absent.
3. Order of the list: dated before undated; earlier date first; same date oldest first.
4. Pick list sum is exact: orders of 10 and 2.5 of the same SKU sum to 12.5; an `out_for_delivery` order is excluded from the pick list but present in `orders`.
5. `counts` has three keys at zero when nothing is open.
6. No key in the JSON matches `/cost|price|total/i` (use `hub_keys_matching`).
7. The route performs no writes: row counts of `orders`, `order_lines`, `inventory_movements` are unchanged after the call.
8. UI: `warehouse.html` is served with the required ids; `warehouse.js` calls `'api/warehouse/queue'` and `/status`; relative URLs only; the escape lint passes; no `eval`/`document.write`.

## 7. Lane B: ingredient screen (front end only)

No PHP. Uses the existing API: `GET api/ingredients?include_inactive=1` (hq) and `PUT api/admin/ingredients/{sku}`.

**Page `ingredients.html` + `assets/ingredients.js`**, `Hub.requireRole('hq')`. Ids: `hub-nav`, `ing-body`, `ing-form`, `ing-sku`, `ing-name`, `ing-unit`, `ing-cost`, `ing-fincon`, `ing-active`, `ing-save`, `ing-message`, `ing-show-inactive`.
- Table: SKU, name, unit, cost (R, excl. VAT), FinCon item number, a `SAMPLE` badge for `is_sample`, active. Inactive rows greyed. A checkbox shows or hides inactive rows.
- Add or edit form. For an existing SKU the SKU field is read-only. Client checks mirror the server (name 1-150, unit 1-20, FinCon item at most 25 characters or empty). The server stays the source of truth: show its `errors` per field.
- **Cost is sent as a JSON number, never a string** (`Money::parseCents` rejects strings). Validate the text with `/^\d{1,9}(\.\d{1,2})?$/` first, then send `Number(text)`. Show costs with `Hub.money`.
- Deactivate and reactivate are a `PUT` with the full object and `active` flipped (the endpoint replaces all fields). A blank FinCon item sends `null`.
- When a cost changes, `window.confirm`: "Change <name> from R a to R b? New orders only. Existing orders and batches keep their old cost."
- A visible note: "Sample ingredients cannot be ordered in production. Load the real ingredients, then deactivate the samples."
- Relative API paths, `Hub.escapeHtml` on every value, no `eval`.

**Tests (`tests/unit/IngredientScreen/`, new files only):**
1. Page and script served; required ids present; calls `'api/ingredients'` with `include_inactive=1` and `'api/admin/ingredients/'`; relative URLs only; escape lint passes.
2. `ingredients.js` contains the `Number(` conversion and the two-decimal regex, and does not send `cost_price` as a quoted string (assert the source does not build `cost_price:` from an un-converted variable named like the text field; the lane picks the exact assertion).
3. Round trip over HTTP with `hq.admin`: PUT `cost_price` 12.5 then GET returns 12.5; PUT 0.1 then GET returns 0.1; a string `"12.50"` is refused with 422.
4. `hub.user` and `franchise.user` get 403 on the PUT.

## 8. Lane C: account creation

**Files.** `src/Modules/Accounts/routes.php`, `AccountController.php`, `AccountService.php`, plus the page and script.

**Endpoints (all `hq` only; 401 / 403 as elsewhere):**
- `GET /api/admin/locations` -> `{"locations":[{"id","name","type"}]}` for ACTIVE locations of type `hq` or `hub` only. Franchise locations are never listed.
- `GET /api/admin/users` -> `{"users":[{"id","username","email","location":{"id","name","type"},"active","created_at"}]}`, ordered by `id`. Never returns `password_hash`.
- `POST /api/admin/users` body `{"username","email","password","location_id"}` -> 201 `{"user":{...as above}}`. Validation (422 with per-field `errors`):
  - `username` 3-60 characters, `[A-Za-z0-9._-]` only; already taken -> 409 CONFLICT.
  - `email` valid, at most 190 characters.
  - `password` 12 to 72 BYTES (bcrypt ignores bytes past 72, so refuse longer).
  - `location_id` must exist, be active, and be of type `hq` or `hub`. **A franchise location is refused with 422 on `location_id` ("franchise locations cannot have Hub accounts"). This is the server enforcing the rule that used to be "INSTRUCTED".**
  - Hash with `password_hash($password, PASSWORD_BCRYPT)` exactly like `bin/hash_password.php`. Insert `users (location_id, username, email, password_hash, active=1, created_at)`.
  - Never return, log or store the plain password. Write one line `error_log('HUB-AUDIT account_created user_id=<new> by=<creator id>')` (ids only, no email, no password).
- `PATCH /api/admin/users/{id}` body `{"active": true|false}` -> 200 with the user. 404 if unknown. An `hq` user cannot deactivate their own account (409). Deactivating also deletes that user's rows in `auth_tokens`.

**Page `accounts.html` + `assets/accounts.js`**, `Hub.requireRole('hq')`. Ids: `hub-nav`, `acc-body`, `acc-form`, `acc-username`, `acc-email`, `acc-password`, `acc-location`, `acc-suggest`, `acc-save`, `acc-message`.
- List of accounts with a Deactivate / Reactivate button.
- Create form; the location dropdown comes from `GET api/admin/locations`.
- "Suggest a password" button fills a 16-character password from `crypto.getRandomValues`, shown in the field.
- After a successful create, show once: "Account created. Give Nicole these three things: username, email, password. She logs in with all three." Then clear the password field. The password is never put in `sessionStorage`, `localStorage`, the URL or the console.
- Relative API paths, `Hub.escapeHtml`, no `eval`.

**Tests (`tests/unit/Accounts/`, new files only):**
1. 401 / 403 / 200 matrix for every endpoint (hub and franchise get 403).
2. Create a hub user, then **log in as that user over HTTP** with username, email and password and reach `GET /api/me` with role `hub`. This proves the hash is valid.
3. Create an `hq` user; log in; role `hq`.
4. A franchise-type location id -> 422 on `location_id`; the location list never contains it; no user row was inserted.
5. Duplicate username -> 409; short password (11) -> 422; 73-byte password -> 422; bad email -> 422; bad username characters -> 422.
6. The list never contains a `password_hash` key or a `$2y$` value anywhere (`hub_keys_matching` plus a string scan).
7. Deactivate: the user's existing token gets 401 on the next call, a new login fails; self-deactivate -> 409; reactivate restores login.
8. UI: page and script served with the required ids; relative URLs; escape lint; the script contains no `localStorage` or `sessionStorage` write of the password field.

## 8b. Lane D: Hub stock page (front end only)

No PHP. Uses the existing endpoints in `src/Modules/Recipes/InventoryController.php`: `GET api/inventory` and `POST api/inventory/adjustments` (roles `hq` and `hub`). Read that controller and `InventoryService` first for the exact query parameters and request body; do not guess them. Copy the request shape the existing inventory form in `public/assets/recipes.js` already sends.

**Page `stock.html` + `assets/stock.js`**, `Hub.requireRole('hq','hub')` (if `requireRole` takes one role only, check `user.role` against both). Ids: `hub-nav`, `stock-body`, `stock-location`, `stock-meta`, `adj-form`, `adj-sku`, `adj-qty`, `adj-reason`, `adj-save`, `stock-message`.
- Table: SKU, name, unit, on hand. A negative on-hand value is shown in plain text with the note "more used than received". No colour thresholds.
- `hub` users see their own location. `hq` users get a location dropdown of hub locations (use the location list the existing inventory form uses).
- Stock count form: ingredient dropdown, signed quantity (a count of +12.5 or a correction of -2), reason (required, at most 200 characters, placeholder "opening balance, stock count"). After save, reload the table.
- Heading text: "Hub stock". Short help line: "On hand = deliveries from head office plus or minus stock counts."
- No cost or price shown anywhere on this page.
- Relative API paths, `Hub.escapeHtml` on every value, no `eval`, no `document.write`.

**Tests (`tests/unit/StockScreen/`, new files only):**
1. Page and script served; required ids present; calls `'api/inventory'` and `'api/inventory/adjustments'`; relative URLs only; escape lint passes (copy the pattern from `tests/unit/Ordering/ordering_ui_test.php`).
2. `stock.js` source contains no `cost`, `price` or `total` token.
3. Over HTTP as `hub.user`: an adjustment of +5 for one SKU then `GET api/inventory` shows on hand 5 for that SKU.
4. `franchise.user` gets 403 on both endpoints (existing behaviour; this guards it).

## 9. Integration node (Coordinator only, after all four lane reports are in)

1. Count lane reports: expected 4. If fewer, STOP and report the gap above everything else.
2. Merge lane branches into `feat/hub-live-ops` one at a time; run the lane's tests after each.
3. Edit `public/assets/app.js` `LINKS` to exactly this list, in this order:
   - `Home` index.html (hq, hub, franchise), unchanged
   - `Stock & ordering` ordering.html (hq, hub, franchise), unchanged
   - `Hub stock` stock.html (hq, hub)
   - `Warehouse queue` warehouse.html (hq)
   - `Ingredients` ingredients.html (hq)
   - `Accounts` accounts.html (hq)
   The `Recipes & batches` and `Reports` entries are REMOVED.
   Edit `public/index.html` `TILES` the same way: keep the two ordering tiles; add tiles for Hub stock (hq, hub), Warehouse queue, Ingredients, Accounts (hq); remove the Recipes & batches and Reports tiles. Tile text in plain words, for example Warehouse queue: "Every open Hub order and what to pull from the warehouse."
   Add a unit test `tests/unit/Navigation/nav_test.php` asserting `app.js` and `index.html` no longer contain `recipes.html` or `reports.html`, and do contain the four new pages.
4. Append one line per decision or assumption from the lane notes to `DECISIONS-LOG.md`, in the existing format `- 2026-11-16 | <lane> | decision | reason`.
5. `CLAUDE.md` is already updated in this branch; do not edit it again.
6. Write `docs/BUILD-REPORT-2026-11-16.md` in the section 14 format, commit, push the branch.

## 10. Verification gates (every gate is a command with a visible result)

| Gate | Command or check | Pass means |
|---|---|---|
| G0 preflight | `php -v`; `git status -sb`; `git rev-parse main` | PHP present; `main` is `1851d9d`. If PHP is missing, STOP and report. Do not install anything. |
| G1 per lane | `php tests/run.php <lane test folder>` | `0 failed` for that folder, plus the lane's file-ownership diff is clean |
| G2 frozen files | `git diff --name-only main -- SPEC.md SPEC-locked-decisions.md tests/acceptance db src/Config.php src/Auth src/Money.php src/Http src/Modules/Ordering src/Modules/Recipes src/Modules/Reports src/Fincon bin deploy/sql` and `git diff --diff-filter=DM --name-only main -- tests` | both outputs EMPTY |
| G3 full suite | `php tests/run.php` | last line `RESULT: <n> passed, 0 failed`, `n` >= 89 + new tests, and acceptance A1 to A7 all present and passing |
| G4 clean-context audit | Auditor agent, given only this spec and `git diff main...feat/hub-live-ops` | verdict PASS or PASS-WITH-FLAGS with every flag listed; any BLOCKED stops the build |
| G5 final | Coordinator reruns G2 and G3 on the final commit | PASS |

The Auditor checks specifically: every new route calls `Auth::requireRole` before any data access; no SQL string is built by concatenating request input; no route writes except `POST /api/admin/users`, `PATCH /api/admin/users/{id}` and the existing endpoints; no new file reads or logs a password; no new page lets `franchise` in; no money field in the queue; every user-supplied value in markup is escaped.

## 11. Human gates and ship (Damian only)

Claude Code commits and pushes the branch `feat/hub-live-ops` (and lane branches) to the private `Xpresso-100/The-Hub` repo as it goes. These are INSTRUCTED rules; whether a hook enforces them is [Unverified]:
- **Merge to `main`:** only after Damian says "merge". Present the G5 result, then wait.
- **Deploy over SSH:** only in an attended session, after Damian says "ship". First run the `save-ship` dry run and report back **counts only** (files new, changed, unchanged, protected). Never print protected file names. Expected change set: the new files in section 4 plus `public/assets/app.js` and `public/index.html`. Any other changed file means STOP and report.
- **No database step, no `config.local.php` change, no OPcache flush needed beyond the normal ship routine** [Inference: PHP files change, so follow the existing flush step in `deploy/DEPLOY.md` if the ship tool does not do it].

**After ship, Damian checks in a browser (about 10 minutes):**
1. `.../hub/public/api/health` shows `{"success":true,"status":"ok"}`.
2. Log in with your existing `hq` login: Warehouse queue shows "Nothing waiting". Ingredients and Accounts open.
3. Ingredients: confirm real ingredients exist with costs (the cost sheet is a data dependency; see section 13). If only samples exist, load the real list first.
4. Accounts: create Nicole as a `hub` login. Log in as her in a private window.
5. As Nicole, place a test order of 1 unit of one cheap ingredient. The queue (hq window) shows it within 60 seconds, the email arrives at head office, and the email status badge reads "sent".
6. Hub stock: as Nicole, open Hub stock and enter one opening count; it shows in the table.
7. In the queue press "Start picking", then cancel the order from `ordering.html` (cancel is allowed from `in_process`). **Do not press "Mark delivered" on a test order: it credits the live Hub stock ledger and cannot be undone.** The delivered path is proven by the automated tests.

## 12. Time box (120 minutes from start, unattended)

| Minute | Step | Who |
|---|---|---|
| 0-10 | G0 preflight; confirm branch `feat/hub-live-ops` is checked out and clean; create four worktrees, one per lane | Coordinator |
| 10-70 | Lanes A, B, C, D in parallel. Budget: A 55 min, C 50 min, B 35 min, D 30 min | 4 Builders |
| 70-80 | G1 per lane; security review of lane C; UI review of lanes A, B, D | Auditor + specialists |
| 80-95 | Integration node (section 9) | Coordinator |
| 95-110 | G2, G3, G4 (clean-context audit), fix BLOCKED items once | Coordinator + Auditor |
| 110-120 | G5, write the build report, push, STOP | Coordinator |

Builders that finish early: pick up the next unclaimed task from section 15 in another lane ONLY through the Coordinator, who reassigns the task and its files. Never two agents on one file.

**Cut order if time runs out (cut from the bottom, never the top):** keep A, then D, then C, then B. Inside a lane, cut in this order: print stylesheet; page-title count; self-refresh polling; suggest-password button; the `PATCH` deactivate endpoint and its button. A cut lane ships nothing and gets no menu link. Record every cut in the report.

## 13. Not in the code: what must exist for the Hub to run

- The real ingredient list with cost price (excl. VAT) per unit, from Deirdre or Jolandi. An earlier cost sheet may exist; its version, date and whether it was returned are [Unverified]. Without it, orders are refused (samples) or priced at R0.00.
- Head office logins for the warehouse staff who will use the queue. Who they are and whether they exist is [Unverified]; lane C lets Damian create them.
- Deirdre records delivered Hub orders and finished goods in FinCon by hand. The app never writes to FinCon (acceptance test A6, frozen).

## 14. Report back (Coordinator, one message)

1. Gate table G0 to G5 with the real command output line for each.
2. Lane report count: `RAN: 4/4 lanes returned` (or the gap, first).
3. The test line: `RESULT: <n> passed, 0 failed`.
4. Auditor verdict and every flag.
5. Branch name, last commit hash, push confirmation.
6. Cuts made, assumptions logged, anything marked `NEED-OUT-OF-SCOPE`.
7. WAITING FOR DAMIAN: the two human gates, "merge" then "ship", and anything else a human must do.
8. Percentage complete per task in section 15 (done criteria met divided by total) and the time each lane actually took.

Write all of this to `docs/BUILD-REPORT-2026-11-16.md`, commit it on the branch and push. That file is how Damian learns what happened; he is not watching the session.

## 15. Task board (bite-sized; each task ends with its own test passing)

| # | Lane | Task | Done when | Est. min |
|---|---|---|---|---|
| A1 | A | Write failing access tests (401/403/200) | test file exists, fails for the right reason | 5 |
| A2 | A | `routes.php` + `QueueController::show` with `requireRole('hq')` | A1 passes | 5 |
| A3 | A | `QueueService` open-order query with the section 6 ordering | tests 2, 3 pass | 10 |
| A4 | A | Lines in one query + latest email status | tests 6, 7 pass | 8 |
| A5 | A | Pick list sum and `counts` | tests 4, 5 pass | 7 |
| A6 | A | `warehouse.html` + `warehouse.js` list, counts, pick list | UI test 8 passes | 10 |
| A7 | A | Forward buttons, delivered confirm, 409 handling | manual read of code + UI test | 5 |
| A8 | A | Auto-refresh, title count, print css | UI test 8 still passes | 5 |
| B1 | B | `ingredients.html` table + show-inactive | UI test 1 passes | 10 |
| B2 | B | Add/edit form with number-only cost | tests 2, 3 pass | 15 |
| B3 | B | Deactivate/reactivate + cost-change confirm | test 4 passes | 10 |
| C1 | C | Failing access tests for all endpoints | fails for right reason | 5 |
| C2 | C | `GET admin/locations` (hq and hub only) | tests 1, 4 partly pass | 5 |
| C3 | C | `POST admin/users` with validation and bcrypt | tests 2, 3, 4, 5 pass | 15 |
| C4 | C | `GET admin/users` without hashes | test 6 passes | 5 |
| C5 | C | `PATCH` deactivate + token delete + self-guard | test 7 passes | 10 |
| C6 | C | `accounts.html` + `accounts.js` | test 8 passes | 10 |
| D1 | D | Read inventory controller and recipes.js request shape; write it into lane notes | notes written | 5 |
| D2 | D | `stock.html` + `stock.js` table and location picker | tests 1, 2 pass | 15 |
| D3 | D | Stock count form | tests 3, 4 pass | 10 |
| N1 | Coord | Menu and tiles per section 9 step 3 + nav test | nav test passes | 10 |
| N2 | Coord | Merge lanes, decisions log, report | G2, G3, G5 pass | 20 |
| V1 | Auditor | Clean-context audit of the full diff | verdict written | 15 |

