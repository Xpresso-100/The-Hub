# The Hub: design tokens (T-10, 19 Nov 2026)

Light only tonight (the source has a dark theme, `xpresso_hq_control_tower_pc.html:52-78`; it is not implemented here).

Source of the look, read-only: the franchisee Control Tower stylesheet, `C:\dev\franchisee-onboarding-system\live_snapshots\2026-10-12\xpresso_hq_control_tower_pc.html` ("CT" below), and `...\orders\onboarding\ob_franchisee_pc.html` (`:root` at lines 25-33, same values). Every colour below is copied from CT with its hex. No colour was invented. Where the Hub needs something CT does not have, it is marked "Hub choice" and uses no new hex.

Not taken from CT, on purpose: the Google Fonts (Bricolage Grotesque, Hanken Grotesk, JetBrains Mono; CT lines 7-9), the Chart.js / jsPDF CDN scripts, the espresso sidebar, the hard "stamp" shadow and the button lift on hover (CT:34, CT:164; a decorative effect), the `.live` glow dot (CT:161).

## 1. Colours (light)

| Token | Hex | Used for | Source |
|---|---|---|---|
| `--surface` | `#F4F1EC` | page background, input fill, table footer, count tiles | CT:27 |
| `--raised` | `#FBFAF7` | cards, header, tiles, secondary button fill | CT:27 |
| `--ink` | `#1C1B19` | body text, primary button fill | CT:27 |
| `--ink-soft` | `#6A655C` | muted text, table headings, input borders | CT:27 |
| `--primary` | `#7A4A28` | brand title, tile titles, focus ring | CT:28 |
| `--primary-dark` | `#5F3A20` | reserved (not used tonight) | CT:28 |
| `--accent` | `#E08A3C` | tint base for "in process" and "low stock" only; never text | CT:28 |
| `--ledger` | `#12674A` | in stock, delivered, success | CT:28 |
| `--line` | `#E2DCD2` | card and row dividers (decorative) | CT:29 |
| `--line-soft` | `#EEE9E1` | disabled input fill | CT:29 |
| `--st-pending` | `#6A655C` | order state: pending | CT:31 |
| `--st-process` | `#8A5A14` | order state: in process; low stock | CT:31 |
| `--st-transit` | `#275C9E` | order state: out for delivery | CT:31 |
| `--st-delivered` | `#12674A` | order state: delivered | CT:31 |
| `--alert` | `#B23A2F` | errors, out of stock, cancelled | CT:31 |
| `--white` | `#fff` | text on the dark button | CT:163 (`color:#fff`) |
| hover fill | `#000` | primary button hover | CT:164 |

Legacy names the Hub scripts already use stay as aliases in `app.css`: `--muted` = `--ink-soft`, `--bg` = `--surface`, `--card` = `--raised`, `--ok` = `--ledger`, `--warn` = `--st-process`, `--bad` = `--alert` (`recipes.js` writes `var(--bad)`).

Cancelled has no state of its own in CT. Hub choice: it takes `--alert`, tinted like CT's `.pill.hv-expired` (CT:330) but at the lighter alpha CT uses for `.msg.error` (.08, CT:570).

### Measured contrast (WCAG 2.1 relative luminance, computed 19 Nov 2026)

Body text needs 4.5:1. Every pair that carries text:

| Text on background | Ratio |
|---|---|
| `--ink` on `--raised` (body in cards) | 16.49 |
| `--ink` on `--surface` (page, inputs) | 15.28 |
| `--ink-soft` on `--raised` (muted text) | 5.54 |
| `--ink-soft` on `--surface` (count labels, pending badge) | 5.14 |
| `--primary` on `--raised` (brand, tile titles) | 7.09 |
| `--primary` on `--surface` | 6.57 |
| `#fff` on `--ink` (primary button) | 17.21 |
| `#fff` on `#000` (primary button hover) | 21.00 |
| `--ink` on `--raised` (secondary button) | 16.49 |
| `--ledger` on `--raised` / `--surface` | 6.56 / 6.08 |
| `--st-process` on `--raised` / `--surface` | 5.66 / 5.25 |
| `--st-transit` on `--raised` / `--surface` | 6.47 / 5.99 |
| `--alert` on `--raised` / `--surface` | 5.68 / 5.27 |
| pending badge (`--st-pending` on `--surface`) | 5.14 |
| in process badge (`--st-process` on 16% `--accent` over `--raised` = `#f7e8d9`) | 4.92 |
| in process badge, same over `--surface` (`#f1e1d0`) | 4.62 |
| out for delivery badge (12% `--st-transit` over `--raised`) | 5.42 |
| delivered / in stock badge (12% `--ledger` over `--raised`) | 5.47 |
| cancelled / out of stock badge (8% `--alert` over `--raised`) | 5.05 |
| cancelled badge over `--surface` | 4.70 |
| error message (`--alert` on 8% `--alert` over `--raised`) | 5.05 |
| success message (`--ledger` on 10% `--ledger` over `--raised`) | 5.65 |

