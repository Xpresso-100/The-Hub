DECISIONS NEEDED: merge, then ship (plus 4 decisions listed below; one push by hand first)

# BUILD REPORT 2026-11-18: Hub go-live (FINAL)

Document type: status and decision request. Branch `feat/hub-live-ops-golive`. Spec: `docs/BUILD-SPEC-2026-11-18-go-live.md` v3.1.
Cost of a week's slip: the Warehouse, Ingredients, Accounts and Hub stock screens stay off the live Hub until merge and ship. ZAR at stake and ROI: not measured in this run [Unverified]; V3 cost report was not computed (see s5).

## Summary
- Tests: `RESULT: 164 passed, 0 failed` (baseline was 130).
- Browser: repo `BROWSER: 7 passed, 0 failed`; upload copy (bundle) `BROWSER: 7 passed, 0 failed`.
- Frozen files: both diffs empty; no existing test modified or deleted.
- Reviews: ledger, reality-checker, red-team, popia-data-protection, compliance-gate = PASS-WITH-FLAGS; live-verifier = PASS (after one FAIL round, fixed).
- Server change set (upload copy vs `main`): 16 added, 3 modified, **0 deletions**.

## 1. Gates (real last lines)
| Gate | Command | Last line |
|---|---|---|
| M2 | `php tests/run.php` | `RESULT: 154 passed, 0 failed` (before the review fixes) |
| M2 | `node tests/browser/run.cjs --root repo` | `BROWSER: 7 passed, 0 failed` |
| A3b | `php tests/run.php Deploy` | `RESULT: 6 passed, 0 failed` |
| E3 | `node tests/browser/run.cjs --root bundle` | `BROWSER: 7 passed, 0 failed` |
| F-R1 | `php tests/run.php HubListLoader` | `RESULT: 15 passed, 0 failed` |
| F-R2 | `php tests/run.php golive` | `RESULT: 9 passed, 0 failed` |
| G2 | frozen-path diff; tests D/M diff | both empty |
| G3 (50380d9) | `php tests/run.php` | `RESULT: 164 passed, 0 failed` |
| G3 | `node --check public/assets/*.js` | 8 files, 0 failures |
| G3 | browser repo / bundle | `BROWSER: 7 passed, 0 failed` / `BROWSER: 7 passed, 0 failed` |
| V1 round 2 (ebf9861, fresh LF checkout, independent) | full suite, Deploy, node, both browsers | 164/0, 6/0, 8 clean, 7/0, 7/0; counts match the Coordinator's |
| Final (see s10) | proof command on the final commit | recorded in `docs/run/OVERNIGHT.md` Done log |

## 2. Journeys and browser checks
J1-J9 (API, `tests/e2e/golive_journeys_test.php`): all PASS (9/0). W1-W7 (browser): all PASS on repo and on the upload copy. After review: W7 now measures the warehouse page with an order showing (it measured an empty page before); J8 now asserts the R14.00 total.
Not proven: red runs for J1-J9 and W1-W6 are documented only in commit messages (reality-checker, [Unverified]).

## 3. Bugs found
| Bug | Status | Evidence |
|---|---|---|
| Loader left a row already active at R0.00 orderable | FIXED (F-R1) | red `12 passed, 3 failed` -> `15 passed, 0 failed` |
| W7 never measured the populated warehouse table | FIXED (F-R2) | new W7 passes with order card and table present; throwaway `<=100` run failed as it should |
| FILE-LIST size check depended on checkout line endings (V1 FAIL, round 1) | FIXED | `.gitattributes` (`* text=auto eol=lf`), FILE-LIST regenerated from an LF tree; Deploy 6/0 in main and in a fresh checkout |
| Login username timing oracle, `src/Auth/Auth.php:34-35` (unknown user = 2 bcrypt ops, known = 1; ratio 1.66-2.18) | OPEN, frozen code | failing test `tests/known-issues/login_username_timing_test.php`; DECISION 1 |

## 4. Reviewer flags (all PASS-WITH-FLAGS)
- **ledger:** money maths correct (5144, 333, 3 cents; no float; no VAT). Open: a unit change on a KEPT row keeps the old cost (spec C3 says so, test C-4 locks it): DECISION 2. Runbook must pass `--deactivate-samples`.
- **reality-checker:** LOW: MT-2 vacuous for 3 of 4 files; J6 skips two status moves; J2 skips PATCH as hub; W6 breaks above 1 000 units; W5 substring row match. Not fixed (LOW, existing tests not edited).
- **red-team:** the only real hole is the timing oracle above. Held: roles, location scope, mass assignment, password rules, deactivation, SQLi, XSS. LOW, unfixed: no login rate limit (`Auth.php:28`); `fincon_item_no` accepts control characters (`IngredientController.php:51-56`); narrow deactivate/login race [Inference]; `APP_ENV='Prod'` not treated as production (`Config.php:73`). The security-review skill could not be invoked; red-team reviewed by hand.
- **popia-data-protection:** franchise emails visible to head office: accept as lawful-purpose access (confirm the basis, POPIA s11(1)(f) [Inference]). MEDIUM: deactivated users keep email and hash for good; fix is to anonymise (foreign keys block deletion); needs a retention period and an owner. LOW: new-password message stays on screen (`accounts.js:179-183`); no audit line on deactivate/reactivate (`AccountService.php:109-125`).
- **compliance-gate:** LOW: the review commit e02ce54 also tightened the `sync-bundle.php` skip list (adds skips only). LOW: the spec file itself (s13 step 4, lines 574-580) names a staff first name; use the role instead. Not changed (your file).

