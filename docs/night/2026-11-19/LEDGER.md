# LEDGER - overnight run 2026-11-19 (The Hub, feat/hub-hardening-1)

Status values: todo / done / blocked / deferred. Evidence = one line with a path or commit.

| Task | Status | Evidence |
|---|---|---|
| T-01 cherry-pick golive-F2, F3, F4 | done (pre-existing) | already applied as 950245d, f4c093b, 10d527b; `git cherry` shows `-` (proof/T01-T08.md) |
| T-02 cherry-pick 2baaf37 (timing test, red) | done (pre-existing) | applied as 86d0224; red at 2baaf37: ratio 1.77, RESULT 0 passed, 1 failed (proof/T01-T08.md) |
| T-03 login timing fix | done (pre-existing) | 2323fac; R3-SEC-01 PASS in the full run on bd295dd |
| T-04 failed-login limit | done (pre-existing) | 2323fac, 3e1d6a5, 085160f; L2-01 to L2-12 red at a9c8195, green on HEAD; deploy/sql/04-hardening.sql (1 statement) |
| T-05 loader refuses unit change | done (pre-existing) | 2323fac; C3-01 to C3-04, C3-06 and C3-07 red at a9c8195, green on HEAD |
| T-06 refuse control characters | done (pre-existing) | 2323fac; C4-01 to C4-04 and C4-06 red at a9c8195, green on HEAD |
| T-07 APP_ENV any-case production | done (pre-existing) | 2323fac; C5-01, C5-04 and C5-05 red at a9c8195, green on HEAD |
| T-08 password shown once | done (pre-existing behaviour) | live since f667b8d; tests never red, so a mutant was used: C6-01 red (proof/T01-T08.md) |
| T-09 end-to-end browser test | todo | |
| T-10 UI tokens (DESIGN.md, KIT.md) | todo | |
| T-11 restyle | todo | |
| T-12 gates on final head | todo | |
| T-13 wave 2 spec draft | done (pre-existing) | 0ce7e66 docs/SPEC-wave2-DRAFT.md: all 4 items, each with SPEC rule, cost of not doing it, and questions for Jolandi/Clyde; header line 1 |
| T-14 ship pack + REPORT.md | todo | |