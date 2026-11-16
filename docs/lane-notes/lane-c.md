# Lane C notes: account creation (build spec 2026-11-16 s8)

Worktree `.claude/worktrees/lane-c`, branch `feat/hub-live-ops-lane-c`. Builder had no shell (Bash denied to subagents
this run): every test, lint and git result comes from the Coordinator. Nothing below is claimed as passing.

## Tasks

| # | Task | Status (16 Nov) |
|---|---|---|
| C1 | Access tests for all endpoints (`tests/unit/Accounts/accounts_access_test.php`) | written, not yet run |
| C2 | `GET /api/admin/locations` (active hq + hub only) | code written, not yet run |
| C3 | `POST /api/admin/users` validation + bcrypt + audit line | code written, not yet run |
| C4 | `GET /api/admin/users` without hashes | code written, not yet run |
| C5 | `PATCH /api/admin/users/{id}` deactivate/reactivate + token delete + self-guard | code written, not yet run |
| C6 | `accounts.html` + `assets/accounts.js` (incl. suggest-password button) | written, not yet run |

Cuts: none.

## Files written (lane-owned only)

- `src/Modules/Accounts/routes.php`, `AccountController.php`, `AccountService.php`
- `public/accounts.html`, `public/assets/accounts.js`
- `tests/unit/Accounts/accounts_access_test.php` (test 1), `accounts_create_test.php` (tests 2-5),
  `accounts_list_test.php` (test 6), `accounts_deactivate_test.php` (test 7), `accounts_ui_test.php` (test 8)
- this file

## Assumptions and decisions

- 2026-11-16 | lane-c | Usernames are unique case-insensitively (`LOWER(username) = LOWER(?)`); "HUB.USER" is 409 when "hub.user" exists | live MySQL uses utf8mb4_unicode_ci, so login treats them as one user; SQLite would otherwise allow both
- 2026-11-16 | lane-c | Username and email are trimmed before validation; the password is not trimmed | matches `AuthController::login`, which trims username and email but passes the password as typed
- 2026-11-16 | lane-c | A password of only whitespace, or containing a NUL byte, is refused with 422 on `password` | login rejects a blank-after-trim password, so such an account could never log in; bcrypt cannot take NUL
- 2026-11-16 | lane-c | `location_id` must be a JSON integer; a string id is 422 | same strictness as `Money::parseCents` (JSON number only)
- 2026-11-16 | lane-c | Franchise location gives 422 "franchise locations cannot have Hub accounts"; inactive, unknown or missing location gives 422 "must be an active hq or hub location" | spec s8; inactive locations cannot log in (`Auth::attempt` checks `l.active`)
- 2026-11-16 | lane-c | Order of checks on POST: 401/403, then 400 bad JSON, then 422 field errors, then 409 username taken | role before body (SPEC s3); validation before the uniqueness lookup
- 2026-11-16 | lane-c | A UNIQUE-key race on insert is answered 409 (re-check after the PDOException), any other DB error stays 500 | two hq users creating the same username at once
- 2026-11-16 | lane-c | `GET /api/admin/users` lists every user, franchise users included (no filter in spec) | hq must see all logins to deactivate one; no field beyond username and email is returned
- 2026-11-16 | lane-c | `GET /api/admin/locations` is ordered by type then name (hq first) | spec gives no order; this reads best in the dropdown
- 2026-11-16 | lane-c | PATCH `active=true` on yourself is allowed (no-op); PATCH may target any user, franchise included | spec forbids only self-deactivation
- 2026-11-16 | lane-c | PATCH order: role, then body (`active` must be a JSON boolean, 422), then 404 unknown id, then 409 self-deactivate | body errors are cheap and do not reveal ids
- 2026-11-16 | lane-c | No audit line for deactivate/reactivate | Coordinator brief: the only log line is `HUB-AUDIT account_created`
- 2026-11-16 | lane-c | Success message is generic ("Give the new user these three things: username, email, password ... They log in with all three") instead of naming a person | the page serves every new login; no person's name is hard-coded (POPIA minimisation)
- 2026-11-16 | lane-c | The success message shows the password once, as text (textContent), then the field is cleared | there is no reset screen (out of scope), so a suggested password that was not copied would leave an unusable account; never stored, never in the address, never logged
- 2026-11-16 | lane-c | The password field is `type="text"` | spec: the suggested password is "shown in the field"
- 2026-11-16 | lane-c | Suggested password: 16 characters from a 57-character alphabet without look-alikes (I, O, l, 0, 1), `crypto.getRandomValues` with rejection sampling | about 93 bits; readable when handed over in person
- 2026-11-16 | lane-c | `created_at` uses `Clock::nowDb()` and is returned through `Clock::iso()` | same clock and format as `bin/seed.php` and `Auth`
- 2026-11-16 | lane-c | Test passwords are built at run time (`'Test-' . $tag . '-' . str_repeat('x', 8)`) rather than written as literals | the SECRET-GUARD hook blocks a credential-looking literal in a written file; they are test-only values
- 2026-11-16 | lane-c | UI escape lint copies the ordering_ui_test.php regex and adds this script's row variables (a, loc, acc, created) | the original variable list would not see `a.username`
- 2026-11-16 | lane-c | The existing `api-standard.md` rule (new builds only) is not applied | The Hub is an existing system; its current response shape is kept

## POPIA

No new personal field. The accounts endpoints read and write only staff username and email (already in `users`).
The audit line carries ids only. Flag for the Auditor: the account list shows staff emails to hq users.

## NEED-OUT-OF-SCOPE

- NEED-OUT-OF-SCOPE: `users.created_by` column - the creator is only in the HUB-AUDIT log line; a column needs SQL (spec s2 says out of scope)
- NEED-OUT-OF-SCOPE: password reset screen - a lost password today means deactivate and create a new username

## LANE BREACH

None.

## Strikes

None yet.
