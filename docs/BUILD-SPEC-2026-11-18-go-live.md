# BUILD SPEC v2: Hub go-live, full build and end-to-end proof

FOR INFO: launched unattended at Damian's "go" (18 Nov 2026, 15:33). DECISIONS NEEDED from Damian afterwards: "merge", then "ship".

Version 2.1 · 18 Nov 2026 · Data as at 18 Nov 2026 15:35 SAST (read from the laptop and the live site in chat) · Supersedes v2.0 and v1.0 of the same date · DRAFT, not verified

Repo: `Xpresso-100/The-Hub` · local `C:\dev\The-Hub` · base `feat/hub-live-ops` at `c9bddf7` · run branch `feat/hub-live-ops-golive` · `main` at `1851d9d`

---

## 0. What this run delivers, in one paragraph

The system is built completely without the real ingredient list. The list is a later data drop, loaded with the loader this run builds (§13).

The run does five things:
1. It fixes the upload copy, so `ship` actually uploads the new screens.
2. It adds a loader that can never make an item orderable at R0.00.
3. It fixes the phone layout.
4. It proves the whole Hub journey end to end, twice: through the API, and through a real browser. The browser test runs against the code, and again against the exact upload copy.
5. It fixes every bug the tests find in non-frozen code, and reports any bug found in frozen code as a decision for Damian.

## 0.1 v2.1 rules (these override anything below that disagrees)

1. **Autonomous.** No human approves anything during the run. The permission file (Appendix B) settles every action in advance (ENFORCED). Merge to `main` and deploy stay with Damian, because his standing rules require his yes for both (INSTRUCTED here, and push-to-main is ENFORCED).
2. **The Coordinator commands.**
   - The Coordinator (the main Claude Code session, Opus) is the only agent that splits work, writes task cards (§8), assigns agents, merges and decides what happens next.
   - forge, draft and every other agent act only on a task card and return only the §8 reply.
   - No agent dispatches another agent, except decision-gate routing to on-call agents (§11).
3. **One worktree per task card.**
   - Every task card gets its own git worktree, `.claude/worktrees/<task-id>`, on its own branch, `golive-<task-id>`, made from the current tip of `feat/hub-live-ops-golive`.
   - An agent writes only inside its own worktree, and only the files its card names.
   - Only the Coordinator merges, one branch at a time, and reruns that lane's tests after each merge.
   - Remove a worktree (`git worktree remove`) after its branch is merged.
4. **Two phases, no 120-minute box.**
   - **Phase 1, ship-ready checkpoint CP1, as early as possible:** waves 0–2, then A3 (rebuild the upload copy), G2 (frozen files) and the full `php tests/run.php` green. Push, then write and push `docs/BUILD-REPORT-2026-11-18.md` marked **INTERIM: ship-ready** with the server change set. This is what Damian can merge and ship from.
   - **Phase 2, continuing overnight:** browser E2 and E3, wave 3 reviews, the §11 fix loop, V1–V3, then overwrite the report as **FINAL** and push.
   - Push after every merge, so GitHub always has the latest green state.
5. **Stop conditions.** Stop when all §1 criteria are met. Otherwise stop at 9 h 30 min of run time, or when every open item is BLOCKED under the two-strike rule. Either way, write the FINAL report with what is done. Never leave the branch red: if the last merge breaks the suite, revert that merge (`git revert -m 1`), then report.
6. **Concurrency.** At most 4 builders at once. Reviewers run after builders, not alongside.

## 1. Done criteria

1. `php tests/run.php` ends `RESULT: <n> passed, 0 failed`, with n ≥ 130 + the new tests.
2. `node tests/browser/run.cjs --root repo` and `--root bundle` both end `BROWSER: <n> passed, 0 failed`.
3. `node --check` passes on every `public/assets/*.js`.
4. The upload copy equals the code (the Deploy parity test passes), and the server change set lists 0 deletions.
5. The frozen files are unchanged and no existing test is modified or deleted.
6. Every review verdict is PASS or PASS-WITH-FLAGS: reality-checker, ledger, red-team, popia-data-protection, compliance-gate and live-verifier.
7. The branch is pushed and `docs/BUILD-REPORT-2026-11-18.md` is written. Nothing is merged to `main` and nothing is deployed.

## 2. Facts at base (read in chat, 18 Nov)

