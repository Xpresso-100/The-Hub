MODULES: M1-ordering,M2-recipes,M3-reports

SYSTEM: THE HUB - Xpresso's central production kitchen (makes food for all stores). Repo Xpresso-100/The-Hub.

Modules (independent, built in parallel worktrees after the scaffold):
- M1-ordering: Hub login (location type "hub"); read-only FinCon stock view; order ingredients from HQ at COST price; order history. Orders reach HQ by the method CODEBASE_MAP.md chose.
- M2-recipes: recipe = finished item -> ingredients + quantities (seed 2-3 sample recipes marked SAMPLE); record a production batch (item + quantity) that deducts ingredients and calculates cost per finished item.
- M3-reports: "finished goods for HQ" report (what was made, quantity, cost per item); admin list of recipes and batches.

Acceptance checks:
- Hub user cannot read other locations' orders.
- Franchise users never see cost prices or recipes.
- Hub order lines use cost price.
- A production batch deducts the right ingredient quantities; cost per item matches a hand-calculated sample.
- Finished-goods report totals match the batches.
- Nothing writes to FinCon.

E2E smoke flow: Hub user logs in -> views stock -> places an ingredient order -> records a production batch -> opens the finished-goods report.

Working assumptions (log them): finished food goes into HQ stock and stores order it from HQ as normal. Phase 1 does not post finished goods into FinCon.
