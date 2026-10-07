# Build: "Order Booker" mobile app (Flutter, Android first)

You are building a native mobile app for **order bookers** (field sales staff) of a wholesale business that runs
UltimatePOS (Laravel). The backend API already exists and is live; **do not change the server**. The app must work
**fully offline** and sync when there is internet. A working web version of this app exists at
`https://pos.explainerkhan.com/booker/` — use it as the behaviour reference; this app replaces it on phones.

---

## 1. What the booker does

1. Logs in with their POS username and password.
2. Sees a **dashboard**: today's booked sales, today's cash collected, orders waiting at the office, items not sent,
   total customers, total dues, products, this month's totals, highest-due customers, recent work.
3. Books **orders** for shops (customers): pick customer → add products (in a unit such as `CTN 24` or `Pieces`),
   quantity, price (editable) → save. Gets an **order slip** to print or send on WhatsApp.
4. **Collects payments** from customers (cash / cheque / bank transfer), optionally against specific unpaid
   invoices. Gets a **receipt**.
5. **Adds new customers** (shops) in the field.
6. Sees the **status** of every order/payment: not sent → sent → at office → approved / invoiced / rejected (with
   the office's reason).
7. Follows **today's route**: the shops of the route(s) planned for this weekday, in visit order, with ✓ for shops
   already visited today and a **Go** button (Google Maps directions).
8. **Checks in** at a shop (GPS compared with the shop's saved location, optional photo), books / collects, then
   **leaves the shop** with the result (order, payment, or a reason why there was no order).
9. **Edits shops**: sets the shop's GPS location ("Set location here"), takes a shop photo, corrects mobile, address,
   city, route, shop type and class. Empty details are filled at once; changes to existing details wait for the
   office's approval.

The booker never creates invoices or changes stock; the office approves orders and payments on the shop PC.

## 2. Tech stack (required)

- Flutter (latest stable), Dart null-safety. Android 8+ first; keep iOS-compatible.
- Local database: **SQLite** (`sqflite` or `drift`). All data the screens show comes from SQLite.
- HTTP: `dio` with timeouts (connect 15 s, receive 120 s).
- State: Riverpod (or Bloc — be consistent).
- Token storage: `flutter_secure_storage`.
- Connectivity: `connectivity_plus` + a real request to decide "online".
- Background sync: `workmanager` (every 15 min when online) + sync on app open, on reconnect, after every save,
  and a **Sync** button.
- Printing: Bluetooth thermal printers (ESC/POS, 58 mm and 80 mm) via `esc_pos_utils` + a Bluetooth plugin; also
  "Share as text" and "Share as PDF".
- UUIDs: `uuid` package (v4).
- GPS: `geolocator` (high accuracy, 20 s timeout; ask permission with a clear explanation). Camera: `image_picker`
  (camera source) + resize to max 1280 px, JPEG quality ~70 (`flutter_image_compress`). Maps links: `url_launcher`.
- Brand colour `#2E9E6A` (green), white cards, rounded 12 px. Clean, large touch targets; usable with one hand.

## 3. Server API

Base URL (configurable in a settings screen, default): `https://pos.explainerkhan.com/api/mobile`

All requests: `Accept: application/json`, `Content-Type: application/json`.
After login, send `Authorization: Bearer <token>`.
Dates are strings `YYYY-MM-DD HH:mm:ss` (server local time). Money/quantities are decimal strings or numbers —
parse as decimals, never as int.

**Errors:** non-2xx returns `{"message": "..."}` (422 validation, 404, 500). **401 = token expired or the booker was
blocked** → go to login, but **keep all unsent data** (outbox). Login is rate-limited (10/min).

### 3.1 POST `/login`
Request: `{"username": "booker1", "password": "…", "device_name": "Samsung A15"}`

Response 200:
```json
{
  "token": "64-char-token",
  "user": {"id": 11, "name": "Booker One", "username": "booker1", "code": "BOO11"},
  "locations": [{"id": 1, "name": "Raheem Dad Traders"}, {"id": 2, "name": "Warehouse 2"}],
  "next_order_seq": 4,
  "next_receipt_seq": 2,
  "business": "Raheem Dad Traders",
  "server_time": "2026-10-07 13:05:00"
}
```
Response 422: `{"message": "Wrong username or password"}`

`code` + seq make the slip numbers (see §5.6). If a *different* user logs in on the same phone and the outbox is not
empty, warn before wiping the previous booker's data.

### 3.2 GET `/sync?since=<server_time from the previous sync>`
Omit `since` on the first sync (full download). Response 200:
```json
{
  "server_time": "2026-10-07 13:10:00",
  "business": "Raheem Dad Traders",
  "stock_updated_at": "2026-10-07 13:09:41",
  "locations": [{"id": 1, "name": "Raheem Dad Traders"}],
  "stock": {"4": 669, "1": -580, "88": null},
  "stock_by_location": {"1": {"4": 669, "1": -580, "88": null}, "2": {"4": 299}},
  "products": [{
    "variation_id": 4, "product_id": 4, "name": "CHORAN CHATNI RS 2.5", "sku": "0004",
    "unit": "Pc(s)",
    "units": [{"id": 1, "name": "Pieces", "multiplier": 1, "allow_decimal": 0},
              {"id": 14, "name": "CTN 12", "multiplier": 12, "allow_decimal": 0}],
    "category": null, "brand": "HILAL",
    "price": "130.0000", "loc_price": {"1": 130, "2": 130},
    "enable_stock": 1, "image": null, "active": 1
  }],
  "customers": [{
    "id": 52, "local_id": 57, "uuid": null, "name": "SKY LINE TREDERS UPPER DIR", "business_name": null,
    "mobile": "03171414003", "address": null, "city": null, "credit_limit": null,
    "balance_due": "678887.0000", "status": "active",
    "route_id": 1, "position": "34.8123456,71.8234567", "photo_url": "uploads/booker/<uuid>.jpg",
    "outlet_type": "Bakery", "outlet_class": "B", "visit_sequence": 1
  }],
  "routes": [{"id": 1, "name": "Khwaza Khela Bazar", "location_id": 1, "days": [3], "booker_id": 11}],
  "outlet_types": ["Kiryana", "General store", "Wholesale", "Medical store", "Bakery", "Super store", "Hotel / Restaurant", "Other"],
  "visit_radius_m": 100,
  "invoices": [{
    "id": 15763, "contact_id": 57, "invoice_no": "15479", "transaction_date": "2025-12-20 08:49:00",
    "final_total": "826805.0000", "paid": "147918.0000", "due": "678887.0000", "active": 1
  }],
  "orders": [{
    "uuid": "…", "number": "BOO11-0004", "status": "invoiced", "local_so_no": "2026/0003",
    "invoice_no": "28135", "reject_reason": null, "short_stock": 1, "total": "10680.0000",
    "location_id": 1, "created": "2026-10-07 09:12:09", "customer_name": "SKY LINE TREDERS UPPER DIR"
  }],
  "payments": [{
    "uuid": "…", "number": "BOO11-R-0001", "status": "approved", "local_ref": "SP2026/44963",
    "reject_reason": null, "amount": "5000.0000", "method": "cash",
    "created": "2026-10-07 09:20:00", "customer_name": "SKY LINE TREDERS UPPER DIR"
  }]
}
```
Rules for applying it (upsert into SQLite):
- `products`: key `variation_id`. `active = 0` → delete locally.
- `customers`: key = `local_id` if set, else `uuid`. `status = "deleted"` → delete. When a row arrives with both
  `uuid` and `local_id`, it is a customer this phone created that the office has now saved → replace the uuid-keyed
  row with it. `status = "pending"` = added by a booker, not yet at the office (show a "new" badge).
- `invoices`: key `id` (unpaid invoices of customers). `active = 0` → delete.
- `orders` / `payments`: this booker's own items; merge status fields into local history by `uuid` (create the row
  if missing, e.g. after reinstall).
- `stock` / `stock_by_location`: **always the full map; replace**. Values are **free quantity in the product's base
  unit** (already minus orders not yet invoiced). `null` = stock not tracked.
- `routes`: **always the full list of active routes; replace**. `days` are weekdays 1 = Monday … 7 = Sunday.
  `booker_id` null = not assigned to anyone.
- Customer `position` is `"lat,lng"` (null = no location yet). `photo_url` is a path on the server: show it from
  `https://pos.explainerkhan.com/<photo_url>` (cache the image for offline). `mobile` may be null.
- `outlet_types` and `visit_radius_m` (metres; a check-in further than this from the shop is flagged): replace.
- Save `server_time` and use it as the next `since`.

### 3.3 POST `/upload` — send the outbox
```json
{
  "customers": [{"uuid": "…", "name": "NEW SHOP", "business_name": null, "mobile": "03001234567",
                 "address": "Main bazar", "city": "Dir",
                 "route_id": 1, "outlet_type": "Kiryana", "outlet_class": "C",      // optional
                 "position": "34.8123456,71.8234567",                                 // optional
                 "photo": "<base64 JPEG>"}],                                          // optional
  "customer_updates": [{                         // edits of EXISTING shops (only changed fields)
    "uuid": "…", "contact_id": 57, "created": "2026-10-07 13:22:00",
    "fields": {"mobile": "03451234567", "city": "Shin", "route_id": 1, "outlet_type": "Bakery", "outlet_class": "B",
               "position": "34.8123456,71.8234567", "accuracy_m": 12},
    "photo": "<base64 JPEG or null>"
  }],
  "visits": [{                                   // one per finished visit (sent after "Leave shop")
    "uuid": "…", "contact_id": 57,               // OR "customer_uuid"
    "route_id": 1, "started_at": "2026-10-07 10:32:00", "ended_at": "2026-10-07 10:41:00",
    "lat": 34.8124, "lng": 71.8235, "accuracy_m": 10, "distance_m": 7,  // lat/lng null = no GPS
    "photo": "<base64 JPEG or null>",
    "outcome": "order",                          // order | payment | no_order | closed
    "reason": null,                              // e.g. "Owner not there" when there was no order
    "note": null,
    "order_uuids": ["…"], "payment_uuids": []    // orders / payments made during this visit
  }],
  "orders": [{
    "uuid": "…", "number": "BOO11-0005", "seq": 5,
    "contact_id": 57,               // OR "customer_uuid": "…" for a customer created on this phone
    "location_id": 1,
    "order_date": "2026-10-07 13:20:00",
    "note": "Deliver Monday",
    "lines": [{"variation_id": 1, "sub_unit_id": 26, "quantity": 5, "unit_price": 2520},
              {"variation_id": 4, "sub_unit_id": 1,  "quantity": 10, "unit_price": 130}]
  }],
  "payments": [{
    "uuid": "…", "number": "BOO11-R-0002", "seq": 2,
    "contact_id": 57,               // OR "customer_uuid"
    "amount": 5000, "method": "cash",           // cash | cheque | bank_transfer
    "cheque_number": null, "bank_ref": null, "note": "Cash at shop",
    "allocations": [{"invoice_id": 15763, "amount": 5000}],   // optional; empty = oldest dues first
    "paid_on": "2026-10-07 13:25:00"
  }]
}
```
- Line `quantity` and `unit_price` are **in the chosen unit** (`sub_unit_id`), e.g. 5 × CTN 24 at 2,520 per carton.
- Photos: base64 JPEG (a `data:image/jpeg;base64,` prefix is fine), at most 4 MB — resize first (max 1280 px).
- `customer_updates` / `visits` results come back in the same shape (`customer_updates: [...]`, `visits: [...]`).
- Always send customers before orders/payments that reference them (same request is fine).
- Response 200, one result per item:
```json
{
  "customers": [{"uuid": "…", "result": "saved"}],
  "orders": [{"uuid": "…", "result": "saved", "status": "pending", "short_stock": true, "whatsapp": true}],
  "payments": [{"uuid": "…", "result": "error", "message": "Invoice does not belong to this customer"}],
  "server_time": "…"
}
```
- `saved` or `duplicate` → remove from outbox, store in history (resending is always safe: the uuid makes the
  server ignore duplicates). `error` → keep in outbox, mark "needs attention" with `message`; the booker can
  **Try again** or **Delete**.
- `whatsapp: true` = the server already sent the order slip to the customer's WhatsApp.

### 3.4 POST `/whatsapp` — send the slip/receipt again to the customer
Request `{"uuid": "<order or payment uuid>"}` (item must already be uploaded; sync first).
200 `{"success": true, "message": "Sent to the customer on WhatsApp"}`; 422 `{"message": "The customer has no
mobile number" | "WhatsApp is not connected in the POS (Settings > WhatsApp)" | …}`.
This uses the business's own WhatsApp number on the server — **do not open WhatsApp/wa.me on the phone** for this.

### 3.5 POST `/logout` — revokes the token. Keep the outbox on the phone.

## 4. Local SQLite schema (suggested)

- `kv(key, value)` — token, user json, business, locations json, current_location_id, since, last_sync,
  stock_updated_at, seq_order, seq_receipt, draft_order json.
- `products(variation_id PK, product_id, name, sku, unit, units_json, category, brand, price, loc_price_json,
  enable_stock)` + index on `name`, `sku`.
- `stock(location_id, variation_id, qty NULL, PK(location_id, variation_id))`.
- `customers(key PK, local_id, uuid, name, business_name, mobile, address, city, credit_limit, balance_due, status)`.
- `invoices(id PK, contact_id, invoice_no, transaction_date, final_total, paid, due)`.
- `outbox(uuid PK, type, payload_json, created_at, state NULL|'error', error)` — type `customer | order | payment |
  customer_update | visit`; photos are stored as files on the phone and base64-encoded only when uploading.
- `routes(id PK, name, location_id, days_json, booker_id)`; customers also get `route_id, position, photo_url,
  outlet_type, outlet_class, visit_sequence`.
- `visits(uuid PK, customer_key, customer_name, route_id, started_at, ended_at, lat, lng, accuracy_m, distance_m,
  photo_path, outcome, reason, note, order_uuids_json, payment_uuids_json)` — the open visit has `ended_at` NULL
  (at most one open visit); keep finished ones 3 days for the ✓ marks on Today's route.
- `history(uuid PK, type, number, payload_json, status, local_so_no, invoice_no, local_ref, reject_reason,
  short_stock, wa_sent, created_at)`.

## 4.1 No internet: everything runs from SQLite (mandatory)

The booker must be able to work a **whole day with no internet**. The phone's SQLite database is the app's only
data source; the server is only used to sync. Never block a screen waiting for the network.

**Works offline (from SQLite):**
- Log in again on the same phone if the token is still stored (no network needed to open the app). First-ever login
  on a phone needs internet once.
- Dashboard, all totals, highest dues, recent work.
- Search products and customers, see prices (per location), units, free stock, customer dues, unpaid invoices.
- **Book orders**, **collect payments**, **add new customers** — saved instantly to SQLite (`outbox`) with a uuid and
  a slip/receipt number; the slip can be **printed (Bluetooth)** and **shared** at once.
- My work: all orders/payments with their last known status; unsent items marked "not sent".
- Today's route, check in / leave shop (GPS works without internet), shop edits and photos — all saved to the
  outbox and uploaded later.
- The draft order being typed survives the app being closed or the phone restarting.

**Needs internet (show a clear message, never crash):**
- First login on a new phone, and Log out (log out offline = keep the session; offer it again when online).
- "Send on WhatsApp" (`/whatsapp`): offline → "No internet. The customer gets the slip on WhatsApp automatically when
  this order is sent."
- Fresh stock/prices/statuses: show the age ("Stock from 3 hours ago") so the booker knows the data is old.

**How data is kept safe offline:**
1. Every save is one SQLite transaction (outbox row + next number + clearing the draft). Write to SQLite **before**
   any network call.
2. Each outbox item has a **uuid**; uploading the same item again is harmless (server answers `duplicate`), so the
   app can retry as often as needed — after a crash, a timeout, or half-sent uploads.
3. Free stock on the phone = last downloaded free stock **minus this phone's unsent orders**, so two orders made
   offline do not both use the same stock.
4. Slip numbers come from the local counter (`seq_order` / `seq_receipt`) — no server needed; never reused.
5. Logging out, a 401, or an app update must **never delete the outbox**. Only an explicit "Delete" on an item the
   server rejected removes it.
6. When the internet returns (connectivity change, app start, Sync button, every 2 min while open, workmanager every
   15 min) the app uploads the outbox, then downloads changes — automatically, without the booker doing anything.

**What can differ after a long offline period (handled by the office, just show it):**
- Price or stock changed meanwhile → the order keeps the booker's price/quantity; the office sees the difference and
  may edit or reject; the phone then shows the new status and reason.
- A product or customer was deleted on the POS meanwhile → upload returns `error` with a message; show it under the
  item with **Try again** / **Delete**.

Acceptance: with airplane mode ON for the whole test, a booker can open the app, book 10 orders (one for a new
customer), collect 3 payments, print and share every slip, close/reopen the app and restart the phone — nothing is
lost; turning airplane mode OFF uploads all 14 items once and the statuses update.

## 5. Business rules (must match exactly)

1. **Units:** a product sells in `units`; `multiplier` = how many base units (Pc) in that unit. Price of a unit =
   base price × multiplier. Default unit when adding = the first in `units`. If `allow_decimal = 0`, quantity must be
   a whole number. Line total = quantity × unit price.
2. **Location:** a booker may book for `locations`. One location → use it silently. Several → a picker
   "📍 Booking for …" on the New order screen (remember the choice). Base price at a location =
   `loc_price[location]` if present, else `price`. A product is **sold at a location only if `loc_price` has that
   key** (if `loc_price` is null, treat as sold everywhere). Hide products not sold at the chosen location.
3. **Free stock** shown = `stock_by_location[location][variation_id]` (fallback `stock[variation_id]`) **minus this
   phone's unsent orders for that location**, in base units. Display in the chosen unit, e.g. `55 CTN 12 + 9 Pc(s)`;
   ≤ 0 → "out of stock". Ordering more than free stock is **allowed** but marked **short stock** with a warning; ask
   to confirm on save.
4. **Price is editable** per line (per chosen unit). If changed, show the list price small underneath. Changing the
   unit resets the line to that unit's list price.
5. **Customer due** shown = `balance_due` minus this booker's payments not yet approved (outbox + history with status
   pending/received). Show "−X collected" under it.