| Fact | Evidence |
|---|---|
| The live app is up; the new screens are not live | health check returns HTTP 200 `{"success":true,"status":"ok"}`; `warehouse.html` returns 404 (12:24) |
| `feat/hub-live-ops` is unmerged | `origin/main` = `1851d9d`, branch tip = `c9bddf7` |
| The upload copy is stale | `deploy/upload/hub` has 65 files and no Warehouse, Accounts or `warehouse.html` |
| `ship` uploads the copy | save-ship registry, system `hub`: `source_dir=deploy/upload/hub`, `server_path=public_html/hub`, `mode=folder`, `protected=var/**` |
| `build-bundle.ps1` refuses to overwrite | it throws if `deploy/upload/hub` exists; it writes `deploy/FILE-LIST.txt` |
| The current importer makes R0.00 lines orderable | `bin/import_ingredients.php` accepts cost ≥ 0 and sets `active = 1` |
| The test harness runs the real app | `tests/lib/harness.php` (`TestApp`): `php -S` on a free port, fresh SQLite, migrate + seed `--fixtures`; used by `tests/e2e/hub_smoke_flow_test.php` |
| Browser tooling is present | `%APPDATA%\npm\node_modules\@playwright` exists, `%LOCALAPPDATA%\ms-playwright` exists, Node is at `C:\Program Files\nodejs`. [Unverified] which `@playwright` package it is. |
| Money helpers | `src/Money.php`: `roundHalfUp(int,int)`, `lineCents`, `parseCents`, `parseMilli`, `format` |
| Bench roster | 40 agent files in `C:\Users\damia\.claude\agents` (listed §6) |

## 3. Scope

**In:**
- Lane A: upload copy and parity test.
- Lane B: phone tables and UI fixes.
- Lane C: list loader.
- Lane E: end-to-end journeys (API and browser).
- The bug-fix loop.
- The reviews.
- The CLAUDE.md bundle rule.
- The report.

**Out:**
- merge, deploy, ssh or scp, SQL against any real database;
- edits to frozen files (§5);
- the real ingredient list and real costs;
- recipes, FinCon writes, a cost-upload screen, password reset, `users.created_by`;
- the `hub-bundle.zip` and `hub-upload.zip` files.

**Test data uses fixture names only** (for example `hub.e2e`, `hq.e2e`, "Test Flour"), never a real person's name or a real price.

## 4. The five engineering layers, applied to this build

| Layer | What it is | The rule in this run |
|---|---|---|
| **Prompt engineering** | the exact words each agent gets | Every task goes to its agent as a **task card** (§8): role, inputs, the one output, a done-when command, forbidden actions, and a 10-line return format. No free-form briefs. |
| **Context engineering** | what each agent sees | An agent reads only its task card, this spec's §5 and its lane section, `CLAUDE.md`, and the files the card lists. No agent reads logs, other lanes' notes or the 16 Nov report. Results come back as the 10-line return, not as file dumps. The Coordinator compacts at 50% context. |
| **Harness engineering** | the runtime around the agents | headless `claude -p`, a permission file (Appendix B), git worktrees per lane, a 150-minute watchdog, `STATUS.md` updated after every task. ENFORCED vs INSTRUCTED is listed in §7. |
| **Loop engineering** | how each task repeats until it is right | test first → red for the stated reason → code → green → reviewer. **Two-strike rule:** two failed fix rounds → BLOCKED with evidence, move on. **No-progress rule:** the same command with the same output twice → stop. **Time box:** 120 minutes of work, then the final gates with what is done. |
| **Graph engineering** | how agents connect | §9: four waves with explicit edges, at most 4 builders at once, merges that count their inputs, and a clean-context audit. |

[Inference] The five-layer stack, prompt → context → harness → loop → graph, follows the August 2026 Graph Engineering survey (arXiv:2608.21156) as summarised by TrueFoundry.

## 5. Frozen files and lane ownership

**Frozen (read only):**
- `SPEC.md`, `SPEC-locked-decisions.md`
- `tests/acceptance/`, every existing file under `tests/`
- `db/`, `deploy/sql/`, `deploy/build-bundle.ps1`, `deploy/htaccess-root.txt`, `deploy/config.local.php.TEMPLATE`
- `src/Config.php`, `src/Db.php`, `src/Auth/`, `src/Money.php`, `src/Http/`, `src/Fincon/`
- `src/Modules/Ordering/`, `src/Modules/Recipes/`, `src/Modules/Reports/`
- `bin/import_ingredients.php`, `bin/hash_password.php`
- `public/recipes.html`, `public/reports.html`, `public/assets/recipes.js`, `public/assets/reports.js`

