# Build report: Hub live ops (warehouse queue, ingredient screen, accounts, Hub stock)

FOR INFO, then two DECISIONS NEEDED from Damian: "merge" and then "ship" (see section 7).
Spec: `docs/BUILD-SPEC-2026-11-16-live-ops.md` v1.1 · Branch `feat/hub-live-ops` · Run 16 Nov 2026, 09:15 to 10:40 SAST (85 of 120 minutes), unattended · Coordinator: Claude Code (headless).

**Summary**
- All four lanes (A warehouse queue, B ingredient screen, C accounts, D Hub stock) are built, merged and pushed. Nothing was cut.
- Full suite: `RESULT: 130 passed, 0 failed` (89 baseline + 41 new: Warehouse 13, IngredientScreen 7, Accounts 13, StockScreen 4, Navigation 4) (baseline was 89).
- Frozen files untouched (G2 empty). Auditor verdict: PASS-WITH-FLAGS (7 flags, nothing blocked).
- One process deviation: subagents could not run Bash, so the builders wrote the files and the Coordinator ran every test, lint and git step (section 6).
- Nothing is merged to `main` and nothing is deployed. Both wait for you.

## 1. Gates G0 to G5

| Gate | Command | Real output (last line) | Result |
|---|---|---|---|
| G0 preflight | `php -v`; `git status -sb`; `git rev-parse main` | `PHP 8.3.32 (cli)`; `## feat/hub-live-ops...origin/feat/hub-live-ops` (clean); `1851d9df7580ea30535639877b6e01707703af9c` | PASS (09:15) |
| Baseline | `php tests/run.php` at b17ef98 | `RESULT: 89 passed, 0 failed` (09:34, about 11 min per full run) | PASS |
| G1 lane A | `php tests/run.php Warehouse` | `RESULT: 13 passed, 0 failed` (first run) | PASS; ownership diff clean |
| G1 lane B | `php tests/run.php IngredientScreen` | run 1 `6 passed, 1 failed` (test bug); run 2 `RESULT: 7 passed, 0 failed` | PASS; ownership diff clean |
| G1 lane C | `php tests/run.php Accounts` | `RESULT: 13 passed, 0 failed` (first run) | PASS; ownership diff clean |
| G1 lane D | `php tests/run.php StockScreen` | run 1 `3 passed, 1 failed` (escape lint); run 2 `RESULT: 4 passed, 0 failed` | PASS; ownership diff clean |
| G1 after merge | lane folders re-run on `feat/hub-live-ops` after each merge | Warehouse 13/0, StockScreen 4/0, Accounts 13/0, IngredientScreen 7/0 | PASS |
| N1 nav test | `php tests/unit/Navigation/nav_test.php` | red before the edit (3 FAIL), then 4 PASS | PASS |
| G2 frozen files | `git diff --name-only main -- SPEC.md SPEC-locked-decisions.md tests/acceptance db src/Config.php src/Auth src/Money.php src/Http src/Modules/Ordering src/Modules/Recipes src/Modules/Reports src/Fincon bin deploy/sql` (plus the four v1.1 recipe/report pages) and `git diff --diff-filter=DM --name-only main -- tests` | both EMPTY | PASS (10:10) |
| G3 full suite | `php tests/run.php` at 765fb97 | `RESULT: 130 passed, 0 failed` | PASS (10:11 to 10:23). 130 = 89 baseline + 41 new. Acceptance A1 to A7: 7 files, 19 tests, all PASS |
| G4 audit | compliance-gate, given only the spec and `git diff main...feat/hub-live-ops` | PASS-WITH-FLAGS, 7 flags, nothing BLOCKED (section 4) | PASS (10:20) |
| G5 final | G2 + G3 on the final code commit | at ebee5c2: G2 both diffs EMPTY, `main` still `1851d9d`; `RESULT: 130 passed, 0 failed`, acceptance 19/19 PASS | PASS (10:26 to 10:37). The only later commit changes this report file and nothing else |

## 2. Lane reports

RAN: 4/4 lanes returned.

## 3. Test line

`RESULT: 130 passed, 0 failed` (89 baseline + 41 new: Warehouse 13, IngredientScreen 7, Accounts 13, StockScreen 4, Navigation 4)

## 4. Auditor verdict and flags

