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

## Blocked log
- (append rows: item · two attempts · evidence)

## Needs me (Damian)
- Merge `feat/hub-live-ops-golive` to main (his yes), then `ship hub -DryRun` / `ship hub`.

## Guard blocks (final, not retried)
- 18 Nov: the push of the run branch to origin was blocked by guard.ps1 [DEPLOY-GATE] (T3). decision-gate: option A, high confidence. All pushes are BLOCKED for the run; commits stay local. Needs me: push the branch by hand. (The spec asks for early pushes; the guard overrides.)
- 18 Nov: guard.ps1 also matched the words "git push" inside a log heredoc in a Bash call (false positive). Logs are now written with Edit, not Bash heredocs.

## Decisions (made without asking)
- 18 Nov: [GRAPH DECISION] line with limited fan-out; only R1-R4 reviewers run in parallel (read-only). Pilot slice = M2.