| Lane | Owns |
|---|---|
| A, upload copy | `tests/unit/Deploy/` (new); in task A3 only (Coordinator): `deploy/upload/hub/`, `deploy/FILE-LIST.txt` |
| B, UI | `public/ingredients.html`, `public/warehouse.html`, `public/stock.html`, `public/accounts.html`, `public/index.html`, `public/assets/app.css`, `public/assets/app.js`, `public/assets/warehouse.css`, and the `.js` files for warehouse, ingredients, stock and accounts; `tests/unit/MobileTables/` (new) |
| C, loader | `bin/load_hub_list.php` (new), `tests/unit/HubListLoader/` (new), `tests/fixtures/hub-list/` (new) |
| E, end to end | `tests/e2e/golive_journeys_test.php` (new), `tests/browser/` (new), `tests/fixtures/e2e/` (new) |
| Fix tasks (§11) | the files of the lane that owns the bug, plus `src/Modules/Warehouse/` and `src/Modules/Accounts/` (built 16 Nov, not frozen) |
| Coordinator | `.gitignore`, `DECISIONS-LOG.md` (append only), `docs/run/STATUS.md`, `docs/lane-notes/2026-11-18-*.md`, `docs/BUILD-REPORT-2026-11-18.md`, the §8 wording in `docs/BUILD-SPEC-2026-11-16-live-ops.md`, merges |
| context-curator only | `CLAUDE.md` (one new rule, task N2) |

## 6. Bench roster: 15 active, 25 on call

The 40 agents are in `C:\Users\damia\.claude\agents`. **At most 4 builder agents run at once** (laptop limit). Reviewers run in wave 3, not alongside builders.

**Active (15):**

| Agent | Model | Job in this run |
|---|---|---|
| Coordinator (main session) | Opus | runs the waves, merges, gates and the bug loop; writes the report |
| project-shepherd | sonnet | updates `docs/run/STATUS.md` after each task (task, owner, state, test line, time) |
| decision-gate | haiku | routes every failure or BLOCKED item to the owner or the on-call agent (§11) |
| Explore | haiku | read-only lookups for builders, so builders don't load whole files |
| forge | sonnet | lanes A1 and C (two instances, separate worktrees) |
| draft | sonnet | lane B |
| eval-engineer | sonnet | E1: the API journeys J1–J9 |
| test-engineer | sonnet | E2: the browser test W1–W7 |
| ledger | opus | reviews the loader's money maths and the J5 and J8 cost checks (money is on the critical-code list) |
| reality-checker | opus | adversarial review of lanes B and E: do the tests prove what they claim? |
| red-team | opus | attacks the new endpoints (Accounts, Warehouse, Ingredients) in its own worktree; turns findings into failing tests only, never fixes |
| popia-data-protection | opus | reviews the 16 Nov flag "GET /api/admin/users shows franchise emails to head office"; recommends, does not code |
| context-curator | sonnet | adds the bundle rule to `CLAUDE.md` (N2) |
| live-verifier | opus | independent re-run of everything in a fresh worktree at the end; compares counts with the Coordinator's |
| compliance-gate | opus | final gate: spec + diff only, clean context |

**On call: dispatched only by decision-gate, when its trigger fires:**

| Trigger | Agent |
|---|---|
| a loop stalls (two strikes, or no progress) | loop-strategist |
| a guard or permission blocks a legitimate step, or a lesson needs a rule | harness-engineer |
| topology needs to change mid-run (a new lane, a merge conflict across lanes) | multi-agent-systems-architect |
| a spec gap or contradiction | spec-architect |
| a schema or ingredients-table question | data-authority |
| a CSV, Excel or delimiter issue | data-engineer |
| a fact about Playwright, PHP or Node versions | web-researcher, then beacon |
| a cost or time overrun warning | trace-cost-analyst (also runs once at the end) |
| a memory or lessons duplicate | memory-steward |
| a contract or legal wording question | legal-contracts |
| a cross-lane operations question that no file answers | operations-lead (writes the question for Damian; does not guess) |

**Not expected in this build** (on standby, no trigger): beacon (except as above), comms-lead, content-creator, growth-hacker, instagram-curator, social-media-strategist, tiktok-strategist, embedded-firmware-engineer, bookkeeper-controller, chief-financial-officer, payroll-tax-officer, pricing-analyst, business-strategist, hospitality-guest-services, people-lead.

## 7. Harness: what is ENFORCED vs INSTRUCTED

**ENFORCED** by the permission file in Appendix B:
- Pushes go only to exactly `feat/hub-live-ops-golive`, matched exactly with no wildcard, so a `branch:main` refspec is refused.
- No force push. No `reset --hard`, `stash` or `clean`.
- No ssh, scp, curl or wget. No `rm -rf`.
- No reading `.env` or `config.local.php`.
- PowerShell only as the one exact bundle command.
- Node only for `--check` and the two browser commands.

