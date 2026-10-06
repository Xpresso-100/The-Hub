MODULES: serial

SYSTEM: THE FOOD TRUCK - Xpresso-owned retail outlet outside the office. Repo Xpresso-100/Food-Truck.

Modules (small, built serially on main, no worktrees):
- M1-ordering: truck login (location type "truck"), mobile-first; read-only FinCon stock view; place an order from HQ at R0 with the COST value stored on every order line.
- M2-history: order history for the truck user.

Acceptance checks:
- Truck user cannot read other locations' orders.
- Franchise users never see cost prices or truck pricing.
- Truck order lines are R0 and store the cost value.
- Nothing writes to FinCon.
- UI usable at phone width.

E2E smoke flow: truck user logs in on a phone-sized viewport -> views stock -> places an order -> sees it in order history.

Working assumptions (log them): no sales or POS in phase 1. Ordering is on demand.
