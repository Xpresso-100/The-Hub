# Lane notes, 18 Nov 2026 (Coordinator)

## A2: Deploy parity test, red output on the stale upload copy (golive-A1, 3613f39)

`php .claude/worktrees/A1/tests/run.php Deploy` (builder run, worktree A1):

- FAIL `A1: bundle public, src, db and bin hold the same files as the repo, with the same SHA-1`: 17 problems: 14 files missing (incl. public/accounts.html, public/warehouse.html, src/Modules/Warehouse/QueueController.php, QueueService.php, routes.php), 2 files different (public/assets/app.js, public/index.html), 0 extra.
- FAIL (f) FILE-LIST.txt: all 40 listed sizes are smaller than the files on disk (e.g. `.htaccess` listed 417, on disk 427). [Inference] The list was built from LF files; this checkout has core.autocrlf=true, so files are CRLF on disk. A rebuild from the same checkout writes sizes that match.
- PASS (b) root .htaccess, (c) var/.htaccess, (d) config.local.php.TEMPLATE, (e) no config.local.php, .env or tests/.
- Result: `RESULT: 4 passed, 2 failed`.

Known issue (not auto-run): `tests/known-issues/deploy_filelist_count_test.php` is red. Line 1 of FILE-LIST.txt is "417  .htaccess", not a count, because the frozen build-bundle.ps1 prints the count to stdout only.

## A3: BLOCKED

`git rm -r -q deploy/upload/hub` → guard.ps1 `[DESTRUCTIVE-GUARD] Blocked: rm with -r/-f (recursive/force delete)`. decision-gate → harness-engineer: keep it a human step. The A1 merge (789765e) was reverted (c8d937e) under spec §0.1 rule 5.
