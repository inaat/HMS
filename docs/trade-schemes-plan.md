# Trade schemes (trade offers) — end-to-end plan

How big FMCG distributors run "buy X get Y free" offers, built into Fatoora Now in phases.
Each phase is usable on its own and is tested before the next one starts.

**Decisions already made**
- Free goods on the **same product** show on the **same invoice line as a discount** (13 CTN billed, 1 CTN discounted).
- Free goods of a **different product** are a separate line with 100% discount, marked as scheme.
- Offer types: same product free, different product free, supplier bonus on purchases.
- Applies in: POS + Add Sale, booker mobile app, reports.

---

## Phase 1 — Scheme master + POS / Add Sale (core)

**What the user gets**
- New page **Products > Trade schemes**: list, add, edit, copy, switch off.
- A scheme has:
  - Code + name (e.g. `SCH-014 Candy Ramzan 12+1`), start and end date, active on/off.
  - **Applies to:** one product (all its variations) or one variation; unit to count in (CTN / piece).
  - **Slabs:** buy 12 → 1 free, 24 → 3, 50 → 8. The highest slab reached is used; "repeat" option (12+1 repeats: 24 → 2, 36 → 3).
  - **Free item:** same product, or another product + its unit.
  - **Who gets it:** all customers (customer groups are not used); all locations or selected locations.
  - **Funded by:** own (distributor) or supplier/principal company (for claims, phase 3) + supplier name.
  - **Budget (optional):** total free qty allowed; scheme stops when used up.
- **POS and Add Sale:** when a line's quantity reaches a slab, the scheme is applied by itself:
  - same product → line discount equal to the free quantity × price, label under the line
    "Scheme SCH-014 12+1: −Rs 2,400";
  - different product → free line added (100% discount), updated when the main quantity changes, removed when it
    drops below the slab.
  - Staff can remove the scheme from a line (×); a manual discount replaces it.
- **Invoice print:** scheme name and free quantity under the line ("incl. 1 CTN free — SCH-014").
- **Edit invoice / Sales order → invoice:** scheme kept; re-applied if quantities change.

**Data**
- `trade_schemes` (business_id, code, name, starts_at, ends_at, is_active, product_id, variation_id null,
  unit_id, free_mode same|other, free_variation_id, free_unit_id, repeat, location_ids json, funded_by own|supplier, supplier_id, budget_qty, notes, created_by).
- `trade_scheme_slabs` (scheme_id, buy_qty, free_qty) — quantities in the scheme's unit.
- `transaction_sell_lines` + `trade_scheme_id`, `scheme_free_qty` (base units), `scheme_parent_line_id`
  (free line of a different product → the line that earned it).

**Logic (one place, used by POS, Add Sale, sales order conversion and the booker inbox)**
- `TradeSchemeUtil::forSale(location, date)` → active schemes by variation.
- `TradeSchemeUtil::freeQty(scheme, qty_in_scheme_unit)` → slab / repeat result.
- POS JS: on quantity / unit / customer change → recompute; writes discount and hidden fields;
  server re-checks on save (cannot be faked from the browser; budget checked in the same transaction).

**Accounting**
- Same product: sales are recorded net of the scheme discount (like any line discount); stock goes down by the
  full quantity, so cost of goods includes the free goods. Ledger: scheme value posted to a new account
  **4250 Trade scheme discount** (instead of "Discount given") so it can be seen separately.

**Done when**
- 12+1, 24+3 slab, repeat, different-product free, location rule, expired date and budget all work in
  POS and Add Sale; invoice print shows the scheme; stock and totals are correct; editing keeps the scheme.

---

## Phase 2 — Supplier bonus on purchases

- **Add / Edit Purchase:** new column **Free qty** per line (in the line's unit), and an optional supplier scheme
  link.
- Stock goes up by paid + free; **cost per unit = line amount ÷ (paid + free)**, so stock value and profit are right.
- Purchase print and list show the free quantity.
- Purchase returns: free quantity returned first is at zero cost (setting).
- Data: `purchase_lines` + `bonus_qty`, `trade_scheme_id`.

**Done when** a 10+1 purchase adds 11 to stock at the lower cost, and the profit of selling those 11 is correct.

---

## Phase 3 — Reports and claims

- **Scheme report:** per scheme / product / customer / booker / location and date range: invoices, paid qty,
  free qty, free value (at sale price), free cost (at purchase price).
- **Claim report (funded by supplier, e.g. Hilal Foods):** month, supplier, scheme, free qty, claim value; print /
  Excel; mark as *claimed* and *received*. A claim is settled in one of three ways:
  - **credit note** — reduces what we owe the supplier (supplier payable);
  - **cash / bank** — money received into a payment account;
  - **free stock** — the supplier sends goods at zero price: a purchase linked to the claim, stock in at the claim's
    cost.
  Until settled the claim is money the supplier owes us. Ledger: account **1460 Scheme claims receivable**.
- **Budget view:** used vs left per scheme; warning on the dashboard at 80%.
- **Supplier bonus report** (from phase 2): free stock received per supplier.
- Sales and product sell reports get a "scheme discount" column.

**Done when** the claim for a month matches the free goods on that month's invoices, and a received claim clears it.

---

## Phase 4 — Booker app and mobile orders

- Active schemes go to the cloud with the normal sync (`LocalSnapshot` → `mb_schemes`), for the booker's locations.
- `GET /api/mobile/sync` returns `schemes` (with slabs, free item, locations, dates).
- App: product shows a badge "12+1"; in the cart the free quantity / free line is shown; order slip and WhatsApp
  slip show "incl. 1 CTN free (SCH-014)". Works offline from saved schemes.
- Office: **Mobile orders** shows the scheme per line; on approval / *Make invoice* the server re-checks
  (dates, budget, location) and applies the same scheme — the office sees a warning if the phone's scheme no
  longer applies.
- Prompt for the mobile app developer (same style as earlier prompts) + updated `booker-mobile-app-prompt.md`.

**Done when** a booker order with 12+1 becomes an invoice with the same free quantity, also when booked offline.

---

## Phase 5 — Polish and rollout

- Roles: who can create schemes, who can remove a scheme from a line (permission `scheme.override`).
- Activity log for scheme create / edit / override.
- Help text on the scheme page; short guide in Urdu/English.
- Data check: run on a copy of the live database, compare totals of a day with and without schemes.
- Backup before migration; migrations are additive only (no existing data changed).

---

## Order and size

| Phase | Main parts | Depends on |
|---|---|---|
| 1 | scheme page, POS / Add Sale auto-apply, invoice print, ledger account | — |
| 2 | purchase free qty and cost | — |
| 3 | scheme, claim, budget reports | 1 (2 for supplier bonus report) |
| 4 | booker sync, app prompt, mobile order invoicing | 1 |
| 5 | permissions, log, guide, live-data check | 1–4 |

Phases 1 → 2 → 3 → 4 → 5 is the suggested order; 2 can also go first if supplier bonus is more urgent.