6. **Numbers:** order slip `CODE-0001` (`user.code` + 4-digit `seq_order`), receipt `CODE-R-0001`. Start from
   `next_order_seq` / `next_receipt_seq` given at login and **never go backwards** (take the max of local and server).
   Increment when saving, even offline.
7. **Payment allocations:** optional list of the customer's unpaid invoices with an amount each (default = invoice
   due); their sum must not exceed the payment amount. New (uuid) customers have no invoices.
8. **New customer:** name required; mobile, business name, address, city optional. Save to outbox with a uuid and make
   it immediately selectable (badge "new · not sent").
9. Nothing in the outbox can be edited after saving except: error items → Try again / Delete.
10. **Today's route:** weekday = 1 Monday … 7 Sunday. Routes for today = routes whose `days` contain today **and**
    `booker_id` = this booker; if there are none, the routes for today with `booker_id` null. Shops = customers with
    `route_id` in those routes, ordered by route, then `visit_sequence`, then name. A shop counts as visited when this
    phone has a visit for it started today. Show "N of M visited · K with orders" and the %.
11. **Check in:** only one open visit at a time (to check in elsewhere, leave the current shop first). Get GPS (high
    accuracy, 20 s). No GPS → ask "Check in without location? The office will see 'no GPS'" (lat/lng null).
    Distance = haversine metres from the shop's `position` (null if the shop has none). Inside `visit_radius_m` →
    green "✓ At the shop (7 m)"; outside → red "⚠ 640 m from the shop's saved location — the office will see this"
    (still allowed). The office recomputes the distance itself.
