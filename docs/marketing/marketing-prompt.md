# Fatoora Now — marketing prompt

Paste everything below the line into your AI tool (website builder, design tool, ChatGPT/Claude, video tool).
Attach the images from `docs/marketing/Desktopscreenshots/` (full desktop screens, 1440×900) and
`docs/marketing/Mobilescreenshots/` (phone screens, 390 px wide). Both folders use the same file names, listed next
to each feature below. `02-menu.png` is only in the phone folder.

---

You are a marketing designer and copywriter. Create **mobile-first** marketing material for **Fatoora Now**, a
point-of-sale, inventory, accounting and field-sales system for **wholesalers, distributors and shops in Pakistan**.

## What to make
1. **Landing page** (mobile first, 390 px wide; then tablet and desktop): hero, problem → solution, feature sections,
   "How it works" in 3 steps, the order-booker app section, pricing call-to-action, FAQ, WhatsApp contact button,
   footer. Show desktop screenshots inside a **laptop frame** and phone screenshots inside a **phone frame**; the hero
   shows both together (laptop with the dashboard, phone with the booker app in front of it).
2. **10 social media posts** (square 1080×1080 and story 1080×1920): one feature per post, a screenshot inside a
   laptop or phone frame, a short headline, 1–2 lines of text, call-to-action "Free demo on WhatsApp".
3. **A 60-second video script** (vertical, for Reels/TikTok/Shorts): scene by scene, which screenshot to show,
   on-screen text, voice-over in simple English **and** Urdu.
4. **A one-page brochure** (A4, printable) with the top 12 features.

## Audience and tone
- Owners of distribution businesses, wholesalers, sub-dealers, marts and general stores; they use phones more
  than computers and are busy, practical people.
- Tone: simple, confident, no jargon. Short sentences. Talk about money saved, dues recovered, time saved and
  stock under control. Prices in **Rs (PKR)**.
- Offer both English and Urdu (Roman Urdu for social posts is fine).

## Brand
- Name: **Fatoora Now**. Always write it exactly like this: two words, capital F and N. Do not write "EXPLAINER
  POS" anywhere, even though it appears on some screenshots.
- Colours (use only these; light design by default, dark version for social posts and the video):

| Token | Light | Dark | Use |
|---|---|---|---|
| surface | `#F4F8F5` | `#0F1A14` | page and card backgrounds |
| ink | `#12261C` | `#EAF3EE` | headings and body text |
| muted | `#4A5E53` | `#A3B8AC` | secondary text, captions |
| green-500 | `#2E9E6A` | `#2E9E6A` | brand colour: buttons, highlights, icons |
| green-700 | `#1F7A50` | `#1F7A50` | button hover, dark accents, links |
| on-brand | `#FFFFFF` | `#FFFFFF` | text on green buttons and banners |

- Style: clean and modern, rounded cards, plenty of space, large numbers for money figures. Green buttons with white
  text. No other accent colours (no blue or purple gradients).

## Key messages (use as headlines)
- "Your whole business in your pocket": sales, stock, dues and accounts on your phone.
- "Order bookers book in the market, even without internet. The office approves and invoices with one click."
- "Recover your dues: see who owes you, and send a WhatsApp reminder in one tap."
- "Know which customers stopped buying before you lose them."
- "Works offline on your shop PC, synced to the cloud automatically."

## Full feature list (with screenshots)

### Dashboard and mobile app
- Mobile-friendly dashboard: total receivable, payable, recovered amount, builty amount, sales, net, invoice due,
  returns, purchases, expenses, charts for the last 30 days and the financial year — `01-dashboard.png`
- Easy menu on the phone — `02-menu.png` (phone folder only)
- Alerts on the dashboard: low stock, expiring stock, **customers not buying** (30+ days)
- Secure login, roles and permissions per user — `00-login.png`, `38-users.png`, `39-roles.png`

### Selling
- Fast **POS billing** screen: scan barcode or search product, customer, commission agent, discount, tax,
  shipping — `03-pos-billing.png`
- **Add Sale** (full invoice) with pay terms, invoice schemes and attachments — `04-add-sale.png`
- **All sales** list with filters and export to CSV / Excel / PDF / print; WhatsApp the invoice from the list —
  `05-all-sales.png`
