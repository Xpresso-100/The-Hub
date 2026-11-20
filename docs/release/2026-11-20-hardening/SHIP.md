# Ship pack - Hub hardening wave 1 (2026-11-20)

FOR INFO / operator steps. Not ready to ship until REPORT.md's open items are closed (docs/night/2026-11-19/REPORT.md).

Branch: feat/hub-hardening-1. Live base: f667b8d. Verify: `RESULT: 202 passed, 0 failed` on 1ca7f26.

## Order (the operator runs every step)
1. Merge feat/hub-hardening-1 to main after review (operator's yes).
2. phpMyAdmin: export a backup of the Hub database.
3. phpMyAdmin: run `deploy/sql/04-hardening.sql` (one statement: `CREATE TABLE IF NOT EXISTS login_attempts ...`). Run it BEFORE the upload; without the table the login lockout is off (fails open).
4. `ship hub -DryRun` and check the file list.
5. `ship hub` (type YES at its prompt).
6. Post-ship checks (deploy/DEPLOY.md section 4):
   - `https://xpressocafe.co.za/hub/public/api/health` returns ok
   - one login works
   - config.local.php, src/Config.php and db/schema.mysql.sql answer 403

## Rollback
- Files: `ship hub -Rollback <BACKUPNAME>` (the name ship printed).
- Database: `DROP TABLE login_attempts;` (only table added; no other data changed).

## What changes for users
- Logins: 5 failed attempts in 15 minutes lock that username for 15 minutes. Stored: username hash + time only, no IP.
- Hub list loader refuses a unit change on an existing ingredient and control characters in text.
- Restyled pages (CSS only, plus class names in ordering.js and warehouse.js).
