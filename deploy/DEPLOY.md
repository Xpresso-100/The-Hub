# Deploying The Hub (xneelo, upload only)

Method: the same as the Xpresso ordering system (`DEPLOY_RUNBOOK.md` there): a person uploads files with the
**xneelo File Manager** and runs SQL in **phpMyAdmin**. No SSH, no Docker, no command line on the server.
The app lives in its own folder, `public_html/hub/`, and is opened at `https://<your-domain>/hub/public/`.

The bundle is the folder `deploy/upload/hub/` (65 files, 199,241 bytes; the full list with byte sizes is
`deploy/FILE-LIST.txt`). It holds no passwords and no keys.

## Before you start (have these ready)
- A new, empty MySQL database and its user, made on the xneelo MySQL page (name, user, password, host).
- The FinCon values listed in section 3 (ask whoever runs the FinCon tunnel; they are the same ones the ordering system uses).
- Two login hashes from the developer (one HQ user, one Hub user). Staff passwords never go into any file; the
  developer makes a hash with `php bin/hash_password.php`, and you paste only the hash.

## 1. Upload steps
1. In the xneelo control panel open **File Manager** and go to `public_html/`.
2. Create a folder called `hub`. Open it.
3. Upload the **contents** of `deploy/upload/hub/` into `public_html/hub/`, keeping the sub-folders
   (`public`, `src`, `db`, `bin`, `var`). If an old copy exists, **delete the old file first**: uploading over a file
   creates `name(1).php` and leaves the old one live. Dotfiles (`.htaccess`) are hidden by default: switch on
   "show hidden files" and check that `hub/.htaccess`, `hub/public/.htaccess`, `hub/src/.htaccess`, `hub/db/.htaccess`,
   `hub/bin/.htaccess` and `hub/var/.htaccess` are all there. They are what keep the code and settings private.
4. Compare file names and byte sizes with `deploy/FILE-LIST.txt` for the PHP files you uploaded.
5. In `public_html/hub/` rename `config.local.php.TEMPLATE` to `config.local.php`, open it in the editor and replace
   every `CHANGE-ME` with the real value (section 3). Save. Do not keep a copy anywhere else.
6. In **phpMyAdmin**, pick the new database, then:
   - **Import** tab: choose `deploy/sql/01-create-tables.sql`, run. (If the import errors with #1109, use the **SQL** tab and paste
     the file one `CREATE TABLE` statement at a time.) It is safe to run twice.
   - Optional: the same for `deploy/sql/02-sample-data.sql` (three SAMPLE recipes and six SAMPLE ingredients, all marked SAMPLE).
     Real recipes and ingredients are loaded later. Only run it once.
   - Copy `deploy/sql/03-create-first-users.sql.TEMPLATE` to `03-create-first-users.sql`, replace each `<...>` (the `<HASH>` values come
     from the developer) and run it in the **SQL** tab **one statement at a time**.
7. PHP changes can be cached by the server. In xneelo **PHP Config**, switch the PHP version, save, switch back, save.
   Use PHP 8.3 (the version the app was built and tested on). The `pdo_mysql` extension must be on.
8. Open `https://<your-domain>/hub/public/` and run the 3 checks below.

Updating later: upload only the changed files (delete old file first), then do step 7.

## 2. What must never be uploaded
`.env` files, `tests/`, `.git/`, `.pipeline/`, and any real `config.local.php` copy from someone's machine. They are not in the bundle.

## 3. Settings the server needs (all in `config.local.php`)
| Setting | Plain English |
|---|---|
| `APP_ENV` | Leave as `production`. In this mode the app refuses to use fake FinCon data. |
| `DB_DRIVER` | Leave as `mysql`. |
| `DB_HOST` | Where the database lives. Usually `localhost` on xneelo; use what the xneelo MySQL page shows. |
| `DB_PORT` | Database port. Leave `3306` unless xneelo says otherwise. |
| `DB_NAME` | Name of the new Hub database (not the ordering system's database). |
| `DB_USER` | The database user for that database. |
| `DB_PASS` | That user's password. |
| `FINCON_ADAPTER` | Leave as `http` (talk to the real FinCon). `mock` is refused in production. |
| `FINCON_BASE_URL` | **The FinCon URL**: the web address of the FinCon tunnel, the same address the ordering system uses (starts `https://`). |
| `FINCON_CF_CLIENT_ID` | **The FinCon key, part 1**: Cloudflare Access service-token ID. It lets the Hub through the tunnel's front door. |
| `FINCON_CF_CLIENT_SECRET` | **The FinCon key, part 2**: the matching Cloudflare Access service-token secret. |
| `FINCON_SRV_USER` / `FINCON_SRV_PASS` | The username and password of FinCon's own web server. |
| `FINCON_DATA_ID` | Which FinCon company data set to open. |
| `FINCON_USER` / `FINCON_PASS` | The FinCon login the Hub signs in with. Give it a read-only FinCon user if one can be made. |
| `FINCON_LOC_NO` | Which FinCon stock location to read. `0` = the default. |
| `FINCON_TIMEOUT_SECONDS` | How long to wait for FinCon before giving up. `15` is fine. |
| `STOCK_CACHE_TTL_SECONDS` | How long a stock reading is reused, in seconds. `900` = 15 minutes. |
| `MAIL_TRANSPORT` | Leave as `mail` (the server's own email sending, same as the ordering system). |
| `MAIL_FROM` | The "from" address on order emails. Use an address on your own domain so it is not rejected as spam. |
| `HQ_ORDERS_EMAIL` | Where Hub orders are emailed. If empty, orders are still saved but the email is recorded as failed. |
| `AUTH_TOKEN_TTL_HOURS` | How long a login lasts. `8` hours. |

The Hub only **reads** from FinCon; it never writes to it. FinCon is the ordering system's tunnel: do not change it for this.

## 4. The 3 checks after upload
1. **The app is alive.** Open `https://<your-domain>/hub/public/api/health`. You must see `{"success":true,"status":"ok"}`.
   A blank page or a 500 error means the PHP files, the `.htaccess` files or step 7 are wrong.
2. **Login and FinCon work.** Open `https://<your-domain>/hub/public/`, log in as the Hub user, open **Stock & ordering**.
   The stock list must say **"Live from FinCon"**. If it says "FinCon unavailable (...)", a FinCon setting is wrong; if login fails,
   check the database settings and that script 03 ran.
3. **The private files are private.** Each of these must show **403 Forbidden** (or 404), never code or text:
   `https://<your-domain>/hub/config.local.php`, `https://<your-domain>/hub/src/Config.php`, `https://<your-domain>/hub/db/schema.mysql.sql`.
   If any shows content, stop and delete that file from the server, then recheck the `.htaccess` files from step 3.

## 5. Things to know
- `public_html/hub/` also holds `bin/`, which is only for the developer's command-line use and is blocked from the web.
- Backups: before running any later SQL, export the Hub tables in phpMyAdmin first.
- Sample data is labelled SAMPLE. Real recipes arrive from Jolandi (locked assumption A3).
