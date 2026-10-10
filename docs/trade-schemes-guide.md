# Trade schemes — short guide (English / Roman Urdu)

## 1. Make a scheme — Products > Trade schemes > Add scheme
- **Code + name**: e.g. `SCH-014`, "Hilal Candy 12+1". **From / To**: dates (empty = always).
- **Product**: what is bought. **Counted in unit**: CTN or piece.
- **Slabs**: buy 12 → 1 free; add more: 24 → 3. **Repeat** on: 24 → 2, 36 → 3.
- **Free item**: *Same product* (discount on the same line) or *Another product* (free line).
- **Locations** (empty = all), **Funded by**: Own or Supplier (e.g. Hilal — you claim it back), **Budget**: total free
  qty (empty = no limit).

*Urdu:* Scheme ka code aur naam likhein, product chunein, unit (CTN), slab (12 par 1 free). Agar company (Hilal) paise
wapas degi to "Funded by: Supplier" chunein. Budget khatam hone par scheme khud band ho jati hai.

## 2. Selling — POS / Add Sale
- Enter the quantity. When it reaches a slab the scheme is applied by itself: green label under the product
  ("SCH-014 (12+1): 1 CTN free = Rs 3,000 off"), or a FREE line for another product.
- **×** on the label removes the scheme from that line. A discount typed by hand replaces it.
- Invoice print: **Free qty** column + scheme name.

*Urdu:* Quantity likhte hi scheme khud lag jati hai. Hatani ho to label par × dabayein.

## 3. Booker orders
- Bookers see the scheme on the phone (badge "12+1"). The office applies it when the order becomes a sales order /
  invoice (Sell > Mobile orders) — the booker does not give discounts himself.

## 4. Supplier bonus on purchases — Purchases > Add purchase
- Under **Purchase quantity** type **Free qty** (e.g. 10 + 1 free). Stock goes up by 11, cost per unit = amount ÷ 11.
- Purchase view shows "incl. 1 free (supplier bonus)".

*Urdu:* Company 10 par 1 muft de to Free qty mein 1 likhein. Stock 11 barhega aur cost kam ho jayegi.

## 5. Reports and claims
- **Reports > Trade scheme report**: free goods by scheme / product / customer / invoice, at sale price and at cost;
  own cost vs cost to claim from suppliers.
- **Reports > Scheme claims**: choose the month → "Create claim" per supplier → print and send → when the supplier
  settles, **Mark as received**:
  - *Credit note*: what you owe the supplier goes down (shows in the supplier ledger);
  - *Cash / bank*: money added to the chosen account;
  - *Free stock*: type the qty the supplier sent per product (in CTN etc.) and the location — it is added to stock
    at cost as a purchase (ref = claim no) **paid by the claim**: no money moves, the supplier balance does not change.
  Whatever the supplier did not approve / did not send shows as "Not approved — our own cost".
  **Undo settlement** removes the credit note / deposit / stock purchase again (stock only while none of it is sold).

*Urdu:* Kuch company paise deti hain (Cash / bank), kuch maal (Free stock). Maal aaye to "Free stock" chunein, har
product ki aayi hui quantity likhein — stock khud barh jayega aur supplier ka balance nahi badlega.
- Dashboard warns when a scheme has used 80% of its budget.

*Urdu:* Mahine ke aakhir mein "Scheme claims" kholein, supplier ka claim banayein, print kar ke bhejein. Paise ya credit
note milne par "Mark as received" karein.