## 5. Deviations and BLOCKED
- **Push BLOCKED:** `guard.ps1` [DEPLOY-GATE] blocked the push of this branch (final, not retried; decision-gate: log and continue). All work is committed locally. **Push by hand first.**
- **V3 cost report NOT COMPUTED:** the read-only guard blocked its scratch script. No token, time or model figures are claimed. 123 text matches of "DEPLOY-GATE" in the logs are not block events.
- A3 (`git rm -r`) replaced by A3b: `deploy/sync-bundle.php` copies, never deletes, prints `EXTRA <path>` and exits 1 on strays. Skip list: `config.local.php`, `.env`, `.env.*`, `tests`, `*.sqlite`, `*.sqlite-wal`, `*.sqlite-shm`, `*.db-journal`, `*.bak`, symlinks.
- Spec A3b item 3 wanted a header line in `FILE-LIST.txt`; the frozen parity test needs every line to be `<size>  <path>`, so the header prints to the console only (as `build-bundle.ps1` does).
- CLAUDE.md: the bundle rule now says run `php deploy/sync-bundle.php` (one line).
- Red-team's test commit sits on branch `worktree-agent-a7587b12345fa60cf` (not merged); worktrees and branches were never deleted.

## 6. Server change set (upload copy vs `main`): 16 A, 3 M, 0 D
Added (16): `bin/load_hub_list.php`; `public/accounts.html`, `ingredients.html`, `stock.html`, `warehouse.html`; `public/assets/accounts.js`, `ingredients.js`, `stock.js`, `warehouse.js`, `warehouse.css`; `src/Modules/Accounts/{AccountController,AccountService,routes}.php`; `src/Modules/Warehouse/{QueueController,QueueService,routes}.php`.
Modified (3): `public/index.html`, `public/assets/app.js`, `public/assets/app.css`.
Deleted (0). Untouched: `config.local.php`, `var/`. `deploy/FILE-LIST.txt` also changes but is not uploaded.

## 7. Merge counts
This session: expected 4 task returns (A3b, F-R1, F-R2, V1b), got 4. Earlier gap (lane A1 merged then reverted) is closed: the parity test is restored.

## 8. Cost and time
NOT COMPUTED (s5). Subagents used this session: about 14. Guard blocks: 2 push blocks, 1 false positive on the words "git push" inside a heredoc, 1 read-only-guard block on V3.

## 9. Waiting list
1. The real ingredient CSV (data drop). On the server: `load_hub_list.php <file> --dry-run --deactivate-samples`, read it, then run it for real. Lines without a price stay off.
2. The hub user's opening stock count.
3. Retention period and owner for deactivated accounts, then an approved build spec for the anonymise step.

## 10. For Damian (attended)
Decisions:
1. Login timing oracle in frozen `src/Auth/Auth.php`: allow a fix (verify against one fixed dummy hash, drop the `password_hash` call)? Test is waiting in `tests/known-issues/`.
2. Loader unit change on a KEPT row (keeps old cost): refuse the change, or switch the row off?
3. J8: confirm a hub user may not cancel its own pending order (the test has head office cancelling).
4. Retention period and owner for deactivated accounts.
Then: push the branch by hand; "merge"; `ship hub -DryRun` (expect 19 uploads, 0 deletions, `config.local.php` and `var/` untouched); `ship hub`; check `/api/health` and `warehouse.html` return 200.

## 11. Final task board
Green: W0.1-W0.4, A1, A2, A3b, B1-B4, C1-C3, E1, E2.0-E2.2, E3, F1, F-R1, F-R2, M1, M2, N2, N2b, R1-R4, G2, G3, V1, V2. Not computed: V3. Blocked by guard: P1 push (by hand). Open decisions: 4 (above).

## 12. Decisions received 19 Nov 2026 (Damian)
1. Login timing hole and login rate limit: fixed today in a separate hardening build, not in this build.
2. Loader unit change on a kept row: fixed in the hardening build (refuse the row and report it).
3. Cancel rule confirmed: only head office cancels orders; Hub users cannot.
4. Accounts: deactivate, never delete. Retention period and owner to be set by Clyde.
Sections 10 and 11 above are unchanged; items 1-4 of section 10 are now answered here.
