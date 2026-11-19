# T-11 UI critic, round 2 (19 Nov 2026)

Reviewer: UI critic (did not build T-11). HEAD `d638a63` on `feat/hub-hardening-1`, checked with `git rev-parse`. Read-only review; no product file edited, no tests run.
Diff reviewed: `git diff a9798c0 d638a63 -- public/`. Only `public/assets/app.css` changed: one `::placeholder` rule (line 137) and the phone stacking block (lines 287-377). No markup or JS changed.
Before shots: regenerated from `bd295dd`, which has the same `public/` as `e8b07a1` (`git diff --stat` is empty). I opened every new or changed before image (recipes-batch, reports, stock at 375).
After shots opened: all 11 at 375x812, plus reports, recipes-batch, ordering, recipes and ingredients at 1440x900.

## Scores (0 fails, 1 partly, 2 meets)

| # | Item | Before | After | Reason |
|---|---|---|---|---|
| 1 | Clear headings | 1 | 2 | Unchanged from round 1. Before: h2 16px, and the inner h3 rendered larger than it (before/375x812/reports.png). After: h2 22/700 (app.css:92-94). |
| 2 | Aligned tables, R amounts right-aligned | 1 | 2 | Desktop: tabular numerals, right-aligned, shaded totals (after/1440x900/reports.png, recipes-batch.png). Phone: the stacked rows label each amount (after/375x812/reports.png). |
| 3 | One consistent button style | 2 | 2 | Dark fill plus `.secondary` outline on every page (app.css:159-176). |
| 4 | Status badges for order states | 0 | 1 | Unchanged. The `.st` dot and colour are there, but there is no pill, and the raw `in_process` still shows (after/1440x900/ordering.png, order history row 1; ordering.js:172). |
| 5 | Real empty, loading and error states | 1 | 1 | Unchanged. Loading still looks like empty (app.css:120). This was deferred because it needs JS. |
| 6 | 44 px touch targets | 0 | 2 | Unchanged. `--touch` applies to buttons, nav links, inputs, selects and the checkbox row (app.css:67, 127, 151, 163). |
| 7 | 4.5:1 contrast | 1 | **2** | B2 is fixed (app.css:137). The rendered placeholder, sampled from after/375x812/stock.png and after/1440x900/recipes-batch.png, is #6A655C on #F4F1EC = 5.14:1. The lowest text pair is now 4.62 (in-process badge over `--surface`). |
| 8 | Nothing cut off at 375 px | 0 | 1 | Recipes, batch result, reports (all 4 tables and the totals), ingredients, accounts and the order detail now stack with every R amount and action on screen. But after/375x812/stock.png clips its On hand column: **new blocker B3**. |
| 9 | prefers-reduced-motion respected | 2 | 2 | app.css:381-383 (the block moved down; same rule). |
| 10 | No gradients, glows, emoji, decorative icons | 2 | 2 | The new CSS adds no decoration; the `::before` labels are text. |

**Totals: BEFORE 10/20, AFTER 17/20.**

## Round 1 blockers re-checked

**B1: FIXED on the pages it named.** Every R amount and row action is fully visible at 375:
- after/375x812/recipes.png: cost per unit and all three Edit buttons, full width.
- after/375x812/recipes-batch.png: Unit cost, Line cost and Total 11.18.
- after/375x812/reports.png: Total cost (R), Avg cost per item (R) and Cost per item (R) on every table, and the totals bar "Total, Quantity 2, Total cost (R) 11.18, Batches 1".
- after/375x812/ingredients.png: Cost (R, excl. VAT), FinCon item, Edit and Deactivate.
- after/375x812/accounts.png: Location, Type, Created, Status and Deactivate.
- after/375x812/ordering.png: the order detail shows Unit cost (R) 12.50, Line (R) 50.00 and Total (R) 50.00.

Desktop is unchanged: the stacking sits inside `@media (max-width: 640px)` (after/1440x900/*.png).

**B2: FIXED.** `::placeholder { color: var(--ink-soft); opacity: 1; }` (app.css:137). Measured on the rendered pixels at 5.14:1.

## Test selectors

No markup or JS changed between a9798c0 and d638a63, so all 47 test tokens from round 1 still resolve. The ids the new CSS targets all exist:
- `#recipes-body` (recipes.html:19), `#batch-result` (recipes.html:59)
- `#report-items`, `#report-totals`, `#report-batches`, `#admin-recipes`, `#admin-batches` (reports.html:32-69)
- `#ing-body` (ingredients.html:20), `#acc-body` (accounts.html:36), `#detail-body` (ordering.html:61)

The new `::before` labels are generated content and are not part of `innerText` or `textContent`. Rows changing from table-row to grid only changes the whitespace `innerText` puts between cells. The e2e checks match substrings or numbers (for example e2e_night.cjs:720-725, :797, :813) or read `tr.cells[n].textContent` (run.cjs:252-259), so neither change should affect them. [Inference] This is not proven until the browser suites run on d638a63; the stacked layout only applies to runs at 640px or narrower (the T-09 390x844 pass).

## NEW BLOCKERS

**B3. Hub stock clips its On hand column at 375px, which is worse than before.**
- Evidence: after/375x812/stock.png. The "ON HAND" heading is cut to its first letter. Values read "-0.120 (more used thar", and the SMP006 quantity is out of view. Before (before/375x812/stock.png), the same data wrapped inside the column: "-0.120 (more used than received)".
- Cause: app.css:115 `td.num, th.num { ... white-space: nowrap; }`, which a9798c0 added. stock.js:10 appends the "more used than received" note inside the `td.num` cell, so it can no longer wrap. `#stock-body` on stock.html is not in the stacking list. Round 1 missed this because that data set had no negative balances.
- Fix, CSS only: inside the 640px block add `td.num { white-space: normal; }`. Alternatively, scope it: `table:has(> #stock-body) td.num { white-space: normal; }`; note that the ordering page also has a `#stock-body`. Re-shoot stock at 375 with a negative balance.

## Nice-to-haves (new this round)

- **N11. Row-action flex rule loses on specificity.** `#ing-body > tr > td:has(> button)` and `#acc-body > tr > td:nth-child(7)` set `display: flex` (app.css:366), but they score (1,0,3) and (1,1,3) respectively. The general `:is(#ing-body, ...) > tr:not(:has(td[colspan])) > td { display: block }` (app.css:334) scores (1,1,3) and comes earlier, so it wins outright for ingredients and ties for accounts. [Inference] For accounts I did not settle whether the tie should go to the later rule; the screenshot shows block layout either way. So the `flex: 1 1 120px` buttons do not stretch: in after/375x812/ingredients.png, Edit and Deactivate sit at natural width about 4px apart, and in after/375x812/accounts.png, Deactivate is at natural width. They are usable at 44px. To fix, prefix the action rule with the same `:not(:has(td[colspan]))`, or add `!important` to `display: flex` on that one rule.
- Round 1 N1-N10 still stand. N1 (order-state pills, ordering.js:172) is the one that would lift item 4 to 2.
