# T-11 UI critic, round 3, final (19 Nov 2026)

Reviewer: UI critic (did not build T-11). HEAD `1ca7f26`, checked with `git rev-parse`. Read-only review; no product file edited, no tests run.
Diff reviewed: `git diff d638a63 1ca7f26 -- public/`. It is `public/assets/app.css` only, with two changes inside the 640px block:
- The row-action selectors now carry `tr:not(:has(td[colspan]))`.
- New rule `td.num, th.num { white-space: normal; }`.

Shots opened this round: all 11 at 375x812 (accounts, index, ingredients, login, login-error, ordering, recipes, recipes-batch, reports, stock, warehouse). The before shots are unchanged since round 2 (regenerated from `bd295dd`, same `public/` as `e8b07a1`).

## Re-checks

**B3: FIXED.** after/375x812/stock.png has negative balances on screen. The ON HAND heading and every value are fully visible. The note wraps inside the column ("-0.120 (more used than received)" over three lines), and SMP006 shows 0.000. Cause removed: the new phone-only `td.num, th.num { white-space: normal; }` overrides the desktop nowrap at app.css:115, with equal specificity and a later position.

**N11: FIXED.** The action selectors now score above the general `display: block` rule:
- Ingredients: (1,0,3) became (1,1,4).
- Accounts: (1,1,3) became (1,2,3).

after/375x812/ingredients.png shows Edit and Deactivate side by side, each half the row with an 8px gap. after/375x812/accounts.png shows each Deactivate button full width.

**Regression sweep at 375, after the new selectors:** none found.
- ordering.png: stock badges, order form stacking, order history and the detail totals 12.50 / 50.00 are all intact.
- recipes.png and recipes-batch.png: the recipe list and batch result stack. The inventory "ON HAND" heading now wraps to two lines, which is harmless, and values stay on one line.
- reports.png: all four tables stack and the totals bar reads "Total, Quantity 2, Total cost (R) 11.18, Batches 1".
- warehouse.png: the pick list and order cards fit.
- index, login and login-error: unchanged.

**Test selectors:** no markup or JS changed since round 1, so all 47 test tokens still resolve. The edits are CSS layout only. [Inference] The browser suites have not run on 1ca7f26.

## Final scores (0 fails, 1 partly, 2 meets)

| # | Item | Before | After | Reason |
|---|---|---|---|---|
| 1 | Clear headings | 1 | 2 | Before: h2 16px, with the h3 larger than it (before/375x812/reports.png). After: h2 22/700, h3 a small section label (app.css:92-94). |
| 2 | Aligned tables, R amounts right-aligned | 1 | 2 | Desktop: tabular numerals, right-aligned, shaded totals (after/1440x900/reports.png, recipes-batch.png). Phone: amounts are labelled in stacked rows (after/375x812/reports.png). |
| 3 | One consistent button style | 2 | 2 | One dark fill plus a `.secondary` outline across all pages (app.css:159-176). |
| 4 | Status badges for order states | 0 | 1 | The `.st` dot and colour are there, but there is no pill, and the raw `in_process` shows (after/1440x900/ordering.png; ordering.js:172). |
| 5 | Real empty, loading and error states | 1 | 1 | Empty rows and error messages are styled, but loading looks the same as empty (app.css:120). Deferred because it needs JS. |
| 6 | 44 px touch targets | 0 | 2 | `--touch` applies to buttons, nav links, inputs, selects and the checkbox row (app.css:67, 127, 151, 163). |
| 7 | 4.5:1 contrast | 1 | 2 | The placeholder renders #6A655C on #F4F1EC = 5.14 (app.css:137). The lowest text pair is 4.62. Before: input borders were 1.37:1. |
| 8 | Nothing cut off at 375 px | 0 | 2 | Before: ordering, recipes and reports overflowed the page (444, 413 and 538 px wide). After: all 11 pages are 375px wide, with every R amount and action visible. |
| 9 | prefers-reduced-motion respected | 2 | 2 | Transitions are off under `reduce` (app.css block at the end of the file). |
| 10 | No gradients, glows, emoji, decorative icons | 2 | 2 | None found in public/; the dots and labels mark state. |

**FINAL TOTALS: BEFORE 10/20, AFTER 18/20.**

## Remaining blockers

None.

## Open items for the report (not blockers; each needs a JS change)

- **O1, item 4.** Show order states as pills with plain words. In ordering.js:172 and :192, wrap the status in `<span class="badge <state>">` and replace `_` with a space. No test reads that cell text (checked in round 1).
- **O2, item 5.** Give loading and error rows their own markup and style, so a loading table does not look empty.
- Round 1 nice-to-haves N2-N10 stay open. The most visible is N2: the h3 section label looks the same as the table headings beneath it on reports and recipes.