- **Delivery challan**: tick invoices, print one challan grouped by customer with customer totals, remark column
  and signatures (prepared by / driver / received by) — `06-delivery-challan.png`
- Sale returns (with or without invoice) — `07-sell-returns.png`
- Quotations and drafts, convert to invoice — `08-quotations.png`
- Sub units (carton / pieces), selling price groups, discounts, sales orders, shipments

### Order-booker field sales (mobile app)
- **Booker app** on the phone (installable, works **fully offline**): customers, products with stock and price,
  book orders, collect payments, print/WhatsApp order slips — `12-booker-app.png`
- **Mobile orders** inbox in the office: approve, one-click invoice, load sheet by brand for the warehouse, totals
  per booker, print — `09-mobile-orders.png`
- Office settings: allow or block booking more than stock; one phone per booker, log out a phone or all phones
  from the office — `09-mobile-orders.png`
- **Booker routes**: which shops each booker visits on which day; route sheet download/upload in Excel —
  `10-booker-routes.png`
- **Booker visits**: check-in/check-out with GPS distance from the shop, visit result, photos —
  `11-booker-visits.png`
- Order slip sent to the customer on WhatsApp automatically

### Customers and dues
- Customers and suppliers with balances, ledgers, groups, import from Excel — `13-customers.png`,
  `16-suppliers.png`
- **Top defaulters**: who owes the most, total outstanding, last purchase, WhatsApp reminders one by one or in bulk
  with progress bar — `14-top-defaulters.png`
- **Customers not buying**: customers who bought before but not in the last 7–365 days, biggest past buyers first,
  their past purchases and dues, one-tap WhatsApp — `15-customers-not-buying.png`

### Products and stock
- Products with images, SKU, barcode, units and sub units, brands, categories, variations, alert quantity, Excel
  import, download stock in Excel — `17-products.png`, `18-add-product.png`
- **Barcode labels** printing — `19-print-labels.png`
- Purchases and purchase returns, import products into a purchase — `20-purchases.png`, `21-add-purchase.png`
- Stock transfers between locations — `22-stock-transfers.png`
- Stock adjustments (damage, loss) — `23-stock-adjustments.png`
- Stock report with closing stock value (by purchase and sale price) — `30-stock-report.png`
- Multi-location / multi-warehouse

### Transport
- **Builty** (goods transport receipts) and transport companies — `24-builty.png`

### Money and accounts
- Expenses with categories — `25-expenses.png`
- Payment accounts (cash, bank, wallets) — `26-payment-accounts.png`
- Balance sheet, trial balance, cash flow, payment account report — `27-balance-sheet.png`, `28-cash-flow.png`
- **Profit / loss** by product, category, brand, location, invoice, date, customer and day — `29-profit-loss.png`
- **Zakat** calculator (Hanafi, lunar year 2.5%, nisab), zakat records and slips — `36-zakat.png`

### Reports and team
- Trending products — `31-trending-products.png`
- Commission agent report: net sale, commission, invoices, quantity by agent, brand, category —
  `32-commission-agents.png`
- Sales representative, purchase & sale, tax, items, product sell/purchase, payment, expense and register reports
- Activity log: who did what and when — `43-activity-log.png`

### WhatsApp, settings and safety
- **WhatsApp connection** with your own number: send invoices and ledgers as image or PDF, several devices rotate
  the sending — `37-whatsapp.png`
- Business settings: currency PKR, time zone, logo, financial year, tax, POS, prefixes — `40-business-settings.png`
- Invoice schemes and invoice layouts — `41-invoice-settings.png`
- **Backup** with one click and send to Google Drive — `42-backup.png`
- Works on the shop PC **without internet**; syncs with the cloud every 2 minutes for bookers and remote viewing

## Rules
- Only claim features in this list. No made-up numbers, customers or reviews. Leave testimonial and price spots as
  clearly marked placeholders.
- The screenshots contain real business data (customer names, phone numbers, amounts). **Blur customer names,
  phone numbers and amounts** before publishing, or replace them with sample data.
- The login screenshot (`00-login.png`) and the booker app still show the old name and blue colours: crop the name
  out or recreate those screens in the brand colours.
- Every section must look good on a phone first.
