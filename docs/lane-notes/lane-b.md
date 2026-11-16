# Lane B notes: ingredient screen (front end only)

Worktree: .claude/worktrees/lane-b, branch feat/hub-live-ops-lane-b. Builder: DRAFT. Date: 2026-11-16.
Bash was denied to this builder (Coordinator decision). Nothing below has been run; the Coordinator runs `php tests/run.php IngredientScreen` and `node --check public/assets/ingredients.js`.

## Tasks
| # | Task | Status |
|---|---|---|
| B1 | `ingredients.html` table, show-inactive checkbox | WRITTEN, not yet run |
| B2 | Add/edit form, number-only cost | WRITTEN, not yet run |
| B3 | Deactivate/reactivate, cost-change confirm | WRITTEN, not yet run |

## Tests (tests/unit/IngredientScreen/)
- ingredient_screen_ui_test.php: LB-1 served page and script, ids, relative URLs; LB-1 escape lint and no eval; LB-2 Number(, regex, cost_price only from costNumber.
- ingredient_api_test.php: LB-3 round trip 12.5 and 0.1; LB-3 string "12.50" gives 422 and cost unchanged; LB-3 deactivate/reactivate and null FinCon; LB-4 hub and franchise get 403, anon 401.

## Assumptions
- 2026-11-16 | lane-b | SKU is validated client-side as /^[A-Z0-9-]{2,20}$/ and upper-cased on input | the router only matches {sku} as [A-Z0-9-]{2,20} (src/Http/Router.php line 9), so anything else would 404
- 2026-11-16 | lane-b | Server errors are read from `data.errors` (keys name, unit, cost_price, fincon_item_no) | IngredientController::put and Response.php
- 2026-11-16 | lane-b | Inactive rows are greyed with the existing `.muted` class | no new CSS file allowed
- 2026-11-16 | lane-b | Cost-change confirm compares in cents against the loaded row; it only fires when editing an existing SKU | spec: "when a cost changes"
- 2026-11-16 | lane-b | Deactivate/Reactivate from the table does not ask for confirmation | spec asks only for the cost-change confirm; the action is reversible
- 2026-11-16 | lane-b | Test SKUs LBTEST1-4 are created through the real PUT in a fresh per-test SQLite app | harness starts a fresh app per TestApp::start
- 2026-11-16 | lane-b | Edit form shows cost as toFixed(2) text, which is display only; the body always uses costNumber = Number(text) | spec s7

## NEED-OUT-OF-SCOPE
- none

## Strikes
- 2026-11-16 | lane-b | strike 1: LB-3c "blank FinCon item stored as null: expected null, got missing" | test bug, not a page or API bug: `$row['fincon_item_no'] ?? 'missing'` turns a stored null into the fallback. Fixed with array_key_exists plus a direct null assert; the null assertion is not weakened. Awaiting rerun.

## Ownership
Files written: public/ingredients.html, public/assets/ingredients.js, tests/unit/IngredientScreen/*, docs/lane-notes/lane-b.md. No LANE BREACH.
