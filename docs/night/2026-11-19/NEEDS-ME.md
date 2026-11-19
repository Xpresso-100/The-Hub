# NEEDS-ME - overnight run 2026-11-19 (append only)

Anything that needs a human: approvals, server steps, guard proposals.

- [16:52] Confirm the ship registry's 'franchisee' entry points at `C:\dev\franchisee-onboarding-system` (agents cannot read the ship hook folder: guard DEPLOY-ENTRY). Recommended answer: yes. That repo's DEPLOY.md stages from `orders\onboarding\`, and the other copies are its worktrees.
- [17:45] Approve a one-test edit to `tests/browser/run.cjs` so W9 runs before W7 (DECISIONS.md D6)? Recommended answer: yes. W9 has been red on this branch since the T-01 cherry-pick, and tests are frozen without your OK.
- [17:45] Add "R" to the batch cost-per-item message (`public/assets/recipes.js:162`, a never-edit file; DECISIONS.md D4)? Recommended answer: yes, as part of wave 2.
