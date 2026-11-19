# Lane A: warehouse queue: lane notes

Build spec: `docs/BUILD-SPEC-2026-11-16-live-ops.md` s6. Worktree `.claude/worktrees/lane-a`, branch `feat/hub-live-ops-lane-a`.
Builder had no shell (Bash and PowerShell denied to subagents in this run). Nothing below has been run or linted by the builder; the Coordinator runs `php tests/run.php Warehouse` and `php -l`.

## Tasks

| # | Task | Status (builder view; tests decide) |
|---|---|---|
| A1 | Access tests 401/403/200 | written: `tests/unit/Warehouse/queue_access_test.php` |
| A2 | `routes.php` + `QueueController::show` with `requireRole('hq')` | written, not yet run |
| A3 | `QueueService` open-order query, s6 ordering | written, not yet run |
| A4 | Lines in one query + latest email status | written, not yet run |
| A5 | Pick list sum and `counts` | written, not yet run |
| A6 | `warehouse.html` + `warehouse.js` list, counts, pick list | written, not yet run |
| A7 | Forward buttons, delivered confirm, 409 handling | written; manual read pending |
| A8 | Auto-refresh, title count, print css | written, not yet run |

## Files (all lane-owned)

- `src/Modules/Warehouse/routes.php`, `QueueController.php`, `QueueService.php`
- `public/warehouse.html`, `public/assets/warehouse.js`, `public/assets/warehouse.css`
- `tests/unit/Warehouse/wh_helpers.php` (shared helpers; not a `_test.php`, so the runner does not run it)
- `tests/unit/Warehouse/queue_access_test.php` (test 1), `queue_selection_test.php` (tests 2, 3), `queue_picklist_test.php` (tests 4, 5), `queue_safety_test.php` (tests 6, 7), `warehouse_ui_test.php` (test 8)

## Assumptions

- 2026-11-16 | lane-a | Pick list name and unit come from the line on the order with the highest id | ids are auto-increment, so the highest id is the newest order; avoids a second sort on created_at, which can tie within one second
- 2026-11-16 | lane-a | The lines and notification `IN (...)` lists are chunked at 500 ids (one query per 500 open orders) | the spec asks for one query, not one per order; chunking keeps one query at any realistic volume and stays under the SQLite bound-parameter limit
- 2026-11-16 | lane-a | `hq_notification` is the status of the newest `order_notifications` row (highest id), else `none` | spec s6 "latest notification status per order"
- 2026-11-16 | lane-a | The queue lists open orders from every location (hq sees all) | hq already reads every order through `GET /api/orders`
- 2026-11-16 | lane-a | `created_by` (staff username) is shown on each card | spec s6 response shape; hq already sees it in the existing order detail; no new personal data is stored or collected
- 2026-11-16 | lane-a | Age text reads "waiting <d> <h> <min>" from `created_at` for all three statuses | spec s6 example text; no SLA colour or threshold
- 2026-11-16 | lane-a | Count badge labels: Waiting (pending), Being picked (in_process), Out for delivery | spec s6
- 2026-11-16 | lane-a | A failed PATCH that is not 409 re-enables the button and shows the server message; a network failure refreshes the queue | the order may or may not have moved; a refresh shows the truth
- 2026-11-16 | lane-a | A refresh asked for while one is in flight is queued once and run after it, not dropped | "never two requests in flight" while still showing the result of a status change
- 2026-11-16 | lane-a | Print hides the nav, every button, the action row and the message box | spec s6 print rule
- 2026-11-16 | lane-a | Extra tests beyond s6 1-8: non-GET methods answer 405; thousandths sum exactly; static scan that the module source has no write SQL or Db write helper | cheap deterministic guards for the read-only rule

## NEED-OUT-OF-SCOPE

- None. The menu link and home tile for `warehouse.html` are the Coordinator's (s9 step 3, `public/assets/app.js`, `public/index.html`).

## LANE BREACH

- None.

## Strikes

- None yet.
