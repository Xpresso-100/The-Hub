# DECISIONS - overnight run 2026-11-19

Each entry: the open question, the reversible default taken, the question for Damian. Hard-to-undo ones are marked HARD.

## D1 (16:55): the branch is not at f667b8d. T-01 to T-08 and T-13 are already built.
- Found: HEAD was 085160f, 10 commits past f667b8d. `git cherry -v HEAD <x>` shows `-` (already applied) for golive-F2 (950245d), golive-F3 (f4c093b), golive-F4 (10d527b) and 2baaf37 (86d0224). Hardening code is in a9c8195 (tests first), 2323fac, 3e1d6a5 and 085160f. `deploy/sql/04-hardening.sql` and `docs/SPEC-wave2-DRAFT.md` (0ce7e66) exist.
- Default: no re-cherry-picks and no rebuilds. Re-applying would create empty or duplicate commits. Each of T-01 to T-08 is checked instead: (a) red proof, where HEAD's test files run on the pre-fix code in a detached scratch worktree (a9c8195, or 2baaf37 for T-02/T-03; `git diff a9c8195 HEAD -- tests/` is empty, so these are the same tests); (b) green on HEAD; (c) a tester agent's run. The LEDGER marks them "done (pre-existing)" with the commit.
- Question for Damian: was this prompt written before this afternoon's session built wave 1? If you wanted a rebuild from f667b8d, say so. That would mean a new branch, not a rewrite of this one.

## D2 (16:55): P1 "verify passes (164)"
- Default: the count is wrong because HEAD is ahead. The pass condition I use is "0 failed" on HEAD.

## D3 (16:55): fonts for T-10
- Found: the franchisee system loads Bricolage Grotesque, Hanken Grotesk and JetBrains Mono from the Google Fonts CDN. It self-hosts no font files and keeps no licence file (Explore report, FCT repo `C:\dev\franchisee-onboarding-system`).
- Default: the system font stack, per RUN.md T-10 and its no-CDN, no-web-font rule. Tabular numbers come from `font-variant-numeric: tabular-nums`.
- Question for Damian: should the Hub ever load those three fonts? They are OFL, but self-hosting them needs a Tier 0 / compliance-gate entry first.

## D4 (17:20): T-09 E4, the batch cost per item has no "R"
- Found by the T-09 builder: `public/assets/recipes.js:162` prints "... total 134.16, cost per item 5.59." The amounts and the deductions match SPEC.md:380 (134.16 / 5.59). Only the rand marker is missing. SPEC s8 does not require "R", so this is not a SPEC break.
- Default: not fixed. T-09 allows fixing only wave-1 bugs or plain SPEC breaks. `recipes.js` is also on the never-edit list in `docs/BUILD-SPEC-2026-11-16-live-ops.md:71`, and T-11 forbids JS changes. E4 stays FAIL in e2e/ (honest), with this as the reason.
- Question for Damian: add "R " to the batch message and "(R)" to the batch table headers in a small approved follow-up (it touches the never-edit `recipes.js`)? Recommended: yes, in wave 2.

## D5 (17:20): T-11 vs the never-edit list
- RUN.md T-11 says to restyle the recipes pages and the report. `docs/BUILD-SPEC-2026-11-16-live-ops.md:71` says never edit `public/recipes.html`, `public/reports.html`, `public/assets/recipes.js` and `public/assets/reports.js`.
- Default: the approved build spec wins (CLAUDE.md truth order). Those 4 files are untouched, and the two pages change only through the shared `app.css`. They are still screenshotted before and after.
- Question for Damian: lift the never-edit list for markup-only class changes on those two pages? Recommended: not until the recipes/reports module is un-hidden (BUILD-SPEC s9).