12. **During a visit:** a banner on every screen "🏪 In SHOP since 10:32 — tap to leave". Orders and payments saved for
    that shop while checked in are added to the visit (`order_uuids` / `payment_uuids`). Optional visit photo.
    Shop with no `position` and GPS accuracy ≤ 50 m → button "📍 Save this as the shop location" (sends a
    `customer_update` with that position).
13. **Leave shop:** outcome = `order` if the visit has orders, else `payment` if it has payments, else the booker must
    choose a reason: Shop closed (outcome `closed`), Owner not there, Has enough stock, No money / credit problem,
    Price problem, Other (outcome `no_order`); optional note. Then the visit goes to the outbox.
14. **Edit shop** (existing customers only): send only fields that differ from the phone's copy (blank = unchanged).
    "Set location here" needs GPS accuracy ≤ 50 m (otherwise "GPS is weak (±80 m). Step outside and tap again").
    After upload show "Your changes went to the office"; the new values arrive through the normal sync once the
    office has them (empty fields at once, changes to existing values after approval).

## 6. Screens

Bottom navigation on phones (top tabs on tablets ≥ 900 px): **Home · New order · Customers · My work · Account**.
A slim status bar on every screen: ● Online/Offline · "Synced 2 min ago" · "Stock from 3 min ago" ·
"N not sent" · "N need attention" · syncing spinner.

