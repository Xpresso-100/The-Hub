# Decisions - Hub + Food Truck (Oct 2026)

## What these are
- THE HUB = Xpresso's central production kitchen. It makes the food that all the stores sell.
- THE FOOD TRUCK = an Xpresso-owned retail outlet parked outside the office, selling Xpresso products to the public.
- Two separate businesses, two separate GitHub repos, two separate apps. They share no code.
  - github.com/Xpresso-100/The-Hub
  - github.com/Xpresso-100/Food-Truck
- Both repos start EMPTY. These are new apps, built to match the existing Xpresso franchisee ordering system (same stack, same login approach, same FinCon access) and to plug into it.

## Confirmed by Jolandi
- Both sit inside Xpresso's books. Xpresso owns the truck.
- Every location receives stock from ONE place only: Xpresso HQ warehouse.
- Pricing: franchise = franchise price (unchanged); hub = cost price; truck = R0, but every order line stores the cost value.
- Hub and truck orders are internal transfers, not external VAT invoices.

## Working assumptions (build as written unless Jolandi says otherwise)
- A1. Hub flow: Hub orders ingredients from HQ at cost -> makes food -> finished food goes into HQ stock -> stores order it from HQ as normal.
- A2. Phase 1 never writes to FinCon. Read only.
- A3. Hub recipe module is built with 2-3 sample recipes. Real recipes loaded when Jolandi supplies them.
- A4. Truck phase 1 = ordering stock from HQ only. No sales / POS.
- A5. Ordering is on demand, same as franchisees.

## Rules (required tests)
- Franchise users never see cost prices, recipes, or hub/truck pricing.
- Hub and truck users never read other locations' orders.
- Hub and truck get the same FinCon stock visibility as franchisees, via the existing Cloudflare Tunnel.