**INSTRUCTED** (this spec and CLAUDE.md):
- no merge to `main`;
- no edits to frozen files;
- no deploy;
- the time box;
- the 4-builder cap;
- fixture-only data.

**If a subagent's Bash is denied,** do not try variants. The builder writes files only, and the Coordinator runs its commands (this worked on 16 Nov). Record it under deviations.

## 8. Task card format (the prompt contract)

The Coordinator sends every task as exactly this:

```
TASK <id>: <one line>
AGENT: <name>   WORKTREE: .claude/worktrees/<id>   BRANCH: golive-<id>
READ ONLY THESE: <files>
WRITE ONLY THESE: <files>
DO: <steps, max 6>
DONE WHEN: <exact command> prints <exact expected line>
NEVER: edit frozen files (§5), push, merge, ssh, use real names or prices
RETURN (max 10 lines): STATUS=DONE|BLOCKED · FILES=<list> · RED=<first failing line> · GREEN=<final test line> · NOTES=<≤3 lines>
```

## 9. Graph: waves and edges

```
[GRAPH DECISION]
GATE:     Q1 ✓ lanes A1, B, C, E share no files · Q2 ✓ with the E2E work added, one
          context would not hold builders + browser harness + reviews (live-verifier
          and the reviewers need clean contexts by design) · Q3 ✓ shape known ·
          Q4 ✓ worktrees + ownership table §5
PATTERN:  fan-out (wave 1) → adversarial verification (wave 3) → loop until green (wave 4)
WHY:      one agent would finish the builders and skip the browser proof and reviews
          inside the time box; separate reviewers are the only way the work isn't grading itself
SHAPE:    15 active, ≤4 builders at once; 25 on call; pilot = baseline + A1 first
VERIFY:   real signals only: php tests/run.php, node tests/browser/run.cjs, node --check,
          git diff of frozen paths. Never "agent says done".
FROZEN:   §5 files; compliance-gate, popia-data-protection, reality-checker never edited
COST:     ~15 agents, size guideline medium (<15 concurrent); trace-cost-analyst reports actuals
```

**Edges (only these):**
- W0 → everything.
- B and C → E1 J3/J5 (the journeys need the loader and the pages).
- B, C and E → wave 3 reviews.
- The wave 3 findings → the wave 4 fix loop.
- Everything merged → A3 (the bundle copies the final code) → E3 (browser test on the bundle) → final gates.

**Merge count:** each merge lists `expected N task returns, got N`. A gap is flagged at the top of STATUS.md and the report.

## 10. The tasks (bite-sized, each ≤ 15 min)

### Wave 0: preflight (Coordinator, ≈ 10 min)

| # | Task | Done when |
|---|---|---|
| W0.1 | `php -v`; `git status -sb`; `git rev-parse origin/main` | PHP ≥ 8.1, `main` = `1851d9d` |
| W0.2 | `git switch -c feat/hub-live-ops-golive c9bddf7`; commit this spec to `docs/` | branch exists |
| W0.3 | baseline `php tests/run.php` | `RESULT: 130 passed, 0 failed`; anything else → stop, report |
| W0.4 | create `docs/run/STATUS.md` with the board below; add `.claude/verify-gate-*.count` to `.gitignore`; change the 16 Nov spec §8 "Give Nicole" to "Give the new user" | committed |

### Wave 1: build (≤ 4 builders at once, ≈ 45 min)

