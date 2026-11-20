# REPORT - overnight run 2026-11-19 (The Hub, feat/hub-hardening-1)

Written 2026-11-20 05:56 SAST. Head at writing: 3c3f99f (plus this report commit).

## Tasks
| Task | Result |
|---|---|
| T-01 to T-08 | done (pre-existing, proved red then green; proof/T01-T08.md, DECISIONS D1) |
| T-09 end-to-end browser test | done, 1 check fails: E4 batch message lacks "R" (D4) |
| T-10 UI tokens | done (db55237) |
| T-11 restyle | done, critic 10/20 -> 18/20, 0 blockers (a9798c0, bfa13db, d638a63, 1ca7f26) |
| T-12 gates on final head | partial: security review and POPIA check done; /code-review and /verify-gate not run (past the 05:15 cut-off, tool-call cap tripped from about 17:45 until 05:55) |
| T-13 wave 2 spec draft | done (pre-existing, 0ce7e66) |
| T-14 ship pack + REPORT | done: docs/release/2026-11-20-hardening/SHIP.md and this file |

## Verify
`php tests/run.php` on 1ca7f26: `RESULT: 202 passed, 0 failed`. Not re-run on the final docs-only head (no code changed after 1ca7f26).

## Gates (T-12)
- Security review (base f667b8d): no findings at confidence 8 or above. Note: the login lockout fails open until `login_attempts` exists (src/Auth/Auth.php:158-161, 178-182), so run 04-hardening.sql before ship.
- POPIA: PASS WITH NOTES. N1 the username key is an unsalted sha256 (Auth.php:143); an HMAC with a server secret is better. N2 old login_attempts rows are purged lazily on login. N3 DECISIONS.md D1 names the operator; replace with "the operator" (not yet applied).
- /code-review medium and /verify-gate: not run.

## Open, needs the operator (NEEDS-ME.md)
1. W9 browser check red since the T-01 cherry-pick: test-ordering clash, not an app defect (D6). Approve moving W9 before W7.
2. E4: add "R" to the batch message in recipes.js:162, a never-edit file (D4).
3. Confirm the ship registry 'franchisee' entry.
4. Loop cap: this run hit the 250-call cap and sat blocked for hours. Proposal for harness-engineer: a per-run cap sized to the run, and a note in RUN.md that the `!` shell is Git Bash (`rm -f ~/.claude/hooks/loopcap-*.json`).

NOT READY: W9 browser check (D6) and E4 rand marker (D4) need operator approval, and /code-review plus /verify-gate were not run on the final head