**G4 (compliance-gate, clean context: spec + diff only): PASS-WITH-FLAGS. Nothing BLOCKED.**
All ten checks passed with file:line evidence: role check first on every new route (`QueueController.php:15`, `AccountController.php:17, 24, 31, 39`); no SQL built from input (IN lists use generated placeholders); the warehouse module only reads; bcrypt, the 12-72 byte rule and an id-only audit line; franchise refused on every new endpoint and franchise locations refused with 422; no money field in the queue; every value escaped, no eval, relative paths; no password in storage, URL or console; nav matches section 9; no schema, config or existing-endpoint change. Flags:
1. The accounts success message says "Give the new user..." rather than "Give Nicole...". This is deliberate (no names in code); spec section 8 should be amended to match.
2. The Hub stock page gets its hub list from lane C's `api/admin/locations`, not the plain id box the spec pointed to. It falls back to the id box if that call fails. The table header shows "location <id>", not the location name.
3. The queue's lines query is split into blocks of 500 order ids. It is one query below 500 open orders; the spec says one query.
4. After a create, the new password stays on screen until the next action or a reload. The spec asks for this; note the over-the-shoulder risk.
5. Where the `HUB-AUDIT` line lands on the live host depends on its `error_log` setting. [Unverified] until checked after deploy.
6. `GET /api/admin/users` returns every login to hq, franchise staff emails included. This is within the spec; review with the business.
7. The auditor ran no tests (no shell). The test results are G3 and G5 above.

**UI review (draft, read-only) of lanes A, B, D: PASS-WITH-FLAGS on all three pages.** Every required id and spec text is present verbatim; the refresh logic and print CSS are correct; there are no arrow functions or template literals and no new hex colours; every input has a label. Flags:
- No horizontal scroll wrapper on the tables. The 8-column ingredients table is the worst and will overflow at 375 px. One-line fix: an `overflow-x:auto` wrapper.
- `stock.js:50` uses `Number.isInteger` and `warehouse.js:238` uses a guarded `closest()`; both are missing on very old Android browsers. Low risk.
- The SAMPLE badge reuses the amber `.badge.low_stock` style.
- "Nothing waiting." can show twice (pick list and order list) when the queue is empty.
None of these were fixed in this run: none is a BLOCKED item, and the spec allows one fix round for BLOCKED items only.

## 5. Branch, commit, push

- Branch `feat/hub-live-ops` on `Xpresso-100/The-Hub`. Commits: a989451 (N1 nav), merges of lanes A, D, C, B, 765fb97 (decisions log; last code change), then the report commits. A commit cannot contain its own hash, so the tip hash is in the push line of the last commit and in `git log -1 origin/feat/hub-live-ops`.
- Push: `a989451..765fb97  feat/hub-live-ops -> feat/hub-live-ops` (10:11).
- Lane branches pushed: `feat/hub-live-ops-lane-a`, `-lane-b`, `-lane-c`, `-lane-d`, each with one lane commit on b17ef98.
- `main` untouched (still `1851d9d`). No deploy, no ssh.

## 6. Cuts, assumptions, NEED-OUT-OF-SCOPE, deviations

**Cuts:** none. Every lane shipped in full, including the print stylesheet, title count, self-refresh, suggest-password button and the deactivate endpoint.

**BLOCKED (permissions, not retried):**
- Bash was denied to every subagent (all four builders, first call, about 09:20). Per the run rules no variant was tried. The lanes were re-run with builders writing files only (Read/Write/Edit), and the Coordinator ran every test, lint, commit and push from its own session. Each builder still wrote its tests before its code; the Coordinator ran the tests and sent failures back.
- `node --check` needs approval, so no JavaScript file was syntax-checked by a tool. JS is covered only by the served-page and lint tests and by the UI reviewer's reading. [Unverified] that every page runs in a browser without a script error.

**Other deviations:**
- Builders for lanes A and C were general-purpose agents, not `forge`: `forge` creates its own worktree, but this run put each lane in a named worktree. Lanes B and D used `draft`.
- The accounts success message does not name Nicole. It says "Give the new user these three things: username, email, password..." so no person's name is hard-coded in the page (POPIA minimisation). It shows the new password once as text, then clears the field, because there is no reset screen.
- Lane notes (`docs/lane-notes/*.md`) show task status "written, not run". They were written before the Coordinator's test runs; this report holds the real results.
- The four lane worktrees stay on disk under `.claude/worktrees/lane-a..d` (ignored by git). Remove them with `git worktree remove` when you no longer need them.

**Assumptions:** every lane decision is one line in `DECISIONS-LOG.md` (33 lines dated 2026-11-16) and in `docs/lane-notes/`. The ones to review with the business:
- `GET /api/admin/users` lists every login, franchise users included, so head office sees franchise staff emails on the Accounts page. The spec gives no filter. Review whether head office should see them.
- Usernames are unique regardless of letter case.
- The Hub stock page's location dropdown for `hq` comes from the new `GET api/admin/locations` (hub type only), with a numeric id box if that call fails.
- The warehouse queue shows open orders from every location, oldest need first.

**NEED-OUT-OF-SCOPE (from lane C):**
- `users.created_by` column: the creator is only in the `HUB-AUDIT account_created` log line; a column needs SQL.
- A password reset screen: today a lost password means deactivate and create a new username.