Rejected: `--accent` as text is 2.56:1 on `--raised`, so it is never used for text. `--line` (`#E2DCD2`) is 1.31:1 against `--raised`, so it is used only for decorative dividers; input borders use `--ink-soft` (5.54:1, above the 3:1 non-text minimum, WCAG 1.4.11). The CT focus ring uses `--accent` (CT:119); the Hub uses `--primary` as CT does for its drill tiles (CT:224).

## 2. Type

System font stack, no web fonts: `system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif`. Money and quantities use `font-variant-numeric: tabular-nums` and right alignment. CT sizes are 12-13px for dense desktop tiles (CT:230-233); the Hub uses larger sizes for phones and 44px targets, so the scale below is a Hub choice except the cited items.

| Role | Size / weight | Source |
|---|---|---|
| Brand title (header h1) | 20 / 700, `--primary`, -.01em | CT:158 uses 25 / 700 with a display font; Hub choice |
| Page heading (card h2) | 22 / 700, -.01em (20 under 640px) | CT:563 `.view-head h2` 22 / 700 |
| Section label (h3) | 13 / 700, uppercase, .08em, `--ink-soft` | CT:170 `.tile-h h3` (12px) |
| Body | 15 / 1.5, 400 | Hub choice |
| Table text | 14 | CT:230 uses 12.5 |
| Table heading | 12 / 700, uppercase, .07em, `--ink-soft` | CT:231 (10.5px) |
| Label | 13 / 700 | Hub choice |
| Input text | 16 (stops phone zoom on focus) | Hub choice |
| Button | 14 / 600 | CT:163 12.5 / 600 |
| Badge | 11.5 / 700, uppercase, .04em | CT:263 10.5 / 700, .04em |

## 3. Spacing, radius, shadow

- Spacing: 4, 6, 8, 10, 12, 16, 20, 24 px. Card padding 20 (16 under 640px). Page padding 24 (12 under 640px). Gap between cards 20. Table cell padding 10 x 8 (CT:232 uses 8).
- Radius: 10 px buttons, 9 px inputs (CT:163, CT:587), 16 px cards and tiles (CT:168), 999 px badges (CT:263).
- Shadow: `--shadow: 0 1px 2px rgba(28,27,25,.04), 0 2px 8px rgba(28,27,25,.04)` on cards and tiles (CT:32). No other shadow, no glow, no gradient.
- Motion: colour and border transitions only, 200 ms; all removed under `prefers-reduced-motion: reduce`.
- Touch target: 44 px minimum height on every button, link in the nav, input, select and checkbox row.

## 4. Components

**Button.** One style. Dark fill `--ink`, text `#fff`, 1.5px `--ink` border, 10px radius, min-height 44, 14 / 600 (CT:163). Hover `#000` (CT:164, without the lift). Variant `.secondary` (and the Log out button in the nav): `--raised` fill, `--ink` text, `--ink-soft` border, hover `--surface`. Disabled: 50% opacity. Focus: 2px `--primary` ring, 2px offset.

**Table.** Full width inside `<div class="table-scroll">` so a wide table scrolls inside its card and never widens the page. Heading row: 12px uppercase `--ink-soft` on a 2px `--line` rule (CT:231). Rows: 1px `--line` divider (CT:232). `th.num` / `td.num`: right aligned, tabular numbers. Money columns are headed "(R)" and use `td.num`. Footer row (totals): `--surface` fill, bold (CT:234-235).

**Badges, one per order state.** Pill: 6px dot + uppercase label, 999px radius, 11.5 / 700 (CT:321-326, pattern `.pill.<state>`).

| State | Class | Text | Fill | Contrast |
|---|---|---|---|---|
| pending | `.badge.pending` | `--st-pending` | `--surface` | 5.14 |
| in_process | `.badge.in_process` | `--st-process` | 16% `--accent` | 4.92 |
| out_for_delivery | `.badge.out_for_delivery` | `--st-transit` | 12% `--st-transit` | 5.42 |
| delivered | `.badge.delivered` | `--st-delivered` | 12% `--ledger` | 5.47 |
| cancelled | `.badge.cancelled` | `--alert` | 8% `--alert` | 5.05 |

Stock status badges reuse these: `.in_stock` = delivered colours, `.low_stock` = in process colours, `.out_of_stock` = cancelled colours. Where a script writes an order state as plain text inside a cell, the class `.st.<state>` colours that text and adds the dot (no pill fill, because a table cell cannot be a pill without a wrapping element). The state is always written as text, never colour alone.

**Form.** Label above the field, 13 / 700. Fields: 16px text, min-height 44, 1.5px `--ink-soft` border, 9px radius, `--surface` fill, max-width 360 (full width under 360). Checkbox: 22px box in a 44px-high label row. Error text sits in a `.message.error` block under the form, never only as a colour.

**Messages.** `.message.error`: 8% `--alert` fill, `--alert` text, 4px `--alert` left rule, bold (CT:570). `.message.ok`: 10% `--ledger` fill, `--ledger` text, 4px left rule (CT:569). Empty and loading rows: one centred muted line in a table cell (`td[colspan].muted`).

**Header and nav.** `--raised` bar, 1px `--line` rule, brand in `--primary`. Nav links 44px high, bold. Under 640px the nav wraps; nothing scrolls sideways.