**Lane A: upload copy (forge #1)**

**A1.** Write `tests/unit/Deploy/bundle_parity_test.php`. It checks:
- For each of `public`, `src`, `db` and `bin`: the same file set in the repo and in `deploy/upload/hub/<dir>`, with the same SHA-1.
- `.htaccess` equals `deploy/htaccess-root.txt`.
- `var/.htaccess` equals the repo's `var/.htaccess`.
- `config.local.php.TEMPLATE` equals `deploy/config.local.php.TEMPLATE`.
- No `config.local.php`, `.env` or `tests/` in the bundle.
- The first line of `FILE-LIST.txt` states the real file count.

Done when: red, with "missing src/Modules/Warehouse/…".

**A2.** Write the red output to the lane note. Done when: the note exists.

**Lane B: UI (draft)**

**B1.** Wrap every `<table>` on the four new pages in `<div class="table-scroll">`. Add `.table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}` to `app.css`. Use no new colours.

**B2.** Warehouse page: show "Nothing waiting." once, above the pick list.

**B3.** Write `tests/unit/MobileTables/mobile_tables_test.php`. It checks:
- every `<table>` on the four pages sits inside `.table-scroll`;
- `app.css` holds the rule;
- the literal `Nothing waiting.` appears exactly once in `warehouse.js`.

It must go red first, then green.

**B4.** Run `node --check` on each `public/assets/*.js`. Done when: every file exits 0. A failure in another lane's file → BLOCKED to decision-gate.

Code style: no arrow functions, no template literals, every value escaped, relative paths.

**Lane C: loader (forge #2)**

**C1.** Write `tests/unit/HubListLoader/load_hub_list_test.php` with cases 1–10 below, plus fixtures. It must go red.

**C2.** Parse and validate:
- The header must be exactly `sku,name,unit,pack_size,pack_price,fincon_item_no`.
- Take the delimiter (`,` or `;`) from the header line. With `;`, also accept a decimal comma.
- Strip spaces and non-breaking spaces from numbers.
- Strip a UTF-8 BOM.
- Skip blank lines and lines where both sku and name are empty.

| Field | Rule |
|---|---|
| sku | same rule as `IngredientController` (read it); unique in the file |
| name | 1–150 characters, no control characters |
| unit | 1–20 characters |
| pack_size | blank, or > 0 with ≤ 3 decimals |
| pack_price | blank, or ≥ 0 with ≤ 2 decimals |
| fincon_item_no | blank, or ≤ 25 characters |

All or nothing: any bad row → list every problem with its line number, change nothing, exit 1.

**C3.** Write path, in one transaction:

| Case | Result |
|---|---|
| size > 0 and price > 0 | Upsert with `cost_cents = Money::roundHalfUp(price_cents * 1000, size_milli)`, `is_sample = 0`, `active = 1`. Report ACTIVE. |
| price or size blank, or price 0; sku not in the table | Insert with `cost_cents = 0`, `active = 0`. Report OFF. |
| price or size blank, or price 0; sku exists | Update name, unit and fincon_item_no only. Report KEPT. |

Options:
- `--dry-run` writes nothing.
- `--deactivate-samples` sets `active = 0` on every `is_sample = 1` row.

Output:
- One line per row, for example `HUB009 ACTIVE per kg R51.44 · pack 24 kg = R1 234.56 · diff R0.00`.
- A final line: `load_hub_list: <n> active, <n> off, <n> kept, <n> samples off`.
- Exit codes: 0 OK, 1 invalid, 2 usage.
- It writes only `ingredients`.

**C-cases:**
1. Comma CSV: 24 kg at R1 234.56 → 123456 × 1000 / 24000 = 5144 cents per kg.
2. The same with `;` and a decimal comma → same result.
3. A blank price on a new sku → active 0, cost 0.
4. A blank price on an existing priced sku → cost and active unchanged.
5. A price of 0 → never active.
6. One bad row among good rows → nothing written, exit 1, every problem listed.
7. `--dry-run` → nothing changes.
8. `--deactivate-samples` → only sample rows switched off.
9. A duplicate sku → exit 1.
10. Rounding half up: R10.00 over 3 kg → 333 cents; R0.05 over 2 → 3 cents.

**Lane E: end to end**

**E1, API journeys (eval-engineer).** Write `tests/e2e/golive_journeys_test.php`. It is black-box through `TestApp`, like `hub_smoke_flow_test.php`, with fixture users and fixture ingredients only.

| Journey | What it proves |
|---|---|
| J1 | hq creates a `hub` user through `POST /api/admin/users`. A weak password, a duplicate username and a franchise location are each refused. |
| J2 | the new hub user logs in; Accounts and Ingredients admin endpoints return 403; ordering and inventory return 200 |
| J3 | the loader runs on the test DB with a fixture CSV (3 priced lines + 1 unpriced). The hub ingredient list shows exactly the 3 priced lines; samples are off. |
| J4 | the hub enters a stock count through `POST /api/inventory/adjustments`; `GET /api/inventory` shows it |
| J5 | the hub orders 2 lines. `GET /api/warehouse/queue` shows the order, its lines and the pick-list totals. Line cost = `Money::lineCents(qty, cost)` excl. VAT, with no VAT added. Use hand-calculated values. |
| J6 | hq moves pending → in_process → out_for_delivery → delivered. Hub stock rises by exactly the ordered quantities. A second "delivered" and any backward move return 409. |
| J7 | ordering the unpriced (inactive) sku is refused |
| J8 | a franchise user gets 403 on warehouse and admin endpoints; cancel works from pending |
| J9 | a deactivated user's token stops working |

**If J7 (or any journey) fails because of frozen code** (`src/Modules/Ordering/` and the like), do not fix it. Move that one test to `tests/known-issues/` (not auto-run), keep its failure output, and report it as a DECISION for Damian. Any other failure goes to the fix loop.

**E2, browser test (test-engineer).**

E2.0 (≤ 10 min, pilot):
- Find the Playwright package under `%APPDATA%\npm\node_modules\@playwright` and confirm `require('playwright')` or `require('@playwright/test')` resolves with `NODE_PATH` set (run.ps1 sets it).
- If neither resolves → BLOCKED to decision-gate. Do not install anything; web-researcher only looks up the answer.
- The fallback is the API journeys plus `node --check`, reported as a gap.

E2.1: write `tests/browser/serve.php`:
- boots the app with the `TestApp` harness;
- takes `--root repo|bundle` (`bundle` = serve from `deploy/upload/hub`, with the same SQLite and fixture seed);
- prints `PORT=<n>` and stays up until stdin closes.

E2.2: write `tests/browser/run.cjs`:
- spawns `serve.php`;
- runs W1–W7 in Chromium, headless;
- fails a check on any console error, page error, or unexpected 4xx/5xx;
- prints one line per check and the final `BROWSER: <n> passed, <n> failed`.

| Check | What it proves |
|---|---|
| W1 | the login page loads; hq logs in; the index shows Warehouse, Ingredients, Accounts and Hub stock, and not Recipes |
| W2 | each new page (warehouse, ingredients, accounts, stock) loads with zero console errors |
| W3 | Accounts: create a hub user through the form; the password is shown once; the user appears in the list |
| W4 | a second browser context logs in as that hub user; ordering and Hub stock show; Accounts and Ingredients don't |
| W5 | the hub places an order through the ordering page; the hq warehouse page shows it after a reload, with the title count |
| W6 | hq marks it through to delivered; the confirm dialog appears and is accepted (test DB only); the hub's stock page shows the new quantity |
| W7 | at a 375 × 812 viewport, each of the four pages has no page-level sideways scroll: `documentElement.scrollWidth ≤ 375` |

### Wave 2: merge (Coordinator, ≈ 10 min)

**M1.** Merge B, then C, then A1, then E into `feat/hub-live-ops-golive`. After each merge, re-run that lane's tests. Merge count: 4 of 4.

**M2.** Run `php tests/run.php` and `node tests/browser/run.cjs --root repo`. Every failure becomes a fix card (§11).

**N2.** context-curator adds this rule to the repo's `CLAUDE.md`: "Any change under public/, src/, db/ or bin/ ships only after `deploy/upload/hub` is rebuilt (git rm -r the folder, run deploy/build-bundle.ps1) and the Deploy parity test passes."

### Wave 3: review (run in parallel, each reviewer in a clean context, ≈ 20 min)

| # | Reviewer | Input | Output |
|---|---|---|---|
| R1 | ledger | `bin/load_hub_list.php`, the C tests, J5 | PASS/FLAGS/BLOCKED on rounding, units and VAT basis |
| R2 | reality-checker | lanes B and E diffs + this spec | do the tests prove W1–W7 and J1–J9, or only look like it? |
| R3 | red-team | its own worktree off the merged branch; the new endpoints | failing tests for any real hole (IDOR, role bypass, unescaped output, token reuse); no fixes |
| R4 | popia-data-protection | the Accounts module and page | a verdict and a recommendation on the franchise-emails flag; code only if BLOCKED |

### Wave 4: fix loop, rebuild, final gates (≈ 35 min)

| # | Task | Done when |
|---|---|---|
| F* | one fix card per failure or BLOCKED finding (§11) | its failing test goes green; full suite still green |
| A3 | Coordinator: `git rm -r -q deploy/upload/hub` → `powershell.exe -NoProfile -ExecutionPolicy Bypass -File deploy/build-bundle.ps1` → `git add deploy/upload/hub deploy/FILE-LIST.txt` → `php tests/run.php Deploy` | parity green; the A/M/D counts from `git diff --name-status main -- deploy/upload/hub` written to the report, with every D line listed |
| E3 | `node tests/browser/run.cjs --root bundle` | `BROWSER: 7 passed, 0 failed` on the copy that will be uploaded |
| G2 | frozen check: `git diff --name-only c9bddf7 HEAD -- <§5 paths>` and `git diff --diff-filter=DM --name-only c9bddf7 HEAD -- tests` | both empty (except files moved to `tests/known-issues/`, which are new) |
| G3 | full `php tests/run.php` + both browser runs + `node --check` on all JS | all green |
| V1 | live-verifier: a fresh worktree at HEAD; runs G3 itself | its counts match the Coordinator's |
| V2 | compliance-gate: this spec + `git diff c9bddf7...HEAD -- . ':!deploy/upload'` only | PASS or PASS-WITH-FLAGS; BLOCKED → one fix round → G3 again |
| V3 | trace-cost-analyst | tokens, time and model mix in the report |
| P1 | `git push -u origin feat/hub-live-ops-golive`; write and commit the report; push | report on GitHub |

## 11. Bug-fix loop

1. A failing test, red-team test or BLOCKED verdict goes to **decision-gate** with the failing line.
2. decision-gate picks the owner from §5. A frozen file is never an owner.
   - If it belongs to a lane, it goes to that lane's builder as a fix card (§8). The DONE WHEN is the failing test going green.
   - If the bug is in frozen code, it goes to `tests/known-issues/` and is reported as a DECISION, not fixed.
   - If it is unclear, it goes to the on-call agent for the trigger (§6).
3. After a fix: run the failing test, then the full suite, then the browser run.
4. Two failed rounds on one bug → BLOCKED, with both outputs in the report.
5. No bug is closed by changing an assertion to match wrong behaviour. reality-checker re-checks every changed assertion.

## 12. Report (`docs/BUILD-REPORT-2026-11-18.md`)

- **Line 1:** "DECISIONS NEEDED from Damian: merge, then ship" (plus any frozen-code bug decisions).
- **Summary (≤ 5 lines):**
  - the test line;
  - the browser lines (repo and bundle);
  - the frozen-files result;
  - the review verdicts;
  - the server change set A/M/D.
- **Body:**
  1. the gates table, with the real last line of each command;
  2. the journeys and browser checks, with PASS/FAIL each;
  3. the bugs found, with fixed or open status and the evidence;
  4. the reviewers' flags;
  5. the deviations and BLOCKED items;
  6. the server change set, with every D line;
  7. the merge counts;
  8. cost and time;
  9. the waiting list (§13);
  10. the final task board.
- Do not claim anything that was not run.

## 13. After the run: Damian (attended)

1. **Read the report → "merge"** (`feat/hub-live-ops-golive` → `main`; this one merge includes the 16 Nov work).
2. **`ship hub -DryRun`.** Continue only if:
   - the additions match the report;
   - there are **0 deletions** on the server;
   - `config.local.php` and `var/` are untouched.

   Otherwise stop and send the output.
3. **"ship"**, then confirm the health URL returns 200 and `warehouse.html` returns 200.
4. **Live check (≈ 10 min, console open):**
   - log in as head office and see the four tiles;
   - create Nicole's account (hub location). Her password goes by WhatsApp;
   - log in as Nicole in a private window and check she sees ordering and stock;
   - on the Ingredients screen, tick "show inactive" and note the SAMPLE lines.

   Do not place a test order on the live system until the real list is loaded. Never press "Mark delivered" on a test order.
5. **Data drop, whenever Claude gives you the CSV:** in an attended Claude Code session, after your yes, upload the CSV to the server's `var/` folder. Run `php bin/load_hub_list.php var/<file>.csv --dry-run --deactivate-samples` and read it, then run it without `--dry-run`. Delete the CSV from the server afterwards. Lines without a price stay off until finance prices them.
6. **Nicole's opening count** on Hub stock (switched-on lines only).

## 14. Status board (`docs/run/STATUS.md`; project-shepherd keeps it current)

Columns: task · agent · state (todo/running/red/green/blocked) · last test line · minutes.

Rows:
- W0.1–W0.4
- A1, A2
- B1–B4
- C1–C3
- E1, E2.0–E2.2
- M1, M2, N2
- R1–R4
- F*
- A3, E3, G2, G3, V1–V3, P1

Percent done = green rows ÷ total rows, weighted by the estimates in §10.

To watch the run from anywhere: `git log origin/feat/hub-live-ops-golive` after the first push, or `%TEMP%\hub-golive\status.txt` on the laptop.

---

## Appendix A: launcher `%TEMP%\hub-golive\run.ps1`

```powershell
$d = "$env:TEMP\hub-golive"
Set-Location C:\dev\The-Hub
$env:NODE_PATH = "$env:APPDATA\npm\node_modules"
# keep the laptop awake while the run lasts (released when this script ends)
Add-Type -Name P -Namespace W -MemberDefinition '[DllImport("kernel32.dll")] public static extern uint SetThreadExecutionState(uint f);'
[W.P]::SetThreadExecutionState([uint32]2147483649) | Out-Null
"started $(Get-Date -Format s)" | Set-Content "$d\status.txt"
$p = Start-Process -FilePath "cmd.exe" -ArgumentList "/c claude.cmd -p --verbose --output-format stream-json --permission-mode acceptEdits --settings $d\settings.json" -RedirectStandardInput "$d\prompt.txt" -RedirectStandardOutput "$d\log.jsonl" -RedirectStandardError "$d\err.txt" -PassThru -WindowStyle Hidden
"claude launcher pid $($p.Id)" | Add-Content "$d\status.txt"
if (-not $p.WaitForExit(36000000)) {
  taskkill /PID $p.Id /T /F | Out-Null
  "TIMEOUT after 10 h, killed $(Get-Date -Format s)" | Add-Content "$d\status.txt"
} else { "finished exit=$($p.ExitCode) $(Get-Date -Format s)" | Add-Content "$d\status.txt" }
```

**Prompt (`prompt.txt`):**

> You are the Coordinator of an unattended, fully autonomous build in C:\dev\The-Hub. Nobody will answer questions or approve anything. Read CLAUDE.md, then docs/BUILD-SPEC-2026-11-18-go-live.md (v2.1, placed in the repo before launch, uncommitted). §0.1 overrides the rest. You alone split work, write §8 task cards, assign the agents named in §6, and merge. Give every task card its own worktree and branch. Run at most 4 builders at once, and call on-call agents only through decision-gate. Reach checkpoint CP1 (ship-ready, pushed, INTERIM report) as early as possible, then continue with Phase 2 until §0.1 rule 5 stops you. Never merge to main, never deploy, never edit frozen files, never retry a denied command with a variant, never use real names or prices, never leave the branch red. If a subagent has no Bash, it writes files only and you run its commands. Keep docs/run/STATUS.md current. Your last message is the FINAL report summary.

## Appendix B: `%TEMP%\hub-golive\settings.json`

```json
{
  "permissions": {
    "defaultMode": "acceptEdits",
    "allow": [
      "Read", "Edit", "Write", "Glob", "Grep", "Agent", "Task", "TodoWrite",
      "Bash(php:*)", "Bash(node --check:*)",
      "Bash(node tests/browser/run.cjs --root repo)", "Bash(node tests/browser/run.cjs --root bundle)",
      "Bash(git status:*)", "Bash(git diff:*)", "Bash(git log:*)", "Bash(git add:*)", "Bash(git commit:*)",
      "Bash(git branch:*)", "Bash(git checkout:*)", "Bash(git switch:*)", "Bash(git worktree:*)",
      "Bash(git merge:*)", "Bash(git rev-parse:*)", "Bash(git show:*)", "Bash(git ls-files:*)", "Bash(git mv:*)",
      "Bash(git rm -r -q deploy/upload/hub)",
      "Bash(powershell.exe -NoProfile -ExecutionPolicy Bypass -File deploy/build-bundle.ps1)",
      "Bash(git push -u origin feat/hub-live-ops-golive)", "Bash(git push origin feat/hub-live-ops-golive)",
      "Bash(ls:*)", "Bash(cat:*)", "Bash(grep:*)", "Bash(wc:*)", "Bash(head:*)", "Bash(tail:*)",
      "Bash(mkdir:*)", "Bash(find:*)", "Bash(diff:*)", "Bash(date:*)", "Bash(pwd)", "Bash(cd:*)", "Bash(sha1sum:*)"
    ],
    "deny": [
      "Bash(git push origin main:*)", "Bash(git push --force:*)", "Bash(git push -f:*)",
      "Bash(git reset --hard:*)", "Bash(git stash:*)", "Bash(git clean:*)", "Bash(git merge main:*)",
      "Bash(ssh:*)", "Bash(scp:*)", "Bash(rm -rf:*)", "Bash(curl:*)", "Bash(wget:*)", "Bash(npm:*)", "Bash(npx:*)",
      "Read(./.env)", "Read(./config.local.php)", "Read(./deploy/upload/hub/config.local.php)"
    ]
  }
}
```

The push rules are exact matches: `git push origin feat/hub-live-ops-golive:main` does not match an allow rule, so headless mode refuses it (ENFORCED). [Unverified] whether `-p` mode refuses unlisted commands silently rather than stalling. It refused them on 16 Nov.
