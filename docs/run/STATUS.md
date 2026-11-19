# Run status: Hub go-live (BUILD-SPEC-2026-11-18-go-live v2.1)

Run branch `feat/hub-live-ops-golive` · base `c9bddf7` · started 18 Nov 2026 15:35 SAST · hard stop 01:05 SAST (9 h 30 min)

**Flags (top):**
- **A3 BLOCKED, human step:** guard.ps1 [DESTRUCTIVE-GUARD] blocked `git rm -r -q deploy/upload/hub`. The upload copy is NOT rebuilt in this run. The A1 parity-test merge was reverted (`c8d937e`) to keep the branch green; the test is intact on `golive-A1` (`3613f39`). Damian runs A3 (see the report).
- **Merge count gap:** M1 expected 4 lane merges (B, C, A1, E). Got 3 live (B, C, E = E2 + E1); A1 merged and then reverted.
- D2: forge cards re-dispatched to general-purpose (forge is worktree-isolated by charter).
- D3: browser uses installed Chromium 1234 (Playwright 1.63-alpha expects 1237; no installs).

| task | agent | state | last test line | min |
|---|---|---|---|---|
| W0.1 preflight | Coordinator | green | PHP 8.3.32; origin/main 1851d9d | 2 |
| W0.2 branch + spec | Coordinator | green | ac6ada2 | 1 |
| W0.3 baseline | Coordinator | green | RESULT: 130 passed, 0 failed (1st run: A2 load flake, passed alone 2/0) | 20 |
| W0.4 status, .gitignore, 16 Nov wording | Coordinator | green | .gitignore narrow edit a2d468c | 5 |
| A1 parity test | general-purpose | green (red as designed) | RESULT: 4 passed, 2 failed on the stale copy | 3 |
| A2 lane note | Coordinator | green | in docs/lane-notes/2026-11-18-coordinator.md | 1 |
| B1 table-scroll | draft | green | MobileTables 4/0 | 9 |
| B2 Nothing waiting | draft | green | MT-4 PASS | |
| B3 mobile tables test | draft | green | 0/4 red -> 4/0 | |
| B4 node --check | Coordinator | green | 8 of 8 files exit 0 | 1 |
| C1 loader tests | general-purpose | green | red: "Could not open input file" (11 failed) | 11 |
| C2 parse + validate | general-purpose | green | | |
| C3 write path | general-purpose | green | HubListLoader RESULT: 11 passed, 0 failed | |
| E1 API journeys | eval-engineer | green | RESULT: 9 passed, 0 failed (never seen red) | 3 |
| E2.0 Playwright pilot | test-engineer / Coordinator | green | resolved under @playwright/cli; Chromium 1234 | |
| E2.1 serve.php | test-engineer | green | php -l clean | 7 |
| E2.2 run.cjs | test-engineer | green | BROWSER: 7 passed, 0 failed (repo) | |
| M1 merges | Coordinator | green (gap) | B, E2, C, A1 (reverted), N2, E1 | |
| M2 full suite + browser | Coordinator | running | | |
| N2 CLAUDE.md rule | context-curator | green | +1 line under Deploying | 1 |
| R1 ledger | ledger | running | | |
| R2 reality-checker | reality-checker | running | | |
| R3 red-team | red-team | todo | | |
| R4 POPIA | popia-data-protection | running | | |
| F1 browser binary fallback | test-engineer | green | BROWSER-BINARY: chromium_headless_shell-1234 | 1 |
| A3 rebuild upload copy | Damian | blocked | DESTRUCTIVE-GUARD | |
| E3 browser on bundle | Damian (after A3) | blocked | depends on A3 | |
| G2 frozen check | Coordinator | green | both diffs empty | 1 |
| G3 all gates | Coordinator | running | | |
| V1 live-verifier | live-verifier | todo | | |
| V2 compliance-gate | compliance-gate | todo | | |
| V3 cost | trace-cost-analyst | todo | | |
| P1 push + report | Coordinator | todo | | |

Merge counts: lane merges expected 4, got 3 kept + 1 reverted (A1). Reviews expected 4 (R1-R4).

## Night run update (19 Nov session, Coordinator)
- Push is BLOCKED by guard.ps1 [DEPLOY-GATE]; commits stay local; "push branch" is in OVERNIGHT.md Needs-me.
- A3 superseded by A3b: deploy/sync-bundle.php merged; parity test restored; bundle synced (81 files, 0 extra); change set vs main 16 A, 3 M, 0 D. Deploy: 6 passed, 0 failed.
- M2 green (154 passed, 0 failed; BROWSER 7/0 repo). E3 green (BROWSER 7/0 bundle).
- R1 ledger, R2 reality-checker, R3 red-team, R4 popia: all PASS-WITH-FLAGS (details in OVERNIGHT.md).
- F-R1 (loader never leaves R0.00 active; HubListLoader 15/0) and F-R2 (W7 populated warehouse; J8 total) merged. Login timing oracle in frozen src/Auth/Auth.php moved to tests/known-issues as a DECISION.
- G2 green (both frozen diffs empty). G3 running. V1, V2, V3, report, DONE still to do.
