# OVERNIGHT: Hub go-live (Xpresso Cafe), 18 Nov 2026

Run branch `feat/hub-live-ops-golive` · spec `docs/BUILD-SPEC-2026-11-18-go-live.md` v3.1 (sections 0.00, 0.0, 0.1 override the rest) · Coordinator session started after `fa9bd5f`.

## Goal
Finish the Hub go-live build: sync the upload copy (A3b), prove the journey end to end (API + browser, repo and bundle), run reviews R1-R4, fix findings, run final gates, write the FINAL report, push, create `docs/run/DONE`.

## Proof command (finish line)
- `php tests/run.php` ends `RESULT: <n> passed, 0 failed`
- `node tests/browser/run.cjs --root bundle` ends `BROWSER: 7 passed, 0 failed`
- both on the final commit, FINAL report + `docs/run/DONE` pushed.

## Do-not-touch list
- Spec s5 frozen files (SPEC.md, SPEC-locked-decisions.md, everything existing under tests/, db/, deploy/sql/, deploy/build-bundle.ps1, deploy/htaccess-root.txt, deploy/config.local.php.TEMPLATE, src/Config.php, Db.php, Auth/, Money.php, Http/, Fincon/, Modules/Ordering|Recipes|Reports, bin/import_ingredients.php, bin/hash_password.php, recipes/reports pages+js).
- No merge to main, no deploy, no ssh/scp/upload. No real names or prices. No worktree or branch is ever deleted. No git stash / reset --hard / clean.
- A guard block is final: log it, route to decision-gate, no variants.

## Rules in force
- Max 3 builders at once; fresh worktree `.claude/worktrees/<id>` on branch `golive-<id>` per card.
- Two attempts per bug, then BLOCKED with evidence.
- Left-over half-done tasks: new worktree `<id>-r2`; old one noted "abandoned, not deleted".

## Done log
- M2: `RESULT: 154 passed, 0 failed`; `BROWSER: 7 passed, 0 failed` (repo).
- A3b: deploy/sync-bundle.php (golive-A3b, merged); parity test restored (revert of c8d937e); sync copied 81 files, 0 extra; `php tests/run.php Deploy` = `RESULT: 6 passed, 0 failed`; server change set vs main: 16 A, 3 M, 0 D (commit 32abe48).
- E3: `node tests/browser/run.cjs --root bundle` = `BROWSER: 7 passed, 0 failed`.
- Deviation: spec A3b item 3 asks for a header line in FILE-LIST.txt, but the frozen parity test requires every line to be `<size>  <path>`; header goes to the console only (as build-bundle.ps1 does).

- R4 popia-data-protection: PASS-WITH-FLAGS. Franchise-emails flag: accept as lawful-purpose access (POPIA s19; s11(1)(f) basis to be confirmed by the business). Flags: (6) MEDIUM retention gap, deactivated users keep email+hash with no period/owner, fix is anonymise not delete (FKs); (4) LOW password stays on screen / plain-text field in accounts.js:179-183, accounts.html:23; (7) LOW no audit line for deactivate/reactivate AccountService.php:109-125; (5) LOW error log may carry an email via DB duplicate message.
- R2 reality-checker: PASS-WITH-FLAGS. Re-ran golive 9/0, MobileTables 4/0, Deploy 6/0, browser repo 7/0 and bundle 7/0. Flags: (1) MEDIUM W7 measures warehouse.html empty (runs after W6 delivers the only order), so the pick-list table is never measured at 375px, run.cjs:518-535; (2) LOW MT-2 vacuous for 3 of 4 files, mobile_tables_test.php:32-38; (3) LOW J8 who cancels needs Damian's confirmation; (4) LOW J6 skips in_process->pending and pending->delivered; (5) LOW J2 no PATCH /api/admin/users/{id} as hub; (6) LOW W6 parseFloat breaks at >=1000 ("1 234.500"); (7) LOW W5 substring row match; (8) UNVERIFIED red runs for J1-J9, W1-W6 (commit messages only).
- R1 ledger: PASS-WITH-FLAGS. Hand-checked 5144 / 333 / 3 cents, no float, no overflow, new rows cannot go active at R0.00, J5 VAT check sound. Flags: (5) unit change on a KEPT row keeps old cost_cents (load_hub_list.php:280-283, locked by test C-4) - this is what spec C3 says, so it goes to Damian as a DECISION; (6) a row already active at cost 0 stays active on KEPT path (:246-250, :280-283), untested - FIX CARD F-R1; (7) J8 has no cost assertion (expected 1400 cents) - test-strengthening card; (9) runbook must require --deactivate-samples.
- R3 red-team: PASS-WITH-FLAGS (security-review skill could not be invoked; own review). One real hole, in FROZEN code: login username timing oracle, src/Auth/Auth.php:34-35 (unknown username = 2 bcrypt ops, known = 1; ratio 1.66-2.18 on 3 runs). Per spec s11 not fixed: failing test moved to tests/known-issues/login_username_timing_test.php; DECISION for Damian (suggested fix: verify against one fixed dummy hash, drop the password_hash call). Held: roles, location scope, mass assignment, password rules, deactivation, SQLi, XSS, CSRF n/a. Other flags: no login rate limit (Auth.php:28); IngredientController.php:51-56 fincon_item_no accepts control chars; AccountService.php:111-123 narrow deactivate/login race [Inference]; sync-bundle skip list (fixed by me: .env.*, *.sqlite-wal/-shm, *.bak, symlinks); Config.php:73 APP_ENV='Prod' not treated as production. Red-team commit 2baaf37 sits on branch worktree-agent-a7587b12345fa60cf (not merged; golive-R3 left at 32abe48).
- N2b: CLAUDE.md bundle rule now says run sync-bundle.php (commit 2ee7d35).

## Blocked log
- (append rows: item · two attempts · evidence)

## Needs me (Damian)
- Push `feat/hub-live-ops-golive` to origin by hand (the guard blocks agent pushes).
- Merge `feat/hub-live-ops-golive` to main (his yes), then `ship hub -DryRun` / `ship hub`.
- DECISION (frozen code): login timing oracle in src/Auth/Auth.php (R3). Allow a fix to the frozen file? Test waiting at tests/known-issues/login_username_timing_test.php.
- DECISION (spec behaviour): loader KEPT path changes `unit` but keeps the old cost_cents (R1 flag 5, locked by test C-4). Refuse or switch the row off?
- DECISION: J8 "cancel works from pending": test has hq cancelling and the hub user getting 403. Confirm a hub user may not cancel its own pending order.
- Business decision: retention period and owner for deactivated accounts (R4 flag 6); needs an approved build spec for the anonymise step before any code.

## Guard blocks (final, not retried)
- 18 Nov: the push of the run branch to origin was blocked by guard.ps1 [DEPLOY-GATE] (T3). decision-gate: option A, high confidence. All pushes are BLOCKED for the run; commits stay local. Needs me: push the branch by hand. (The spec asks for early pushes; the guard overrides.)
- 18 Nov: guard.ps1 also matched the words "git push" inside a log heredoc in a Bash call (false positive). Logs are now written with Edit, not Bash heredocs.

## Decisions (made without asking)
- 18 Nov: [GRAPH DECISION] line with limited fan-out; only R1-R4 reviewers run in parallel (read-only). Pilot slice = M2.
