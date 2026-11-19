# DRAFT — needs business OK

**DECISION NEEDED: which of four Hub changes to build after wave 1, by [date to be set by Damian].**

**Hub hardening, wave 2: build-spec draft (internal). Nothing here is built or approved.**
Drafted 19 Nov 2026 on `feat/hub-hardening-1` (from `f667b8d`, live).

| Summary | |
|---|---|
| Answer | Four changes are specified below; each needs a business answer before any code. Suggested order: items 2+3, then 4, then 1. |
| ZAR at stake | [Unverified]: no data on Hub stock losses or staff time. No figure is invented. |
| Payback | [Unverified]: cannot be computed without that data. Needs a figure from Jolandi or Clyde. |
| Owner | Jolandi (stock and delivery rules), Clyde (permissions, retention, POPIA). Build owner not yet assigned. |
| Cost of a week's slip | [Inference] low: all four are gaps in manual practice today, none is a live fault. |

**The ask:** decide whether to build four changes after wave 1, and answer the question under each.
Each one changes a rule in `SPEC.md`. Under the repo rules, a behaviour change needs an approved build
spec in `docs/` before any code is written, and `SPEC.md` / `SPEC-locked-decisions.md` are not edited.
If approved, this draft becomes `docs/BUILD-SPEC-<date>-hardening-2.md` and the SPEC rules named below are
superseded there, not edited in place.

**Not decided by this draft:** priority, order, dates, or who builds. No rand figures appear because I have
no cost data for the Hub's stock losses or staff time. Where a "cost of not doing" is stated, it is
reasoning from the SPEC, marked [Inference], not a measurement.

Common constraints for all four (from `CLAUDE.md`):
- Never write to FinCon or the ordering system's database. Money stays integer cents, quantities thousandths.
- Franchise users get no new access. Hub users never read other locations' orders.
- SQL is additive only, in a numbered file in `deploy/sql/`, run by hand in phpMyAdmin after a backup.
- POPIA: the app holds staff username, email and password hash only. Items 1 to 3 below would add more
  (who did what, when). Each is flagged and needs `popia-data-protection` review before build.
- Cancel rule (confirmed 19 Nov): only head office cancels orders. Nothing here changes that.

---

## 1. Short deliveries (quantity received is less than ordered)

**What:** let the quantity actually received be recorded per order line, so stock on hand reflects what
arrived, not what was ordered.

**SPEC rule it changes:** s7.7. Today, on the transition to `delivered`, one `inventory_movements` row per
line adds the full ordered quantity, exactly once. Also touches s7.4 (the order total is the ordered
quantity times the cost snapshot) and the s8.5 `on_hand` figure that follows from it.

**Cost of not doing:** [Inference] if a delivery arrives short, `on_hand` is overstated by the shortfall
until someone makes a manual adjustment (s8.6) and remembers why. Recipes and batches then draw on stock
that is not there, and batch costs are unaffected but availability is wrong. Today there is no field to
say "short" at all.

**Open design points (need answers, not assumed):**
- Does the order total (cost, at cost price) stay as ordered, or drop to the received quantity?
- Who records receipt: the Hub user who receives the goods, or head office when it marks `delivered`?
  Hub users have no write on orders today, so the first answer adds a Hub write route.
- Can received be more than ordered (over-delivery)? Can a line be received in two drops?
- Schema (additive): one new nullable column on `order_lines` for received quantity (thousandths).

**Question for Jolandi:** when a delivery arrives short, does the Hub still owe the full ordered amount, and
does the missing quantity get re-sent or written off?
**Question for Clyde:** is head office or the Hub the system of record for what was received?

**POPIA:** if the receipt records who entered it, that is a new personal-data field (user id). Prefer
recording the location only.

---

## 2. Per-user "can manage ingredients" switch (for the nominated ingredient manager, N.)

**What:** a flag on a user that lets that one user create and edit ingredients (name, unit, cost, active),
without making them head office.

**SPEC rule it changes:** s1 (role table says only `hq` manages ingredient cost prices) and s7.3
(`PUT /api/admin/ingredients/{sku}` is `hq` only). It is a per-user exception to "role = location type".

**Cost of not doing:** [Inference] every ingredient price or name change has to go through a head-office
user, or N. is given a head-office account, which gives them every other head-office power (accounts,
order status, all locations). The switch is the narrower option.

**Open design points:**
- Which role may hold the switch: Hub users only, or also head office (already allowed)? Franchise users
  must never get it (A2 and the "no new access" rule), enforced on the server, not only in the page.