1. **Login** — username, password, Log in. Shows "N items saved on this phone will be sent after you log in" if the
   outbox is not empty.
2. **Home (dashboard)** — greeting + date + big **New order** button; **Today's route** card (route names, "3 of 12
   visited · 1 with orders", %, numbered shop list with ✓ time · result, "in shop now", or **Go ➜** directions);
   tiles: Booked today (amount, count), Collected
   today (cash in hand), Waiting at office (+ approved/invoiced counts), Not sent (+ rejected); Total customers (+ with
   dues), Total dues to collect (+ unpaid invoices), Products (in stock / out at current location), This month
   (orders amount/count, collected); quick buttons New order / Collect payment / New customer; lists: Highest dues
   (tap → customer), Recent work (tap → slip).
3. **New order** — layout like the POS: customer search box with **＋** (new customer) and a dropdown of matches
   (name, mobile, city, due); under it mobile · city · **Due** in red; product search "Enter product name / SKU" with
   dropdown (name, sku · brand · free stock, price / unit); Enter or tap adds (adding the same product+unit again
   increases its quantity); lines table/cards: product (sku, stock, short stock badge) · quantity −/＋ with unit
   selector · price (editable) · subtotal · ✕; items count and total; note; bottom bar **✖ Cancel**,
   **✔ Save order** (disabled until a customer and a line exist; label "Choose customer" if none), **Total
   Payable**. Keep the draft order in SQLite so it survives closing the app. On save → slip screen.
