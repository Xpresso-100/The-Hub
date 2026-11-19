# The Hub: repo rules (Xpresso Cafe central production kitchen)

Last reviewed 16 Nov 2026. This repo is ONE business: The Hub app for Xpresso Cafe. It is not The Hub Distributors and not the Food Truck (separate repo).

## Who governs what
- Build, git and deploy rules live in `~/.claude/CLAUDE.md` and `~/.claude/rules/` (Bench v5). They are the source of truth. This file adds Hub-specific rules only and must not restate or contradict them.
- Merging to `main` and deploying to the server are Damian's decisions, attended, after his explicit yes.
- The old pipeline rules ("do not ask the human", "never push", "never deploy") were removed on 16 Nov 2026 because the pipeline no longer builds this repo.

## Truth order
1. `SPEC-locked-decisions.md` (confirmed business rules) wins over everything.
2. `SPEC.md`, then `DECISIONS-LOG.md` (each line is "review with the business", not a confirmed fact).
3. Any `docs/BUILD-SPEC-*.md` that Damian has approved describes changes made after the 6 Oct 2026 build.
- Do not edit `SPEC.md` or `SPEC-locked-decisions.md`. A behaviour change needs a written build spec in `docs/` that Damian approved before code is written.

## Hub rules (these hold for every change)
- Never write to FinCon. Read only, behind the interface with a mock for tests (acceptance test A6 checks this).
- Never write to the existing ordering system's database. Its code is a read-only reference.
- Franchise users never see cost prices, recipes or Hub pricing, and get no new access (acceptance test A2).
- Hub users never read other locations' orders (acceptance test A1).
- Hub orders are at cost, excluding VAT, with no VAT added. Money is integer cents, quantities are thousandths, rounding follows SPEC s3.1.
- Never invent, print or commit credentials, tunnel hostnames or API keys. Real values live in `config.local.php` on the server, filled by hand. Never read `.env`.
- POPIA: the app holds staff username, email and password hash only. No customer data. Flag any change that adds personal data.
- Label anything unconfirmed as [Inference], [Speculation] or [Unverified]. The live PHP version, live MySQL version and live email sending are [Unverified] until checked.

## Tests and frozen files
- `php tests/run.php` must end `RESULT: <n> passed, 0 failed`.
- Never edit or delete an existing test. If one fails, fix the code. `tests/acceptance/` is read-only.
- The agent that wrote code does not grade it. "Done" is the test command and the gates in the build spec, not a claim.

## Working in parallel lanes
- A lane writes only the files its build spec gives it. Shared files are edited by the Coordinator alone. Out of lane: write `LANE BREACH: <file> - <reason>` in the lane notes and stop that lane.
- Never run `git stash` or `git reset --hard`: lanes share this repository's object store.

## Deploying
- Files go to `public_html/hub/` on xneelo; the app is at `https://xpressocafe.co.za/hub/public/`.
- A database change is the exception, not the rule: back up first, one statement at a time in phpMyAdmin, per `deploy/DEPLOY.md`.
- After any deploy, check `/api/health`, a login, and that private files answer 403.
- Any change under public/, src/, db/ or bin/ ships only after `deploy/upload/hub` is synced (run `php deploy/sync-bundle.php`: it copies and never deletes; it prints EXTRA and exits 1 if the bundle holds a file with no source) and the Deploy parity test passes.