## 7. WAITING FOR DAMIAN

1. **Merge:** read this report, then say "merge" to merge `feat/hub-live-ops` into `main`.
2. **Ship:** after the merge, say "ship". You run `ship <Hub system name> -DryRun` then `ship <Hub system name>` in a normal PowerShell window. [Unverified] the Hub's system name and whether it is "active" in save-ship `registry.json`; this run could not read it. Expected change set: 15 new app files (`src/Modules/Warehouse/` 3, `src/Modules/Accounts/` 3, `public/` pages and assets 9) plus the changed `public/assets/app.js` and `public/index.html`. The branch also carries `deploy/upload/hub-bundle.zip` and `hub-upload.zip` from your base commit b17ef98, plus `CLAUDE.md`, the spec, the lane notes and this report; none of those should be uploaded to `public_html/hub/`. No database step.
3. **Data:** load the real ingredient list with cost prices (spec section 13). Until then the Hub cannot order in production.
4. **After ship:** the 10-minute browser check in spec section 11. Do not press "Mark delivered" on a test order. Also open each new page once with the browser console open: no tool checked the JavaScript syntax (section 6).
5. **Harness:** subagents had no Bash in this run. If the next unattended build should run lanes on their own, allow `php` and `git` for subagents scoped to `.claude/worktrees/`. This run could not write the `errors-log.md` row or record the agents' LEDGER lines: both live outside the repo, which this session may not write to.
6. **Optional follow-up (not in this spec):** an `overflow-x:auto` wrapper on the ingredients and warehouse tables for 375 px phones, and amend spec section 8's "Give Nicole" wording to match the page.

**Task board total:** 21 of 23 tasks at 100%. A1 and C1 are at 50% because their red run was not observed. Weighted by the estimated minutes: 210 of 215 = 98%.

## 8. Task board (spec section 15)

Percent = done criteria met / total for that task. Times are wall clock.

| # | Task | Done when | Status | % |
|---|---|---|---|---|
| A1 | Access tests | fails for right reason | passes; the red run was not observed (see note) | 50 |
| A2 | routes + controller, requireRole('hq') | A1 passes | passes | 100 |
| A3 | Open-order query, s6 ordering | tests 2, 3 pass | pass | 100 |
| A4 | Lines in one query + email status | tests 6, 7 pass | pass | 100 |
| A5 | Pick list sum and counts | tests 4, 5 pass | pass | 100 |
| A6 | Page: list, counts, pick list | UI test 8 passes | passes | 100 |
| A7 | Forward buttons, delivered confirm, 409 | manual read + UI test | UI test passes; the UI reviewer's manual read found the confirm (`warehouse.js:197`) and 409 text (`:210`) verbatim | 100 |
| A8 | Auto-refresh, title count, print css | UI test 8 still passes | passes | 100 |
| B1 | Table + show-inactive | UI test 1 passes | passes | 100 |
| B2 | Form, number-only cost | tests 2, 3 pass | pass | 100 |
| B3 | Deactivate/reactivate + cost confirm | test 4 passes | passes | 100 |
| C1 | Access tests, all endpoints | fails for right reason | passes; the red run was not observed (see note) | 50 |
| C2 | GET admin/locations | tests 1, 4 | pass | 100 |
| C3 | POST admin/users | tests 2-5 | pass | 100 |
| C4 | GET admin/users without hashes | test 6 | passes | 100 |
| C5 | PATCH deactivate + tokens + self-guard | test 7 | passes | 100 |
| C6 | accounts page | test 8 | passes | 100 |
| D1 | Read inventory API, notes | notes written | written (lane-d.md) | 100 |
| D2 | stock page table + location picker | tests 1, 2 | pass | 100 |
| D3 | Stock count form | tests 3, 4 | pass | 100 |
| N1 | Menu and tiles + nav test | nav test passes | red then green | 100 |
| N2 | Merge lanes, decisions log, report | G2, G3, G5 pass | G2, G3, G5 all PASS; lanes merged; 33 decision lines; report written | 100 |
| V1 | Clean-context audit | verdict written | PASS-WITH-FLAGS written (section 4) | 100 |

Note on "fails for the right reason" (A1, C1): with no shell, builders wrote tests and code before the Coordinator's first run, so the red run of A1 and C1 was never observed. Both tests exist and pass, but the "fails for the right reason" half is unproven, so each scores 50.

**Lane times (builder writing, from re-dispatch at about 09:24):** D about 8 min plus 2 min fix; B about 9 min plus 2 min fix; A about 19 min; C about 19 min. The first dispatch (09:17 to 09:21) produced nothing (Bash denied). Integration 09:52 to 10:11.
