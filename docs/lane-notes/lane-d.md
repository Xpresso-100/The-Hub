# Lane D notes: Hub stock page (front end only)

Branch `feat/hub-live-ops-lane-d`. Owned files: `public/stock.html`, `public/assets/stock.js`, `tests/unit/StockScreen/*`, this file.

## Task status
| Task | Status |
|---|---|
| D1 read inventory API and recipes.js request shape | DONE (shape below) |
| Tests (spec 8b tests 1-4) | WRITTEN, not run (Builder has no shell; Coordinator runs `php tests/run.php StockScreen`) |
| D2 stock.html + stock.js table and location picker | WRITTEN, not run |
| D3 stock count form | WRITTEN, not run |

## D1: the API shape (read from InventoryController, InventoryService, routes.php, recipes.js, inventory_test.php)

Both routes require `Auth::requireRole($req, 'hq', 'hub')`; franchise gets 403 `FORBIDDEN`.

**GET `api/inventory`**
- Query: `location_id` (only used by `hq`; must be a hub location id, digits as int or string; else 422 `VALIDATION_ERROR` on `location_id`). A `hub` user's location is always their own; any `location_id` sent is ignored.
- 200: `{"success":true,"location_id":<int>,"items":[{"sku":str,"name":str,"unit":str,"on_hand":<number, 3 dp>}]}`. Every ACTIVE ingredient is listed (0 when no movements), sorted by sku. No cost or price field is returned.

**POST `api/inventory/adjustments`**
- Body (JSON numbers, not strings): `{"sku":str,"qty_delta":number,"reason":str, "location_id":int (hq only, required for hq)}`.
- `qty_delta`: non-zero, at most 3 decimals, between -100000 and 100000. `reason`: trimmed, 1 to 200 chars. `sku`: must be an active ingredient.
- `location_id` in the body must be a JSON int (a string is 422). A `hub` user's value is ignored.
- 201: `{"success":true,"movement":{id,location_id,sku,qty_delta,reason:"adjustment",note:<the reason text>,created_at},"on_hand":<number>}`.
- Errors: 422 `{"success":false,"code":"VALIDATION_ERROR","message":..., "errors":{field:msg}}` (recipes.js reads `data.message` and `data.errors`).

**recipes.js behaviour copied:** `Hub.api('GET','api/inventory' + (hq ? '?location_id='+encodeURIComponent(id) : ''))`; adjustment body `{sku, qty_delta: Number(input), reason}` plus `location_id: Number(id)` for hq; success is `r.status === 201`; then reload the table. Hub.qty(n) formats to 3 dp.

**Location list:** the existing inventory form does NOT have a list. It has a free number input "Hub location id" (`inv-location`). No existing endpoint lists locations for hq. Lane C adds `GET api/admin/locations` -> `{"locations":[{"id","name","type"}]}` (spec 8c).

**Hub.requireRole(...roles)** accepts several roles (app.js:64), so `Hub.requireRole('hq','hub')` is used directly.

## Assumptions
- 2026-11-16 | lane-d | hq location dropdown is filled from `GET api/admin/locations`, keeping `type === 'hub'` only | spec says "hub locations" and lane C provides the only location list; the existing recipes form has none.
- 2026-11-16 | lane-d | If that call fails or returns no hub rows (lane C absent, 403, 404), stock.js shows a numeric "Hub location id" input (`stock-location-id`), the same fallback the recipes page uses | page must work with no dependency on lane C.
- 2026-11-16 | lane-d | The ingredient dropdown and table both come from `GET api/inventory` items, not `GET api/ingredients` | that endpoint is cost-bearing; inventory items carry only sku, name, unit, on_hand, so no cost data ever reaches the page.
- 2026-11-16 | lane-d | Success message after a count shows SKU and the new on-hand from the 201 body, then the table reloads | matches recipes.js.
- 2026-11-16 | lane-d | Negative on-hand: plain text value plus the note "more used than received" in the same cell, no colour | spec 8b.
- 2026-11-16 | lane-d | Test 3 uses SKU `SMP001` (fixture, used by tests/unit/Recipes/inventory_test.php) and reads the app's own `GET api/inventory` first, using the first listed SKU if SMP001 is absent | avoids hard-coding a fixture that may change.
- 2026-11-16 | lane-d | `stock.js` avoids the words cost, price and total everywhere (the test is a plain case-insensitive substring match, which is stricter than "token") | spec test 2.

## NEED-OUT-OF-SCOPE
- None. Note for the Coordinator: `app.js` LINKS needs a `stock.html` entry (`roles: ['hq','hub']`) and `index.html` TILES needs a tile; both are Coordinator-only files (spec section 9).

## Strikes
- Strike 1 (D-01 escape lint, line 56 of stock.js): the lint saw `+ it.name` inside one big esc(...). Fixed in code: each value is escaped separately, `esc(it.sku) + ' - ' + esc(it.name) + (it.unit ? ' (' + esc(it.unit) + ')' : '')`. Lint untouched.