4. **Customers** — search (name, mobile, city), "＋ New"; list with 📍 (has location) 📷 (has photo), mobile · city ·
   route, due and "−X collected", and an **✎ Edit** button on each shop; customer screen: **visit block** on top
   (**📍 Check in — I am at this shop**, or the open visit: since, distance status, photo, orders/payments in this
   visit, **🚪 Leave shop**), shop photo, contact, route · type · class, **Navigate to shop**, balance due, unpaid
   invoices, **New order**, **Collect payment**, **✎ Edit shop**, **Call**.
   New customer form also has route, shop type, class, **Set location here** and **Take photo**.
5. **Collect payment** — amount, method (Cash/Cheque/Bank transfer; cheque no. / bank ref fields), optional invoice
   checkboxes with amounts, note, Save → receipt screen.
6. **My work** — orders and payments, newest first: number, customer, date, amount, status chip
   (not sent / sent / at office / approved / invoiced / rejected), short-stock chip, rejection reason, SO/invoice/ref
   numbers; error items with message + Try again / Delete; tap → slip.
7. **Slip / receipt** — monospace text (business name header, number, date, customer, location if several, lines
   "qty unit x price = total", TOTAL, note, booker, status), "✓ Sent to the customer on WhatsApp" badge when known;
   buttons **Send on WhatsApp** (calls `/whatsapp`; label "Send again" if already sent), **Print** (Bluetooth),
   **Share**.
