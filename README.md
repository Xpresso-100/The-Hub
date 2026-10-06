# The Hub

Xpresso's central production kitchen app (Phase 1). The Hub orders ingredients from Xpresso HQ **at cost**,
records production batches that consume those ingredients by recipe, and reports finished goods to HQ.
Finished food goes into HQ stock; stores order it from HQ as normal. **Phase 1 never writes to FinCon.**

Contract: `SPEC.md`. Locked business rules: `SPEC-locked-decisions.md`. Assumptions: `DECISIONS-LOG.md`.
Reference system findings: `CODEBASE_MAP.md`. Shared PHP API for modules: `docs/CORE_API.md`.

## Stack

Plain PHP 8.3 (8.1+ syntax), no framework, no Composer, no npm. PDO: MySQL/MariaDB in production (xneelo),
SQLite for tests and local dev. Vanilla HTML/JS pages in `public/`. Same stack as the existing ordering system.

## Run locally (Git Bash or PowerShell; SQLite, mock FinCon)

```
APP_ENV=development DB_DRIVER=sqlite FINCON_ADAPTER=mock php bin/migrate.php
APP_ENV=development DB_DRIVER=sqlite FINCON_ADAPTER=mock php bin/seed.php --fixtures
APP_ENV=development DB_DRIVER=sqlite FINCON_ADAPTER=mock php -S 127.0.0.1:8080 -t public public/index.php
```

PowerShell: set each variable first, e.g. `$env:APP_ENV='development'; $env:DB_DRIVER='sqlite'; $env:FINCON_ADAPTER='mock'`.
Open http://127.0.0.1:8080/ and log in with a fixture user from SPEC s5.1 (test-only credentials).

## Tests

```
php tests/run.php            # every suite; ends with "RESULT: <p> passed, <f> failed"; exit 0 only when all pass
php tests/run.php a4_        # only files whose path contains "a4_"
```

Suites: `tests/smoke` (core), `tests/unit/<Module>`, `tests/acceptance` (black-box A1-A7, frozen after G1),
`tests/e2e`, `tests/security`. Each file runs in its own PHP process and starts its own app on a free port
with a fresh SQLite file under `var/test/`. Set `HUB_TEST_KEEP=1` to keep those folders.

## Configuration

Environment variables, else a gitignored `config.local.php` at the repo root
(`<?php return ['DB_DRIVER' => 'mysql', ...];`), else defaults. Every key is listed in `.env.example`
with placeholder values. The app never reads `.env`; never commit real values.

## Layout

| Path | What |
|---|---|
| `public/` | web root: `index.php` front controller, `.htaccess`, HTML pages, `assets/` |
| `src/` | core (`Config`, `Db`, `Money`, `Clock`, `Http/`, `Auth/`, `Fincon/`, `Mail/`) and `Modules/{Ordering,Recipes,Reports}` |
| `db/` | `schema.mysql.sql`, `schema.sqlite.sql`, `seed_samples.sql` (SAMPLE data, A3) |
| `bin/` | `migrate.php`, `seed.php`, `hash_password.php`, `import_ingredients.php` (M1) |
| `tests/` | runner, harness, fake FinCon server, suites |
| `var/` | runtime only (SQLite, logs) - gitignored |

`src/`, `db/`, `bin/`, `tests/` and `var/` each carry a `Require all denied` `.htaccess` in case the whole
folder is uploaded under the web root.