- Can the cost price be edited by the switch holder, or only name/unit/active? Cost drives every future
  order and batch cost (snapshots protect the past, not the future).
- Who can grant and revoke the switch? Proposed: head office only, from the accounts page.
- Deactivated users lose it automatically (accounts are deactivated, never deleted).
- Schema (additive): one new column on `users`, default 0.
- Wave 1 refuses a unit change on a loader row; this switch must not become a back door for the same
  change. Whether a unit change by a switch holder is allowed at all is a question for Clyde.

**Question for Clyde:** may N. change cost prices, or only names and the active flag?
**Question for Jolandi:** is there a second person who should hold the same switch?

**POPIA:** the flag is a permission on an existing account, not new personal data.

---

## 3. Change history and a read-only history page

**What:** record each change to ingredients (and optionally accounts and order status), and show it on a
read-only page for head office.

**SPEC rule it changes:** none of the existing rules directly. It adds a table and a page. It does change
the s7.3 note "Changing a cost never changes existing order lines or batches" only by making the change
visible. It is the natural companion to item 2 (more people can edit, so there should be a trail).

**Cost of not doing:** [Inference] with item 2 built and no history, a wrong price cannot be traced to a
change, a time or a person. Today the only trail is the server's error log (`HUB-AUDIT` lines for account
creation) and ingredient `updated_at`, which says when but not what or by whom.

**Open design points:**
- What is recorded: field, old value, new value, time, and who. Recording "who" is the personal-data
  question. Options: user id (links to an account), or location only (no individual).
- Retention: how long, and who owns it. Decision 4 (19 Nov) already says retention and owner for
  deactivated accounts are set by Clyde. Proposed: the same owner and the same period for this log.
- Append-only in the app: no edit or delete route. Cleanup, if any, is a by-hand job.
- Page access: head office only. Hub and franchise users get 403.
- Schema (additive): one new table.

**Question for Clyde:** retention period, owner, and whether the log may name the individual user or only
the location.
**Question for Jolandi:** which changes must be traceable (prices only, or names, units and the active flag
too)?

**POPIA:** HIGH relevance. A change log with user ids is personal information about staff. Needs
`popia-data-protection` before any build.

---

## 4. Stock count entry

**What:** a screen where a Hub user enters the counted quantity per ingredient, and the system records the
difference as an adjustment, instead of the user working out `qty_delta` by hand.

**SPEC rule it changes:** s8.6. `POST /api/inventory/adjustments` already says it is "for opening balances
and stock counts", but takes a delta, and the reason is free text. A count entry takes the counted figure,
computes the delta on the server, and labels the movement as a count (a new `reason` value, so
`inventory_movements` can tell a count from a correction).

**Cost of not doing:** [Inference] counts are done today by calculating a delta and typing a reason. A wrong
sum is recorded as a real movement, and nothing separates a count from any other adjustment when someone
later asks why `on_hand` changed.

**Open design points:**
- A count is a snapshot at a moment: what happens to orders delivered while the count is open?
- Does a large difference need a second person to approve, or just a reason?
- Whole-location count only, or per-ingredient?
- Negative `on_hand` is allowed today (s8.5). Should a count be able to fix it silently?
- Reuses the existing movement table. Schema change only if the new reason value needs a column or enum
  update (to be checked against `db/schema.mysql.sql`).
- Hub users count their own location only. Head office may count any hub (with `location_id`), as in s8.6.

**Question for Jolandi:** how often does the Hub count, and who counts?
**Question for Clyde:** does a count above a set difference need head-office sign-off?

**POPIA:** no new personal data if the movement keeps recording the location only. If it records the
counting user, flag as in item 3.

---

## Suggested order, if all four are approved [Inference]

1. Item 2 and item 3 together (permission plus its trail), because item 2 without item 3 widens who can
   change cost prices with no record.
2. Item 4 (self-contained, reuses an existing table).
3. Item 1 last: it changes the delivery rule that stock depends on, so it carries the most risk, and
   needs the most business input.

## What this draft does not cover

- The login timing fix, failed-login limit, loader unit refusal, control characters, APP_ENV and
  one-time password (wave 1, in progress on this branch).
- Retention and anonymisation of deactivated accounts (decision 4, Clyde to set the period and owner; still
  needs its own approved build spec).
- Any change to FinCon, VAT, pricing, or the franchise ordering system.
