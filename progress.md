# progress.md - The Hub pipeline

## 2026-10-06 - G0 DISCOVER (done)
What I did:
- Studied the reference ordering system (C:\dev\ordering-ref) read-only: stack, login/roles, order flow, FinCon tunnel read path and auth layers, price sources, dev and deploy method.
- Wrote CODEBASE_MAP.md (findings with file:line evidence).
- Wrote SPEC.md: stack, config keys, commands, test-harness contract, file ownership per module, data model for all modules, fixtures and SAMPLE data, hand-calculated values, public HTTP API for core/M1/M2/M3, FinCon read-only interface (mock, HTTP adapter, fake server), integration method, acceptance checks A1-A7, E2E flow.
- Wrote feature_list.json (31 features: core C-01..C-10, M1-01..M1-10, M2-01..M2-06, M3-01..M3-05), all passes=false.
- Started DECISIONS-LOG.md with every assumption so far.
- No application code written.

What is next:
- G1: scaffold the core in SPEC.md s2.4 (frozen afterwards), schema for all modules (SQLite + MySQL), seeds, auth, FinCon interface + mock + HTTP adapter + fake server, mailer, test runner, smoke test, black-box acceptance tests A1-A7 (SPEC s12), docs/CORE_API.md, and .pipeline/test-cmd.txt = `php tests/run.php`.
- Then G2 builds M1-ordering, M2-recipes, M3-reports in parallel, each only inside its own file list.