8. **Account** — name, username, slip code, booked/collected today, last sync, stock time, counts, **Sync now**,
   printer setup (pair, paper width 58/80 mm, test print), server URL, **Log out** (warn if outbox not empty).

## 7. Sync algorithm

```
sync():
  if already syncing or offline: return
  1. upload(): send all outbox items not in error state (oldest first) in one POST /upload;
     apply results (§3.3).
  2. download(): GET /sync?since=…; apply (§3.2) in one SQLite transaction; save server_time, last_sync.
  On 401: clear token, show login, keep outbox. On network error: keep everything, show "Offline", retry later.
Triggers: app start, login, reconnect, after each save, every 2 min while the app is open, workmanager every 15 min,
pull-to-refresh, Sync button.
```

## 8. Non-functional

- Works with ~600 products and ~1,200 customers instantly (indexed search, no lag while typing); must stay smooth at
  20,000 products.
- Never lose data: write to SQLite before any network call; outbox survives app kill, reboot, logout.
- Show amounts with thousands separators; quantities up to 3 decimals; no float rounding errors in totals.
- English UI; keep strings in one file (Urdu can be added later).
- No analytics, no third-party backends.

## 9. Acceptance tests (all must pass)

1. Fresh install → login booker1 → first sync downloads products, customers, invoices; dashboard totals show.
2. Airplane mode: create a customer, an order for it (2 × CTN 12 + 10 Pc of another product, one price edited) and a
   payment; slips have numbers; close and reopen the app → everything still there, "3 not sent".
