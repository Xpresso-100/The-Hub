
## Build rules - Hub/Truck sprint
- This repo is ONE business, built from empty by an automated pipeline (.pipeline/run-pipeline.ps1). Each node gets its own instructions on stdin.
- Do not ask the human questions. Make the documented working assumption, append one line to DECISIONS-LOG.md, keep going.
- The existing Xpresso ordering system (--add-dir) is a READ-ONLY reference. Match its stack and patterns. Never edit it.
- Never write to FinCon. Read only, behind an interface with a mock for tests.
- Never invent credentials, tunnel URLs or API keys. Placeholders go in .env.example. Never read .env.
- Never push and never deploy. The pipeline script and the human do that.
- tests/acceptance is READ-ONLY for everyone except the G1 node. The pipeline reverts edits to it.
- The agent that wrote code never grades it.
- "Done" is decided by the pipeline running the full test command, not by what you say.
- Stay inside your module's file list. Need a file outside it: write "LANE BREACH: <file> - <reason>" in progress.md and stop that module.
- Never run: git stash, git reset --hard, git push.
