# OVERNIGHT RUN — The Hub — 2026-11-19
Business: Xpresso Cafe (The Hub app, central production kitchen).
Repo: Xpresso-100/The-Hub · C:\dev\The-Hub · branch feat/hub-hardening-1 (from f667b8d = live).
Run folder: docs/night/2026-11-19/. Stop time 06:00 SAST.

## STEP 0 — Save this prompt, then preflight, then STOP
1. Write this entire prompt, unchanged, to docs/night/2026-11-19/RUN.md.
   Commit it ("docs(night): run file 2026-11-19", message via a file and -F).
2. Run PREFLIGHT (below).
3. Print "PREFLIGHT OK" or "PREFLIGHT BLOCKED: <what>", then STOP and wait.
   The tasks start only when I set /goal.

## NEVER-STOP RULES (Field Guide v5 section 3, R1–R3, R7, R11, R12)
- No server contact of any kind: no ssh, scp, sftp, ship, ship -DryRun, curl to
  xpressocafe.co.za, phpMyAdmin, FinCon, or the ordering system's database.
  Localhost only. The guard blocks agent ssh (ENFORCED). Never look for a way round it.
- A refused command is never a reason to stop. Log one line to
  BLOCKED-COMMANDS.md (time, command, guard, what you did instead), take the
  nearest local alternative, continue in the same turn. Never try a second
  route round the same guard.
- An open question gets the reversible default, recorded in DECISIONS.md with
  the question for Damian. Never wait for an answer.
- Never edit a guard, hook, permission or anything under ~/.claude. Write a
  proposal to NEEDS-ME.md instead.
- Run docker ps before any docker or port action. Never touch another run's
  containers or ports. Use a free localhost port.
- Kill switch: if docs/night/2026-11-19/AGENT_STOP exists, finish the current
  commit and go straight to T-14.
- Never end the turn before 06:00 unless REPORT.md is written.
- Push only feat/hub-hardening-1. Never main, never --force, never -D, never
  --no-verify, never reset --hard. Commit messages via a file and -F.
- SQL: additive only, written to deploy/sql/04-hardening.sql, never run.
- Never edit acceptance tests A1-A7, SPEC.md or SPEC-locked-decisions.md.
- Test data starts with TEST-NB-. No real names, emails or phones anywhere,
  including screenshots. No secrets in any file; use placeholders.
- POPIA: no new personal data. Login attempts store username + time only, no IP.
- No new libraries, packages, CDN links or web fonts. The playwright-cli and
  webapp-testing skills are switched off: use the repo's own browser test
  setup from golive-F3.

## LOOP RULES
- Verify command: php tests/run.php must end "RESULT: <n> passed, 0 failed"
  (only T-02 may show its one expected red).
- Test first for every behaviour change: show the red run on the old code,
  then green. A test counts only after it has failed (R9).
- Caps: 2 fix attempts and 45 minutes per task. The same fix failing twice →
  write DIAG-<task>.md, revert that task to its start commit, mark it BLOCKED,
  move on.
- Roles: the builder writes code and tests. A SEPARATE tester agent runs the
  suite and browser checks and never edits app code. A SEPARATE reviewer, after
  /clear, reviews. The builder never marks its own work passed.
- After every task: verify, commit "feat|fix|test|docs(T-xx): ...", push the
  branch, update LEDGER.md (done / blocked / deferred + one line of evidence).
- A fix after a gate has passed means re-running that gate on the final head,
  or listing it as "not re-run" (R8).
- Start no new task after 05:15. At 05:15, go to T-14.

## PREFLIGHT
P1 On feat/hub-hardening-1, HEAD f667b8d, status clean, verify passes (164).
   Branches golive-F2, golive-F3, golive-F4 and
   worktree-agent-a7587b12345fa60cf exist.
P2 Run each of these once, and list any that asked for permission: verify; a
   scratch commit via -F then git reset --soft HEAD~1; docker ps; php -S on a
   free port, then stop it; the F3 browser setup taking one localhost
   screenshot; launching a subagent; writing a file in the run folder.
P3 Find the Xpresso franchisee system's code on this laptop, READ-ONLY (look
   under C:\dev and the user's Xpresso-* folders). Print its path and the
   stylesheet file(s) you will take the look from. Not found → say so.
P4 Name the builder, tester and reviewer agents (Bench v5 if they exist,
   otherwise separate subagents).
P5 Create LEDGER.md (all tasks "todo"), DECISIONS.md, NEEDS-ME.md and
   BLOCKED-COMMANDS.md in the run folder.
