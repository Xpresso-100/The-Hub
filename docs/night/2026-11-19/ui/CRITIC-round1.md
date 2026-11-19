# T-11 UI critic, round 1 (19 Nov 2026)

Reviewer: UI critic (did not build T-11). Branch `feat/hub-hardening-1` at `a9798c0`. Read-only review; no product file edited, no tests run.
Evidence: all 40 screenshots in `docs/night/2026-11-19/ui/{before,after}/{375x812,1440x900}/` opened; `public/assets/app.css`, `public/assets/warehouse.css`, `git diff e8b07a1 a9798c0 -- public/`, `design/DESIGN.md`, `design/KIT.md`.

Scoring: 0 fails, 1 partly, 2 meets. Overall per item across all pages and both sizes.

| # | Item | Before | After | Reason |
|---|---|---|---|---|
| 1 | Clear headings | 1 | 2 | Before: card h2 was 16px, barely above body, and the inner h3 rendered larger than the h2 (before/1440x900/reports.png "Batches in this report"). After: h2 22/700 (app.css:92-93), h3 a small uppercase section label (app.css:94). Caveat in N2. |
| 2 | Aligned tables, R amounts right-aligned | 1 | 2 | Before: `td.num` right-aligned but headings wrapped into 3-5 lines and rows were top-aligned (before/375x812/ordering.png, reports.png). After: tabular numerals, right-aligned, headings on one line, shaded total row (app.css:105-117; after/1440x900/ordering.png, reports.png). The clipping at 375 is scored under item 8. |
| 3 | One consistent button style | 2 | 2 | Before: brown fill plus a brown outlined secondary, used the same way on every page. After: dark fill plus `.secondary` outline, the same on every page (app.css:159-176). |
| 4 | Status badges for order states | 0 | 1 | Before: the order state was plain text "pending" (before/1440x900/ordering.png). After: a dot and colour via `.st` (ordering.js:172, 192; warehouse.js:121). There is still no pill: the `.badge.pending` and similar state classes (app.css:197-198) are never used for orders, and the raw word `in_process` reaches users (after/1440x900/ordering.png, order history row 1). |
| 5 | Real empty, loading and error states | 1 | 1 | After: a padded, centred empty row (app.css:120; after/1440x900/reports.png "No batches.") and a ruled error block (app.css:211; after/*/login-error.png). Loading rows still look exactly like empty rows and tables have no error row. Deferred because it needs JS (KIT.md "Known gaps"). |
| 6 | 44 px touch targets | 0 | 2 | Before: buttons and inputs were about 37px tall and nav links about 20px. After: `--touch: 44px` is applied to button (app.css:163), nav links (67), input/select/textarea (127), the checkbox label row (151) and tiles (220). |
| 7 | 4.5:1 contrast | 1 | 1 | Before: all text passed narrowly (low_stock badge 4.52, ok 4.56), but input borders were 1.37:1, below the 3:1 non-text minimum. After: every token pair passes, but the browser-default placeholder now sits on the `--surface` fill, measured 4.09:1. See the contrast table and B2. |
| 8 | Nothing cut off at 375 px | 0 | 1 | Before: the page itself overflowed (ordering 444px, recipes 413px, reports 516px wide). After: every page is 375px wide, but wide tables now clip inside their card with text sliced at the edge: recipes, reports, ingredients, the order detail and accounts. See B1. |
| 9 | prefers-reduced-motion respected | 2 | 2 | Before: nothing moved. After: the 200ms colour transitions are switched off under `prefers-reduced-motion: reduce` (app.css:290-292). |
| 10 | No gradients, glows, emoji, decorative icons | 2 | 2 | A search of public/ for gradient, glow, text-shadow, keyframes and emoji ranges found nothing. Card shadow is the soft CT `--shadow` (app.css:21). Badge dots mark state. |

**Totals: BEFORE 10/20, AFTER 16/20.**

## Contrast, recomputed (WCAG 2.1 relative luminance)

I recomputed these independently. Tints are composited over the real background, and the rendered pixel colours were sampled from the screenshots.

| Pair | Hex | Ratio | Verdict |
|---|---|---|---|
| Placeholder (rendered, sampled from after/1440x900/stock.png) on input fill `--surface` | #757575 on #F4F1EC | **4.09** | **FAIL** (before: on #FFFFFF = 4.61) |
| `--st-process` on 16% `--accent` over `--surface` | #8A5A14 on #F1E1D0 | 4.62 | pass, narrow |
| `--alert` on 8% `--alert` over `--surface` | #B23A2F on #EFE2DD | 4.69 | pass, narrow |
| `--st-process` on 16% `--accent` over `--raised` (low stock, SAMPLE badges) | #8A5A14 on #F7E8D9 | 4.92 | pass |
| `--alert` on 8% `--alert` over `--raised` (error message, out of stock) | #B23A2F on #F5EBE7 | 5.06 | pass |
| `--ink-soft` on `--surface` (count labels, pending and SENT badges) | #6A655C on #F4F1EC | 5.14 | pass |
| `--ink-soft` on `--raised` (muted text, th, hub-user 13px; sampled #6A655C) | #6A655C on #FBFAF7 | 5.54 | pass |
| Input border `--ink-soft` on `--surface` (non-text, needs 3:1) | #6A655C on #F4F1EC | 5.14 | pass |
| `--line` dividers (decorative only) | #E2DCD2 on #FBFAF7 | 1.31 | n/a, decorative |

These match DESIGN.md s1 to two decimals (5.05 there against 5.06 here, a rounding difference). DESIGN.md does not list the placeholder.

## Touch targets (CSS check)

- Buttons: `min-height: var(--touch)` (app.css:163). `td button` keeps it; only the padding changes (176). No `min-width`, but the shortest label ("Edit") renders about 55px wide.
- Nav links: `min-height: 44px` with inline-flex (app.css:64-73). The Log out button in the nav inherits the button rule.
- Inputs, selects and textareas: `min-height: var(--touch)` (app.css:127). The 22px checkbox sits in a 44px clickable label row (138-155).
- Not covered: the gaps between adjacent buttons are about 4px of inline whitespace (N7). Each target is 44px, so WCAG 2.5.8 is still met.

## Test selectors (checked against public/*.html and public/assets/*.js)

I extracted 47 id and class tokens from `tests/browser/run.cjs` and `tests/browser/e2e_night.cjs`. **All 47 are present and none are missing**, including `#ingredient-body`, `#stock-body .badge`, `.wh-order`, `.wh-status`, `#orders-body button[data-view]`, `#tiles a strong`, `#hub-nav a`, `#order-total`, `#report-totals`, `#report-batches`, `#queue-counts`, `#queue-empty`, `#picklist-body`, `#acc-body`, `#ing-body`, `#inv-body`, `#detail-body`, `#next-status`, `#status-submit` and `#b-recipe`.

`text-transform: uppercase` (thead th, .badge, h3) changes what `innerText` returns. I checked every `innerText` assertion: they look for numbers, SKUs, "Waiting", "Active" and emails in non-uppercased cells, and `#stock-body .badge` is read with `textContent` (e2e_night.cjs:910). No breakage expected. [Inference] This is not proven until the browser suites are re-run on a9798c0.

## BLOCKERS

**B1. Money columns and row actions clip at 375px, with no sign that the table scrolls** (breaks item 8).
- Evidence:
  - after/375x812/recipes.png: "COST PER U", the values "5" "4" "22" are cut, and Status and Edit are out of view.
  - after/375x812/reports.png: "TOTAL COST (I", the total shows "0.0", and "COST F" and "QUA" are cut.
  - after/375x812/ingredients.png: "12.5" is cut, and FinCon item, Type, Status, Edit and Deactivate are out of view.
  - after/375x812/ordering.png, order detail: "UNIT CO" is cut and the Total reads "To".
  - after/375x812/accounts.png: "LO" and "Xp" are cut.
- Cause: `thead th { white-space: nowrap }` (app.css:111) applies at every width, so headings no longer wrap and each table gets wider. `.card { overflow-x: auto }` (app.css:88) then clips the table at the card edge. Phone scrollbars are hidden, so nothing tells the user to swipe. On recipes, the main row action (Edit) is now undiscoverable.
- Fix, CSS only:
  - (a) Inside `@media (max-width: 640px)`, add `thead th { white-space: normal; }` and tighten `th, td { padding: 8px 6px; }`.
  - (b) For `#ing-body`, `#acc-body`, the recipes list body, `#report-batches`, the finished-goods body and `#detail-body table`, reuse the stacked-row pattern already in app.css:246-288 (nth-child `::before` labels), so money and actions stay on screen. This needs no markup change, so it is allowed on the frozen recipes and reports pages.
  - At minimum, ship (a), then re-shoot recipes, reports and ingredients at 375.

**B2. Placeholder text contrast fell below 4.5:1** (breaks item 7; worse than before).
- Evidence: the sampled pixel is #757575 on #F4F1EC = 4.09:1. Before, it was 4.61:1 on #FFF. The affected placeholders carry real hints: "opening balance, stock count" (after/1440x900/stock.png, recipes.png) and the format example "12.50" (after/1440x900/ingredients.png).
- Cause: app.css:133 changed the input fill to `--surface` and left the browser-default placeholder colour.
- Fix: add `::placeholder { color: var(--ink-soft); opacity: 1; }` to app.css (5.14:1 on `--surface`), and add that row to DESIGN.md s1.

## NICE-TO-HAVES

- **N1. Order-state pills and friendly words.** ordering.js:172 and :192 add `class="st <state>"`, but users still read `in_process` and `out_for_delivery` with underscores. Use `'<span class="badge ' + esc(o.status) + '">' + esc(o.status.replace(/_/g, ' ')) + '</span>'`, or reuse warehouse's `STATUS_TEXT`. ordering.js is not frozen, and no test reads the status-cell text (grep of both suites). This would raise item 4 to 2.
- **N2. The h3 reads like a table heading.** h3 (app.css:94: 13px, uppercase, `--ink-soft`) sits directly above a thead row of the same style (app.css:105-112: 12px, uppercase, `--ink-soft`). The two run together on after/1440x900/reports.png ("BATCHES IN THIS REPORT" over "BATCH PRODUCED ON ...") and recipes.png ("ADJUSTMENT ..."). Give h3 `color: var(--ink)`, 15-16px and no uppercase, or keep the label style and add `margin-bottom: 12px` plus a top rule.
- **N3. Colour literals outside `:root`**, against KIT.md rule "Never put a hex value outside :root" and bench-ui "no new inline hex": `#000` (app.css:172) and the rgba tints (app.css:194-198, 211-212). Move them to tokens such as `--hover-fill` and `--tint-ok`.
- **N4. SAMPLE label reuses the stock-warning colour.** ingredients.js:80 and recipes.js:69 render SAMPLE as `.badge.low_stock`, so it carries the orange "low stock" colours and a state dot. Add a neutral `.badge.sample`. Only ingredients.js can change; recipes.js is frozen, so for recipes use a selector like `#recipe-body .badge.low_stock` in app.css.
- **N5. No current-page marker in the nav.** `Hub.renderNav` sets no `aria-current` (grep of public/assets/*.js). Add it in JS later, with `#hub-nav a[aria-current="page"] { background: var(--surface); }`.
- **N6. Money left-aligns in the phone order form.** In the stacked rows (app.css:256 `text-align: left`), Cost and Line read left-aligned. That is acceptable with labels above; optionally right-align `#ingredient-body td:nth-child(4)` and `td:nth-child(6)`.
- **N7. Adjacent buttons sit about 4px apart**: Edit and Deactivate, Show report and Download CSV (after/1440x900/ingredients.png, reports.png). Add `button + button { margin-left: 6px; }`.
- **N8. Print side effect.** `.card { overflow-x: auto }` also applies to the warehouse pick list in print. Add `.card { overflow: visible; }` inside warehouse.css `@media print` (warehouse.css:42) so a wide table is not clipped on paper. [Inference] Not print-tested.
- **N9. Login card position.** At 1440 the login card hugs the left side of the 1200px column (after/1440x900/login.png). Add `.card.narrow { margin-inline: auto; }` if centring is wanted.
- **N10. Loading and empty rows look the same** (app.css:120). This is deferred until JS can mark a loading row, as KIT.md notes.
