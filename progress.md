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

## 2026-10-06 - G1 SCAFFOLD + ACCEPTANCE TESTS (done)
What I did:
- Core (SPEC s2.4, now FROZEN): public/index.php front controller + .htaccess files, src/bootstrap.php, Config, Db (PDO mysql|sqlite), Money (s3.1 rounding), Clock, Http/{Request,Response,Router,HttpError}, Auth (login/logout/me, X-Auth-Token, sha256-stored tokens, role = location type), Fincon (read-only interface, mock, HTTP adapter with the reference tunnel protocol and a function allowlist, factory refusing mock in production), Mail (log + PHP mail() transports, MailGuard), public login/landing pages, assets/app.js + app.css.
- db/schema.mysql.sql + db/schema.sqlite.sql for ALL modules (s4), db/seed_samples.sql (3 SAMPLE recipes on 6 SAMPLE ingredients), bin/migrate.php, bin/seed.php (--samples/--fixtures, fixtures refused in production), bin/hash_password.php.
- Tests: tests/run.php (one process per file, "RESULT: p passed, f failed"), tests/lib/harness.php (fresh app per test, API client, fake FinCon, assertions), tests/support/fake_fincon.php.
- Smoke (tests/smoke, 18 tests): app starts, pages served, fixture logins, 404/405, auth contract, Money, FinCon adapters incl. failure mapping, mailer, CLI. All pass: RESULT 18 passed, 0 failed.
- Acceptance (tests/acceptance, 7 files, 19 tests, black-box over HTTP): A1-A7 of SPEC s12 plus s11 HQ notification, FinCon-down fail-open, location_id-ignored scope checks. All fail now with 404 (modules not built), as expected.
- Validated the frozen tests: an independent throwaway implementation of M1-M3 (built by a separate agent in C:\dev\hub-g1-proto, outside the repo, never committed) passes the full suite: RESULT 37 passed, 0 failed. Four injected defects (hub reads another location's order by id, franchise lists recipes, batch line cost off by a cent, hub order list wrong scope) each turned the matching acceptance test red.
- docs/CORE_API.md (exact PHP API modules call), README.md, .env.example (placeholders only), .gitignore (.env, config.local.php, var/, vendor/, node_modules/, build/, dist/, .run/), .pipeline/test-cmd.txt = `php tests/run.php`.
- DECISIONS-LOG.md: 10 g1 lines.

What is next:
- G2: build M1-ordering, M2-recipes, M3-reports in parallel, only inside the SPEC s2.4 file lists, against docs/CORE_API.md. Module unit tests go in tests/unit/<Module>/. Run one file with `php tests/run.php <substring>`.
- Human housekeeping: the scratch folders C:\dev\hub-g1-proto and C:\dev\hub-g1-check (outside the repo) can be deleted.