PREFLIGHT OK only if P1-P5 pass and nothing asked for permission.

## TASKS (in order, only after /goal is set)
T-01 Cherry-pick golive-F2, F3, F4 one at a time, verifying after each.
     A conflict you can't resolve cleanly → skip it, note it in DECISIONS.md.
T-02 Cherry-pick 2baaf37 (the login username timing test). Expect it red.
T-03 Fix the login timing hole (always run the password check). T-02 goes green.
T-04 Failed-login limit: 5 failures for one username in 15 minutes lock it for
     15 minutes; the same message shows for a locked or a wrong login. New
     table in 04-hardening.sql (one statement per block) plus the SQLite test
     version.
T-05 The loader refuses rows that change an existing ingredient's unit, reports
     them, and loads the rest.
T-06 Refuse control characters in names and notes.
T-07 Any capitalisation of APP_ENV "production" counts as production.
T-08 A new user's password is shown once, at creation, never again.
T-09 END-TO-END BROWSER TEST (tester agent, localhost, TEST-NB- data) at
     390x844 and 1366x768: hq, hub and franchise logins; an order goes pending
     → in_process → out_for_delivery → delivered and the ledger is credited
     exactly once; head office cancels from pending and from in_process, a hub
     user cannot; a batch deducts ingredients and shows cost per item in R with
     2 decimals; finished-goods report + CSV; franchise sees stock only; FinCon
     unavailable → orders still work and stock shows "in stock"; the warehouse,
     ingredients, stock and accounts pages work; the T-04 lock and same
     message. Screenshots + a pass/fail table in e2e/. Fix only wave-1 bugs or
     plain breaks against SPEC; anything else → DECISIONS.md.
T-10 UI TOKENS (no screens yet): from the franchisee stylesheet found in P3,
     write design/DESIGN.md (colours with hex, type scale, spacing, radius,
     shadow, button/table/badge/form styles) and design/KIT.md (Type A, PHP
     back-office; CSS-only tonight; no packages; system font stack unless the
     franchisee system self-hosts a font file you can copy under its licence).
     If P3 found nothing: a neutral palette, and record "brand not confirmed"
     in NEEDS-ME.md. Never invent brand colours.
T-11 RESTYLE (CSS and markup classes only; no JS logic, no PHP, no API change):
     apply DESIGN.md to app.css and warehouse.css across the login, index,
     orders, recipes, warehouse, ingredients, stock and accounts pages and the
     report. Clear headings; aligned tables with right-aligned R amounts; one
     consistent button style; status badges for order states; real empty,
     loading and error states; 44 px touch targets; 4.5:1 contrast; nothing cut
     off at 375 px; prefers-reduced-motion respected. No gradients, glows, emoji
     or decorative icons. Screenshot every page at 375x812 and 1440x900, before
     and after, into ui/. Then a SEPARATE critic agent scores before and after
     against that checklist; fix blockers; max 3 rounds. Re-run verify and T-09.
T-12 Gates on the final head, after /clear: /code-review medium;
     /security-review with the base set explicitly to f667b8d (origin/HEAD may
     point elsewhere); a POPIA check of the diff; /verify-gate. Fix real
     defects only, then re-run.
T-13 Wave 2 SPEC DRAFT, no code: docs/SPEC-wave2-DRAFT.md covering short
     deliveries, a per-user "can manage ingredients" switch (Nicole), a change
     history plus a read-only page, and stock count entry. For each: the SPEC
     rule it changes, the cost of not doing it, the question for Jolandi or
     Clyde. Header: "DRAFT — needs business OK".
T-14 SHIP PACK (text only, run nothing) in docs/release/2026-11-20-hardening/:
     the merge command; a table backup, then each 04-hardening.sql statement in
     order; ship hub -DryRun with the expected file list and counts; ship hub;
     the 3 post-ship checks; the rollback command. Update DECISIONS-LOG.md.
     Then REPORT.md in the run folder, one phone screen per section, every
     claim with a file path: Headline · Ready to merge YES/NO · Done · Needs you
     (one-line questions, each with your recommended answer) · Blocked ·
     Decisions (flag the hard-to-undo ones) · Blocked commands · UI before/after
     scores + screenshot paths · Try it this morning · Stats (commits, verify
     line, e2e pass/fail, /usage). Label each gate ENFORCED or INSTRUCTED.
     The LAST line is exactly one of:
     READY-TO-SHIP: <how>   or   NOT READY: <the one thing>
     Push the branch. Stop.
