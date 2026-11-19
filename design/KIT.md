# The Hub: UI kit (T-10)

Type A: PHP back-office. Plain HTML pages in `public/` served by the PHP app, small ES5 scripts in `public/assets/`. CSS only tonight. No packages, no CDN, no web fonts, no build step. Values and contrast numbers are in `design/DESIGN.md`.

## Files
- `public/assets/app.css`: tokens (`:root`) and every shared component. Every page links it.
- `public/assets/warehouse.css`: the warehouse queue page only. It uses the `app.css` tokens and keeps the print rules.
- Add a page rule to its own file only when no shared class does the job. Never put a hex value outside `:root`.

## Page skeleton
```html
<link rel="stylesheet" href="assets/app.css">
<header class="hub-header"><h1>The Hub</h1><nav id="hub-nav" aria-label="Main"></nav></header>
<main>
  <section class="card">
    <h2>Page heading</h2>
    <p class="muted">One line of what this is.</p>
    ...
  </section>
</main>
```
`hub-nav` is filled by `Hub.renderNav`. One `h2` per card, `h3` for a section inside a card.

## Classes
| Need | Use |
|---|---|
| Panel | `.card` (`.card.narrow` for the login box) |
| Button | plain `<button>`; `class="secondary"` for the outlined variant; keep `type="button"` / `type="submit"` |
| Table | `<div class="table-scroll"><table>` ... `</table></div>`; `th.num` / `td.num` for numbers and R amounts; totals in `<tfoot>` |
| Empty or loading row | `<tr><td colspan="N" class="muted">No orders yet.</td></tr>` |
| Status pill | `<span class="badge pending">` (also `in_process`, `out_for_delivery`, `delivered`, `cancelled`, `in_stock`, `low_stock`, `out_of_stock`) |
| Order state in running text or a cell | `class="st <state>"` on the element holding the text |
| Message | `<div class="message error hidden" role="alert">`; `.ok` for success; the script toggles `.hidden` |
| Form field | `<label for>` then `<input>` / `<select>` / `<textarea>`; checkbox inside its `<label>` |
| Muted text | `.muted` |
| Home tile | `<a>` with a `<strong>` title inside `.tiles` |

## Rules
1. Do not rename or remove an id or an existing class; scripts and `tests/browser/run.cjs` use them.
2. Numbers: right aligned, tabular figures, money in rand with two decimals, quantities with three.
3. 44px minimum touch target; 4.5:1 text contrast; nothing wider than the screen at 375px (wide tables scroll inside `.table-scroll`).
4. No gradients, glows, emoji, icon fonts or decorative images. State is text first, colour second.
5. Motion: only the 200 ms colour transitions already in `app.css`; they switch off under `prefers-reduced-motion`.
6. Change a colour once, in `:root`, then update `DESIGN.md`.

## Known gaps (need script changes, not CSS)
- Order state is written by the scripts as the raw word (for example `in_process`); a pill and a friendly label need a wrapping `<span>` in `ordering.js`.
- A loading row and an empty row use the same style; a spinner or error row inside a table needs the script to mark it.
- Dark theme not built.