3. Back online → items upload once; status "sent"; resending (simulate by re-posting) gives "duplicate", no doubles.
4. Office approves/invoices/rejects on the POS → after sync the statuses, invoice numbers and rejection reason show.
5. Ordering more than free stock shows "short stock" and asks to confirm; free stock drops by this phone's unsent
   orders.
6. Booker with two locations: switching location changes prices, stock and visible products; order is uploaded with
   that `location_id`.
7. Send on WhatsApp → success message; customer without mobile → server's message is shown.
8. Bluetooth print of a slip on a 58 mm printer is readable and fits the width.
9. Token revoked on the server → next sync shows login; after login the outbox uploads.
10. Reinstall → login → slip numbers continue after the last number sent (no reuse).
11. Route on today's weekday assigned to booker1 → Home shows its shops in order; check in at a shop's GPS → "✓ At
    the shop", 2 km away → red warning; leave with "Owner not there"; the shop gets ✓ with the time and reason; the
    visit reaches the office (Sell → Booker visits) with distance, photo and result.
12. Check in at a shop with no location → "Save this as the shop location" → after sync the shop has that location.
13. Edit shop: mobile empty on the POS → filled at once; city already set → waits in Mobile orders → Shop edits until
    approved; the photo shows on the shop after sync.
14. Airplane mode: a full visit with photo, an order inside it and a shop edit → all upload once when back online.

## 10. Deliverables

- Flutter project source with README (build, configure base URL, signing).
- Release APK (and AAB) signed with a keystore handed over to the owner.
- Short user guide (one page with screenshots) for bookers.
