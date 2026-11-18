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
- (append rows: time · item · evidence)

## Blocked log
- (append rows: item · two attempts · evidence)

## Needs me (Damian)
- Merge `feat/hub-live-ops-golive` to main (his yes), then `ship hub -DryRun` / `ship hub`.

## Decisions (made without asking)
- 18 Nov: [GRAPH DECISION] line with limited fan-out; only R1-R4 reviewers run in parallel (read-only). Pilot slice = M2.
