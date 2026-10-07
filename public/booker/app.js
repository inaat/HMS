/*
 * Order Booker app (served by the cloud copy at /booker/, API at /api/mobile, see config/mobile_sync.php).
 * Works offline: products, customers, dues and stock are kept in IndexedDB; new orders, payments and customers
 * go to an outbox with a UUID each and are uploaded when there is internet (a resend is never saved twice).
 */
'use strict';

const API = new URL('../api/mobile', location.href).pathname;
const SYNC_EVERY_MS = 2 * 60 * 1000;

// ---------- storage ----------
const db = {
  handle: null,
  open() {
    return new Promise((resolve, reject) => {
      const req = indexedDB.open('booker', 1);
      req.onupgradeneeded = () => {
        const d = req.result;
        d.createObjectStore('kv');
        d.createObjectStore('products', { keyPath: 'variation_id' });
        d.createObjectStore('customers', { keyPath: 'key' });
        d.createObjectStore('invoices', { keyPath: 'id' });
        d.createObjectStore('outbox', { keyPath: 'uuid' });
        d.createObjectStore('history', { keyPath: 'uuid' });
      };
      req.onsuccess = () => { this.handle = req.result; resolve(); };
      req.onerror = () => reject(req.error);
    });
  },
  tx(store, mode, fn) {
    return new Promise((resolve, reject) => {
      const t = this.handle.transaction(store, mode);
      const s = t.objectStore(store);
      const out = fn(s);
      t.oncomplete = () => resolve(out instanceof IDBRequest ? out.result : out);
      t.onerror = () => reject(t.error);
    });
  },
  all(store) { return this.tx(store, 'readonly', (s) => s.getAll()); },
  get(key) { return this.tx('kv', 'readonly', (s) => s.get(key)); },
  set(key, value) { return this.tx('kv', 'readwrite', (s) => { s.put(value, key); }); },
  put(store, rows) { return this.tx(store, 'readwrite', (s) => { (Array.isArray(rows) ? rows : [rows]).forEach((r) => s.put(r)); }); },
  del(store, keys) { return this.tx(store, 'readwrite', (s) => { (Array.isArray(keys) ? keys : [keys]).forEach((k) => s.delete(k)); }); },
  clear(stores) { return Promise.all(stores.map((st) => this.tx(st, 'readwrite', (s) => { s.clear(); }))); },
};

// ---------- state ----------
const S = {
  token: null, user: null, since: null, stock: {}, stockAt: null, lastSync: null, seq: { order: 1, receipt: 1 },
  products: new Map(), customers: new Map(), invoices: new Map(), outbox: new Map(), history: new Map(),
  draft: { customer: null, lines: [], note: '' }, routes: [], outletTypes: [], editsSent: {}, visit: null, visits: [], radius: 100, tab: 'home', cartOpen: false, syncing: false, online: navigator.onLine,
};

async function loadState() {
  for (const k of ['locations', 'location', 'stockByLoc', 'business', 'token', 'user', 'since', 'stock', 'stockAt', 'lastSync', 'seq', 'draft', 'routes', 'outletTypes', 'editsSent', 'visit', 'visits', 'radius']) {
    const v = await db.get(k);
    if (v !== undefined && v !== null) S[k] = v;
  }
  if (!Array.isArray(S.draft.lines)) S.draft = { customer: null, lines: [], note: '' };
  (await db.all('products')).forEach((p) => S.products.set(p.variation_id, p));
  (await db.all('customers')).forEach((c) => S.customers.set(c.key, cleanCust(c)));
  (await db.all('invoices')).forEach((i) => S.invoices.set(i.id, i));
  (await db.all('outbox')).forEach((o) => S.outbox.set(o.uuid, o));
  (await db.all('history')).forEach((o) => S.history.set(o.uuid, o));
}

const saveDraft = () => db.set('draft', S.draft);

// ---------- helpers ----------
const $ = (sel) => document.querySelector(sel);
const h = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const num = (n) => Number(n || 0);
const money = (n) => num(n).toLocaleString(undefined, { maximumFractionDigits: 2 });
const qtyFmt = (n) => num(n).toLocaleString(undefined, { maximumFractionDigits: 3 });
const pad = (n, w = 2) => String(n).padStart(w, '0');
const nowStr = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`; };
const ago = (t) => {
  if (!t) return 'never';
  const s = Math.max(0, (Date.now() - new Date(String(t).replace(' ', 'T')).getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return Math.round(s / 60) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago';
  return Math.round(s / 86400) + ' days ago';
};
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
  const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
}));
/** The POS stores "0" or "-" when a customer has no mobile: show nothing instead. */
const cleanCust = (c) => ({ ...c, mobile: ['0', '-'].includes(String(c.mobile ?? '').trim()) ? '' : c.mobile });
const OUTLET_TYPES = ['Kiryana', 'General store', 'Wholesale', 'Medical store', 'Bakery', 'Super store', 'Hotel / Restaurant', 'Other'];
const DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun' };
const routeName = (id) => ((S.routes || []).find((r) => r.id === num(id)) || {}).name || '';
/** Booker photos live on the cloud copy next to the app (public/uploads/booker/...). */
const photoUrl = (path) => new URL('../' + path, location.href).href;
const mapLink = (pos) => 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(pos);
const match = (text, q) => q.toLowerCase().split(/\s+/).filter(Boolean).every((w) => text.toLowerCase().includes(w));

function toast(msg, ms = 2600) {
  const el = document.createElement('div');
  el.className = 'toast';
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), ms);
}

function sheet(title, html) {
  closeSheet();
  const m = document.createElement('div');
  m.className = 'modal';
  m.id = 'sheet';
  m.innerHTML = `<div class="sheet"><h2><span>${h(title)}</span><button class="x noprint" data-act="close">&times;</button></h2>${html}</div>`;
  m.addEventListener('click', (e) => { if (e.target === m) { S.cartOpen = false; closeSheet(); } });
  document.body.appendChild(m);
  return m;
}
function closeSheet() { const m = $('#sheet'); if (m) m.remove(); }

// ---------- api ----------
async function api(method, path, body) {
  const res = await fetch(API + path, {
    method,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(S.token ? { Authorization: 'Bearer ' + S.token } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  let data = null;
  try { data = await res.json(); } catch (e) { /* not json */ }
  if (!res.ok) {
    const err = new Error((data && (data.message || Object.values(data.errors || {})[0])) || 'Server error ' + res.status);
    err.status = res.status;
    throw err;
  }
  return data;
}

// ---------- customers / stock ----------
/** Location the booker is booking for (several locations: their choice; one: that one). */
function curLocation() {
  const ids = (S.locations || []).map((l) => l.id);
  return ids.includes(S.location) ? S.location : (ids[0] || null);
}
const locName = (id) => ((S.locations || []).find((l) => l.id === id) || {}).name || '';
/** Price of a product at the current location (base unit). */
function priceOf(p) {
  const loc = curLocation();
  return p.loc_price && loc && p.loc_price[loc] !== undefined ? num(p.loc_price[loc]) : num(p.price);
}
/** Sold at the current location? (Older data without per-location prices: yes.) */
const soldHere = (p) => !p.loc_price || !curLocation() || p.loc_price[curLocation()] !== undefined;

const custKey = (row) => (row.local_id ? 'c' + row.local_id : 'u' + row.uuid);
const custName = (c) => (c ? c.name + (c.business_name ? ' (' + c.business_name + ')' : '') : '');

/** Free stock in base units for a variation: what the server says, less this phone's unsent orders. */
function freeStock(variation_id) {
  const map = (S.stockByLoc && S.stockByLoc[curLocation()]) || S.stock || {};
  const s = map[variation_id];
  if (s === null || s === undefined) return null;
  let held = 0;
  S.outbox.forEach((o) => {
    if (o.type === 'order' && (o.location_id || curLocation()) === curLocation()) o.lines.forEach((l) => { if (l.variation_id === variation_id) held += num(l.quantity) * num(l.multiplier || 1); });
  });
  return num(s) - held;
}

function stockText(product, unit) {
  const free = freeStock(product.variation_id);
  if (free === null || !product.enable_stock) return '';
  if (free <= 0) return 'out of stock';
  const m = num(unit && unit.multiplier) || 1;
  if (m === 1) return qtyFmt(free) + ' ' + h(product.unit || '');
  const whole = Math.floor(free / m);
  const rest = free - whole * m;
  if (!whole) return qtyFmt(rest) + ' ' + h(product.unit || '');
  return qtyFmt(whole) + ' ' + h(unit.name) + (rest ? ' + ' + qtyFmt(rest) + ' ' + h(product.unit || '') : '');
}

/** What the customer still owes on the phone: server due less this booker's collections not yet posted. */
function customerDue(c) {
  let pending = 0;
  const pend = (p) => { if (p.type === 'payment' && (p.contact_id ? 'c' + p.contact_id : 'u' + p.customer_uuid) === c.key) pending += num(p.amount); };
  S.outbox.forEach(pend);
  S.history.forEach((p) => { if (['pending', 'received'].includes(p.status)) pend(p); });
  return { due: num(c.balance_due), pending };
}

// ---------- sync ----------
async function sync(manual) {
  if (!S.token || S.syncing) return;
  if (!navigator.onLine) { if (manual) toast('No internet. Your work is saved on the phone.'); return; }
  S.syncing = true;
  renderBar();
  try {
    await upload();
    await download();
    S.lastSync = nowStr();
    await db.set('lastSync', S.lastSync);
    S.syncError = null;
    if (manual) toast('Synced');
  } catch (e) {
    if (e.status === 401) {
      S.token = null;
      await db.set('token', null);
      toast(e.message, 4000);
    } else {
      S.syncError = e.message;
      if (manual) toast('Sync failed: ' + e.message, 4000);
    }
  } finally {
    S.syncing = false;
    render();
  }
}

async function upload() {
  const items = [...S.outbox.values()].filter((o) => o.state !== 'error').sort((a, b) => a.created.localeCompare(b.created));
  if (!items.length) return;
  const sets = { customer: 'customers', order: 'orders', payment: 'payments', customer_update: 'customer_updates', visit: 'visits' };
  const body = { customers: [], orders: [], payments: [], customer_updates: [], visits: [] };
  items.forEach((o) => body[sets[o.type]].push(o));
  const res = await api('POST', '/upload', body);

  const done = [];
  Object.values(sets).forEach((k) => (res[k] || []).forEach((r) => {
    const item = S.outbox.get(r.uuid);
    if (!item) return;
    if (r.result === 'saved' || r.result === 'duplicate') {
      done.push(item.uuid);
      if (item.type === 'customer_update') S.editsSent[item.contact_id] = nowStr();
      if (item.type === 'order' || item.type === 'payment') {
        const hist = { ...item, status: r.status || 'pending', short_stock: !!r.short_stock, wa_sent: !!r.whatsapp, sent: nowStr() };
        delete hist.state; delete hist.error;
        S.history.set(hist.uuid, hist);
      }
    } else {
      item.state = 'error';
      item.error = r.message || 'Not accepted';
    }
  }));
  done.forEach((u) => S.outbox.delete(u));
  await db.del('outbox', done);
  await db.put('outbox', [...S.outbox.values()]);
  await db.put('history', [...S.history.values()]);
  await db.set('editsSent', S.editsSent);
}

async function download() {
  const data = await api('GET', '/sync' + (S.since ? '?since=' + encodeURIComponent(S.since) : ''));

  const putP = [], delP = [];
  data.products.forEach((p) => {
    if (num(p.active)) { S.products.set(p.variation_id, p); putP.push(p); } else { S.products.delete(p.variation_id); delP.push(p.variation_id); }
  });
  await db.put('products', putP); await db.del('products', delP);

  const putC = [], delC = [];
  data.customers.forEach((c) => {
    const key = custKey(c);
    if (c.uuid && c.local_id && S.customers.has('u' + c.uuid)) { S.customers.delete('u' + c.uuid); delC.push('u' + c.uuid); }
    if (c.status === 'deleted') { S.customers.delete(key); delC.push(key); return; }
    const row = cleanCust({ ...c, key });
    S.customers.set(key, row); putC.push(row);
  });
  await db.put('customers', putC); await db.del('customers', delC);

  const putI = [], delI = [];
  data.invoices.forEach((i) => {
    if (num(i.active)) { S.invoices.set(i.id, i); putI.push(i); } else { S.invoices.delete(i.id); delI.push(i.id); }
  });
  await db.put('invoices', putI); await db.del('invoices', delI);

  [...data.orders.map((o) => ({ ...o, type: 'order' })), ...data.payments.map((p) => ({ ...p, type: 'payment' }))].forEach((r) => {
    const old = S.history.get(r.uuid) || { uuid: r.uuid, type: r.type, number: r.number, created: nowStr(), lines: [] };
    S.history.set(r.uuid, { ...old, ...r, total: r.type === 'order' ? r.total : old.total, amount: r.type === 'payment' ? r.amount : old.amount });
  });
  await db.put('history', [...S.history.values()]);

  if (data.business) { S.business = data.business; await db.set('business', data.business); }
  if (data.routes) { S.routes = data.routes; await db.set('routes', S.routes); }
  if (data.visit_radius_m) { S.radius = num(data.visit_radius_m); await db.set('radius', S.radius); }
  if (data.outlet_types) { S.outletTypes = data.outlet_types; await db.set('outletTypes', S.outletTypes); }
  S.stock = data.stock || {};
  if (data.locations) { S.locations = data.locations; await db.set('locations', S.locations); }
  if (data.stock_by_location) { S.stockByLoc = data.stock_by_location; await db.set('stockByLoc', S.stockByLoc); }
  S.stockAt = data.stock_updated_at;
  S.since = data.server_time;
  await Promise.all([db.set('stock', S.stock), db.set('stockAt', S.stockAt), db.set('since', S.since)]);
}

// ---------- outbox writers ----------
async function addToOutbox(item) {
  S.outbox.set(item.uuid, item);
  await db.put('outbox', item);
}

async function nextSeq(kind) {
  const n = S.seq[kind] || 1;
  S.seq[kind] = n + 1;
  await db.set('seq', S.seq);
  return n;
}

// ---------- screens ----------
function render() {
  const app = $('#app');
  if (!S.token) { app.innerHTML = loginView(); return; }
  const views = { home: homeView, order: orderView, customers: customersView, activity: activityView, more: moreView };
  if (!views[S.tab]) S.tab = 'home';
  const title = { home: 'Dashboard', order: 'New order', customers: 'Customers', activity: 'My orders & payments', more: 'Account' }[S.tab];
  const waiting = S.outbox.size;
  app.innerHTML = `
    <header><h1>${h(title)}</h1><span class="hdr-user">${h(S.user.name)}</span><button data-act="sync">${S.syncing ? 'Syncing…' : '⟳ Sync'}</button></header>
    <div class="bar" id="bar"></div>
    ${visitBanner()}
    <main class="${S.tab === 'order' ? 'pos' : ''}">${views[S.tab]()}</main>
    <nav>
      ${[['home', '🏠', 'Home'], ['order', '🛒', 'New order'], ['customers', '👥', 'Customers'], ['activity', '📋', 'My work' + (waiting ? ' (' + waiting + ')' : '')], ['more', '⚙️', 'Account']]
        .map(([t, i, l]) => `<button data-act="tab" data-tab="${t}" class="${S.tab === t ? 'on' : ''}"><b>${i}</b>${h(l)}</button>`).join('')}
    </nav>
`;
  renderBar();
}

/** After an order change: update the lines and the bottom bar in place (the search boxes keep focus). */
function refreshPos() {
  if (S.tab !== 'order' || !$('#pos-lines')) { render(); return; }
  $('#pos-lines').innerHTML = posLines();
  $('#pos-bottom').innerHTML = posBottom();
}

// ---------- dashboard ----------
function homeView() {
  const today = nowStr().slice(0, 10);
  const all = [...S.outbox.values()].filter((o) => o.type === 'order' || o.type === 'payment').map((o) => ({ ...o, status: 'not sent' })).concat([...S.history.values()]);
  const todays = all.filter((o) => String(o.created || '').startsWith(today) && o.status !== 'rejected');
  const booked = todays.filter((o) => o.type === 'order');
  const collected = todays.filter((o) => o.type === 'payment');
  const orders = all.filter((o) => o.type === 'order');
  const count = (s) => orders.filter((o) => s.includes(o.status)).length;
  const dues = [...S.customers.values()].filter((c) => num(c.balance_due) > 0).sort((a, b) => num(b.balance_due) - num(a.balance_due)).slice(0, 6);
  const recent = all.sort((a, b) => String(b.created).localeCompare(String(a.created))).slice(0, 6);
  const pill = { 'not sent': 'warn', pending: '', received: '', approved: 'ok', invoiced: 'ok', rejected: 'bad' };
  const label = { pending: 'sent', received: 'at office' };
  const hour = new Date().getHours();
  const thisMonth = today.slice(0, 7);
  const month = all.filter((o) => String(o.created || '').startsWith(thisMonth) && o.status !== 'rejected');
  const customers = [...S.customers.values()];
  const totalDue = customers.reduce((s, c) => s + Math.max(0, num(c.balance_due)), 0);
  const customersWithDue = customers.filter((c) => num(c.balance_due) > 0).length;
  const inStock = [...S.products.values()].filter(soldHere).filter((p) => !p.enable_stock || (freeStock(p.variation_id) || 0) > 0).length;

  return `
    <div class="card" style="display:flex;align-items:center;gap:12px">
      <div class="grow"><div class="big">${hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'}, ${h(S.user.name.split(' ')[0])}</div>
        <div class="muted">${new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })}</div></div>
      <button class="btn" data-act="tab" data-tab="order">🛒 New order</button>
    </div>
    ${todayCard()}
    <div class="tiles">
      <div class="tile"><div class="l">Booked today</div><div class="v">${money(booked.reduce((s, o) => s + num(o.total), 0))}</div><div class="l">${booked.length} order(s)</div></div>
      <div class="tile"><div class="l">Collected today (cash in hand)</div><div class="v">${money(collected.reduce((s, o) => s + num(o.amount), 0))}</div><div class="l">${collected.length} receipt(s)</div></div>
      <div class="tile tap" data-act="tab" data-tab="activity"><div class="l">Waiting at office</div><div class="v">${count(['pending', 'received'])}</div><div class="l">approved ${count(['approved'])} · invoiced ${count(['invoiced'])}</div></div>
      <div class="tile tap" data-act="tab" data-tab="activity"><div class="l">Not sent yet</div><div class="v" style="color:${S.outbox.size ? 'var(--warn)' : 'inherit'}">${S.outbox.size}</div><div class="l">${count(['rejected'])} rejected</div></div>
    </div>
    <div class="tiles">
      <div class="tile tap" data-act="tab" data-tab="customers"><div class="l">👥 Total customers</div><div class="v">${S.customers.size.toLocaleString()}</div><div class="l">${customersWithDue} with dues</div></div>
      <div class="tile tap" data-act="tab" data-tab="customers"><div class="l">💰 Total dues to collect</div><div class="v" style="color:var(--bad)">${money(totalDue)}</div><div class="l">${S.invoices.size} unpaid invoice(s)</div></div>
      <div class="tile tap" data-act="tab" data-tab="order"><div class="l">📦 Products</div><div class="v">${S.products.size.toLocaleString()}</div><div class="l">${inStock} in stock · ${S.products.size - inStock} out</div></div>
      <div class="tile tap" data-act="tab" data-tab="activity"><div class="l">📅 This month</div><div class="v">${money(month.filter((o) => o.type === 'order').reduce((s, o) => s + num(o.total), 0))}</div><div class="l">${month.filter((o) => o.type === 'order').length} orders · collected ${money(month.filter((o) => o.type === 'payment').reduce((s, o) => s + num(o.amount), 0))}</div></div>
    </div>
    <div class="actions">
      <button class="btn light" data-act="tab" data-tab="order">🛒<br>New order</button>
      <button class="btn light" data-act="tab" data-tab="customers">💵<br>Collect payment</button>
      <button class="btn light" data-act="add-customer">👤<br>New customer</button>
    </div>
    <div class="dash-cols">
      <div class="card list"><b>Highest dues</b>${dues.map((c) => `
        <div class="item tap row" data-act="open-customer" data-key="${h(c.key)}"><div class="grow">${h(custName(c))}<div class="muted">${h(c.mobile || '')} ${h(c.city || '')}</div></div><b>${money(c.balance_due)}</b></div>`).join('') || '<div class="empty">No dues.</div>'}</div>
      <div class="card list"><b>Recent work</b>${recent.map((o) => `
        <div class="item tap row" data-act="open-slip" data-uuid="${o.uuid}"><div class="grow">${o.type === 'order' ? '🛒' : '💵'} ${h(o.number)} <span class="pill ${pill[o.status] ?? ''}">${h(label[o.status] || o.status)}</span>
          <div class="muted">${h(o.customer_name || '')}</div></div><b>${money(o.type === 'order' ? o.total : o.amount)}</b></div>`).join('') || '<div class="empty">Nothing yet. Tap New order to start.</div>'}</div>
    </div>`;
}

function renderBar() {
  const bar = $('#bar');
  if (!bar) return;
  const errors = [...S.outbox.values()].filter((o) => o.state === 'error').length;
  bar.innerHTML = `
    <span><span class="dot" style="background:${navigator.onLine ? 'var(--ok)' : 'var(--bad)'}"></span>${navigator.onLine ? 'Online' : 'Offline'}</span>
    <span>Synced ${h(ago(S.lastSync))}</span>
    <span>Stock from ${h(ago(S.stockAt))}</span>
    ${S.outbox.size ? `<span class="pill warn">${S.outbox.size} not sent</span>` : ''}
    ${errors ? `<span class="pill bad">${errors} need attention</span>` : ''}
    ${S.syncing ? '<span class="pill">syncing…</span>' : ''}
    ${S.syncError && !S.syncing ? `<span class="pill bad" title="${h(S.syncError)}">Sync failed: ${h(String(S.syncError).slice(0, 60))}</span>` : ''}`;
}

function loginView() {
  return `<div class="login card">
    <h2 style="margin-top:0">Order Booker</h2>
    <p class="muted">Log in with your POS username and password.</p>
    <form data-form="login">
      <label>Username</label><input name="username" autocomplete="username" required value="${h(S.user ? S.user.username : '')}">
      <label>Password</label><input name="password" type="password" autocomplete="current-password" required>
      <button class="btn block" style="margin-top:14px">Log in</button>
    </form>
    ${S.outbox.size ? `<p class="muted" style="margin-top:12px">${S.outbox.size} item(s) are saved on this phone and will be sent after you log in.</p>` : ''}
  </div>`;
}

// ---------- New order: same layout as the POS screen (/pos/create) ----------
function orderView() {
  const c = S.draft.customer ? findCustomer(S.draft.customer) : null;
  return `<div class="pos-wrap">
    <div class="pos-box">
      ${(S.locations || []).length > 1 ? `<div class="loc-pick">📍 Booking for <select data-act="pos-location">${S.locations.map((l) => `<option value="${l.id}" ${l.id === curLocation() ? 'selected' : ''}>${h(l.name)}</option>`).join('')}</select></div>` : ''}
      <div class="pos-top">
        <div class="ig-wrap">
          <div class="ig">
            <span class="ig-icon">👤</span>
            <input id="cust-q" data-act="pos-cust" autocomplete="off" placeholder="Search customer name / mobile" value="${h(c ? custName(c) : '')}">
            <button class="ig-btn" data-act="add-customer" title="Add new customer">＋</button>
          </div>
          <div class="dd" id="cust-dd"></div>
          ${c ? `<div class="muted" style="margin-top:6px">📞 ${[c.mobile || '—', c.city].filter(Boolean).map(h).join(' · ')} · <b style="color:${num(customerDue(c).due) > 0 ? 'var(--bad)' : 'inherit'}">Due ${money(customerDue(c).due)}</b></div>` : ''}
        </div>
        <div class="ig-wrap">
          <div class="ig">
            <span class="ig-icon">🔍</span>
            <input id="prod-q" data-act="pos-search" autocomplete="off" placeholder="Enter Product name / SKU" value="${h(S.posQuery || '')}">
          </div>
          <div class="dd" id="prod-dd"></div>
        </div>
      </div>
      <div id="pos-lines">${posLines()}</div>
      <textarea rows="2" data-act="note" placeholder="Note for the shop (optional)" style="margin-top:12px">${h(S.draft.note)}</textarea>
    </div>
  </div>
  <div class="pos-bottom" id="pos-bottom">${posBottom()}</div>`;
}

function lineInfo(l) {
  const p = S.products.get(l.variation_id);
  if (!p) return null;
  const unit = (p.units || []).find((u) => u.id === l.unit_id) || { id: null, name: p.unit, multiplier: 1 };
  // The booker may change the price of a line (l.price, per chosen unit); otherwise the list price.
  const list = priceOf(p) * num(unit.multiplier);
  const price = l.price !== undefined && l.price !== null && l.price !== '' ? num(l.price) : list;
  const free = freeStock(p.variation_id);
  return { p, unit, price, list, total: price * num(l.qty), short: p.enable_stock && free !== null && num(l.qty) * num(unit.multiplier) > free };
}

function posLines() {
  const rows = S.draft.lines.map((l, i) => {
    const x = lineInfo(l);
    if (!x) return `<tr><td colspan="4"><span class="pill bad">Product no longer available</span></td><td class="c"><button class="x" data-act="line-del" data-i="${i}">&times;</button></td></tr>`;
    return `<tr>
      <td data-label="Product"><b>${h(x.p.name)}</b><div class="muted">${h(x.p.sku || '')}${x.p.brand ? ' · ' + h(x.p.brand) : ''}</div>
        <div class="muted">${stockText(x.p, x.unit) || ''} ${x.short ? '<span class="pill warn">short stock</span>' : ''}</div></td>
      <td data-label="Quantity" class="c">
        <div class="qty"><button data-act="qty" data-i="${i}" data-d="-1">−</button><input inputmode="decimal" data-act="qty-in" data-i="${i}" value="${h(l.qty)}"><button data-act="qty" data-i="${i}" data-d="1">+</button></div>
        ${(x.p.units || []).length > 1 ? `<select data-act="line-unit" data-i="${i}" class="unit-sel">${x.p.units.map((u) => `<option value="${u.id}" ${u.id === l.unit_id ? 'selected' : ''}>${h(u.name)}</option>`).join('')}</select>` : `<div class="muted" style="margin-top:4px">${h(x.unit.name)}</div>`}
      </td>
      <td data-label="Price" class="r"><input class="price-in" inputmode="decimal" data-act="price-in" data-i="${i}" value="${h(+x.price.toFixed(2))}">
        ${Math.abs(x.price - x.list) > 0.001 ? `<div class="muted" style="margin-top:3px">list ${money(x.list)}</div>` : ''}</td>
      <td data-label="Subtotal" class="r"><b>${money(x.total)}</b></td>
      <td class="c"><button class="x" data-act="line-del" data-i="${i}" title="Remove">&times;</button></td>
    </tr>`;
  }).join('');
  return `<table class="pos-table">
      <thead><tr><th>Product</th><th class="c" style="width:200px">Quantity</th><th class="r" style="width:130px">Price</th><th class="r" style="width:140px">Subtotal</th><th class="c" style="width:50px">✕</th></tr></thead>
      <tbody>${rows || '<tr class="empty-row"><td colspan="5"><div class="empty">Search a product above to add it.</div></td></tr>'}</tbody>
    </table>
    <div class="pos-sums"><span><b>Items:</b> ${S.draft.lines.length}</span><span><b>Total:</b> ${money(cartTotal())}</span></div>`;
}

function posBottom() {
  const c = S.draft.customer ? findCustomer(S.draft.customer) : null;
  const ready = c && S.draft.lines.length;
  return `<button class="pbtn red" data-act="clear-order" ${S.draft.lines.length || S.draft.customer ? '' : 'disabled'}>✖ Cancel</button>
    <button class="pbtn green" data-act="save-order" ${ready ? '' : 'disabled'}>✔ ${c ? 'Save order' : 'Choose customer'}</button>
    <div class="payable"><span>Total<br>Payable:</span><b>${money(cartTotal())}</b></div>`;
}

function cartTotal() {
  return S.draft.lines.reduce((s, l) => s + ((lineInfo(l) || {}).total || 0), 0);
}

/** Product dropdown under the search box: best matches for what was typed. */
function productDropdown() {
  const q = (S.posQuery || '').trim();
  if (!q) return '';
  const list = [...S.products.values()].filter((p) => soldHere(p) && match([p.name, p.sku, p.brand].join(' '), q))
    .sort((a, b) => a.name.localeCompare(b.name)).slice(0, 15);
  if (!list.length) return '<div class="dd-item muted">No product found</div>';
  return list.map((p, n) => {
    const unit = (p.units || [])[0] || { name: p.unit, multiplier: 1 };
    return `<div class="dd-item ${n === 0 ? 'first' : ''}" data-act="add" data-v="${p.variation_id}">
      <div class="grow"><b>${h(p.name)}</b><div class="muted">${h(p.sku || '')}${p.brand ? ' · ' + h(p.brand) : ''} · ${stockText(p, unit) || ''}</div></div>
      <b>${money(priceOf(p) * num(unit.multiplier))}<span class="muted"> / ${h(unit.name)}</span></b></div>`;
  }).join('');
}

function customerDropdown(q) {
  q = (q || '').trim();
  const list = [...S.customers.values()].filter((c) => !q || match([c.name, c.business_name, c.mobile, c.city].join(' '), q))
    .sort((a, b) => a.name.localeCompare(b.name)).slice(0, 15);
  const fresh = [...S.outbox.values()].filter((o) => o.type === 'customer' && (!q || match([o.name, o.mobile, o.city].join(' '), q)));
  return fresh.map((o) => `<div class="dd-item" data-act="choose-customer" data-key="u${o.uuid}"><div class="grow"><b>${h(o.name)}</b> <span class="pill warn">new</span><div class="muted">${h(o.mobile || '')} ${h(o.city || '')}</div></div></div>`).join('')
    + list.map((c) => `<div class="dd-item" data-act="choose-customer" data-key="${h(c.key)}"><div class="grow"><b>${h(custName(c))}</b><div class="muted">${h(c.mobile || '')} ${h(c.city || '')}</div></div><span class="muted">Due ${money(customerDue(c).due)}</span></div>`).join('')
    + `<div class="dd-item add" data-act="add-customer">＋ Add new customer${q ? ' "' + h(q) + '"' : ''}</div>`;
}

function openCart() {
  render();
}

function customersView() {
  return `
    <div class="row" style="margin-bottom:10px"><input id="cust-q" placeholder="Search name, mobile, city" data-act="cust-search" class="grow" value="${h(S.custQuery || '')}">
      <button class="btn small" data-act="add-customer">+ New</button></div>
    ${(S.routes || []).length ? `<select data-act="cust-route" style="margin-bottom:10px"><option value="">All customers</option>${myRoutes().map((r) => `<option value="${r.id}" ${num(S.custRoute) === r.id ? 'selected' : ''}>Route: ${h(r.name)} · ${h(routeDays(r))}${r.booker_id === S.user.id ? ' (yours)' : ''}</option>`).join('')}</select>` : ''}
    <div class="card list" id="cust-list">${customerRows(S.custQuery || '', 'open-customer')}</div>`;
}

function customerRows(q, act) {
  const route = act === 'open-customer' ? num(S.custRoute) : 0;
  const list = [...S.customers.values()].filter((c) => (!route || num(c.route_id) === route) && (!q || match([c.name, c.business_name, c.mobile, c.city].join(' '), q)))
    .sort((a, b) => (route ? num(a.visit_sequence) - num(b.visit_sequence) : 0) || a.name.localeCompare(b.name)).slice(0, route ? 1000 : 60);
  const newOnes = [...S.outbox.values()].filter((o) => o.type === 'customer' && (!q || match([o.name, o.mobile, o.city].join(' '), q)));
  const rows = [
    ...newOnes.map((o) => `<div class="item tap" data-act="${act}" data-key="u${o.uuid}"><b>${h(o.name)}</b> <span class="pill warn">new · not sent</span><div class="muted">${h(o.mobile || '')} ${h(o.city || '')}</div></div>`),
    ...list.map((c) => {
      const { due, pending } = customerDue(c);
      const editing = [...S.outbox.values()].some((o) => o.type === 'customer_update' && o.contact_id === c.local_id);
      return `<div class="item tap" data-act="${act}" data-key="${h(c.key)}"><div class="row"><div class="grow"><b>${h(custName(c))}</b>
        ${c.status === 'pending' ? '<span class="pill">new</span>' : ''}${editing ? ' <span class="pill warn">edit not sent</span>' : ''}
        <div class="muted">${c.position ? '📍 ' : ''}${c.photo_url ? '📷 ' : ''}${h([c.mobile, c.city, routeName(c.route_id)].filter(Boolean).join(' · '))}</div></div>
        <div class="right"><div class="${due > 0 ? '' : 'muted'}">${money(due)}</div>${pending ? `<div class="muted">−${money(pending)} collected</div>` : ''}</div>
        ${act === 'open-customer' && c.local_id ? `<button class="btn small light" data-act="edit-shop" data-key="${h(c.key)}">✎ Edit</button>` : ''}</div></div>`;
    }),
  ];
  return rows.join('') || '<div class="empty">No customers. Sync to download them.</div>';
}

/** A customer the booker added that is still in the outbox, shaped like a downloaded customer. */
function findCustomer(key) {
  if (S.customers.has(key)) return S.customers.get(key);
  const o = key.startsWith('u') ? S.outbox.get(key.slice(1)) : null;
  return o ? { key, uuid: o.uuid, local_id: null, name: o.name, business_name: o.business_name, mobile: o.mobile, city: o.city, address: o.address, balance_due: 0, status: 'new' } : null;
}

function activityView() {
  const items = [...S.outbox.values()].filter((o) => o.type === 'order' || o.type === 'payment').map((o) => ({ ...o, status: o.state === 'error' ? 'error' : 'not sent' }))
    .concat([...S.history.values()])
    .sort((a, b) => String(b.created).localeCompare(String(a.created))).slice(0, 150);
  const errorsC = [...S.outbox.values()].filter((o) => ['customer', 'customer_update', 'visit'].includes(o.type) && o.state === 'error');
  const pill = (s) => ({ 'not sent': 'warn', error: 'bad', pending: '', received: '', approved: 'ok', invoiced: 'ok', rejected: 'bad' }[s] ?? '');
  const label = (s) => ({ pending: 'sent', received: 'at office', approved: 'approved', invoiced: 'invoiced' }[s] || s);
  return `
    ${errorsC.map((o) => `<div class="card"><b>${o.type === 'customer' ? 'New customer ' + h(o.name) : (o.type === 'visit' ? 'Visit ' : 'Shop edit ') + h(o.customer_name)}</b> <span class="pill bad">not accepted</span><div class="muted">${h(o.error)}</div>
      <div class="row" style="margin-top:8px"><button class="btn small light" data-act="retry" data-uuid="${o.uuid}">Try again</button><button class="btn small bad" data-act="discard" data-uuid="${o.uuid}">Delete</button></div></div>`).join('')}
    <div class="card list">${items.map((o) => `
      <div class="item tap" data-act="open-slip" data-uuid="${o.uuid}">
        <div class="row"><div class="grow"><b>${o.type === 'order' ? '🛒' : '💵'} ${h(o.number)}</b> <span class="pill ${pill(o.status)}">${h(label(o.status))}</span>
          ${o.short_stock ? '<span class="pill warn">short stock</span>' : ''}
          <div class="muted">${h(o.customer_name || '')} · ${h(o.created || '')}</div>
          ${o.status === 'error' ? `<div style="color:var(--bad);font-size:13px">${h(o.error)}</div>` : ''}
          ${o.reject_reason ? `<div style="color:var(--bad);font-size:13px">Rejected: ${h(o.reject_reason)}</div>` : ''}
          ${o.local_so_no || o.local_ref || o.invoice_no ? `<div class="muted">${h([o.local_so_no && 'SO ' + o.local_so_no, o.invoice_no && 'Invoice ' + o.invoice_no, o.local_ref && 'Ref ' + o.local_ref].filter(Boolean).join(' · '))}</div>` : ''}
        </div><b>${money(o.type === 'order' ? o.total : o.amount)}</b></div>
      </div>`).join('') || '<div class="empty">Nothing yet.</div>'}</div>`;
}

function moreView() {
  const today = new Date().toISOString().slice(0, 10);
  let collected = 0, booked = 0;
  [...S.outbox.values(), ...S.history.values()].forEach((o) => {
    if (!String(o.created || '').startsWith(today) || o.status === 'rejected') return;
    if (o.type === 'payment') collected += num(o.amount);
    if (o.type === 'order') booked += num(o.total);
  });
  return `
    <div class="card"><b>${h(S.user.name)}</b><div class="muted">${h(S.user.username)} · slips ${h(S.user.code)}-…</div></div>
    <div class="card"><div class="row"><div class="grow">Booked today</div><b>${money(booked)}</b></div>
      <div class="row"><div class="grow">Collected today (cash in hand)</div><b>${money(collected)}</b></div></div>
    <div class="card list"><b>📍 My routes</b>${myRoutes().map((r) => {
      const shops = [...S.customers.values()].filter((c) => num(c.route_id) === r.id);
      return `<div class="item tap row" data-act="route-shops" data-id="${r.id}"><div class="grow"><b>${h(r.name)}</b>
        <div class="muted">${h(routeDays(r))} · ${shops.length} shops · ${shops.filter((c) => c.position).length} with location</div></div>
        <span class="pill ${r.booker_id === S.user.id ? 'ok' : ''}">${r.booker_id === S.user.id ? 'assigned to you' : 'open to all'}</span></div>`;
    }).join('') || '<div class="empty">No route assigned to you yet. The office sets it in Sell → Booker routes.</div>'}</div>
    <div class="card muted">Last sync: ${h(S.lastSync || 'never')}<br>Stock from: ${h(S.stockAt || '—')}<br>Products ${S.products.size} · Customers ${S.customers.size} · Not sent ${S.outbox.size}</div>
    <button class="btn block" data-act="sync" style="margin-bottom:10px">⟳ Sync now</button>
    <button class="btn bad block" data-act="logout">Log out</button>`;
}

// ---------- pickers & forms ----------
function pickCustomer() {
  const m = sheet('Choose customer', `<input id="pc-q" placeholder="Search name, mobile, city" autofocus>
    <div class="card list" id="pc-list" style="margin-top:10px">${customerRows('', 'choose-customer')}</div>
    <button class="btn light block" data-act="add-customer">+ New customer</button>`);
  m.querySelector('#pc-q').addEventListener('input', (e) => { m.querySelector('#pc-list').innerHTML = customerRows(e.target.value, 'choose-customer'); });
}

function productRows(q) {
  return [...S.products.values()].filter((p) => !q || match([p.name, p.sku, p.brand, p.category].join(' '), q))
    .sort((a, b) => a.name.localeCompare(b.name)).slice(0, 60)
    .map((p) => {
      const unit = (p.units || [])[0] || { name: p.unit, multiplier: 1 };
      return `<div class="item tap" data-act="choose-product" data-v="${p.variation_id}"><div class="row"><div class="grow"><b>${h(p.name)}</b>
        <div class="muted">${h(p.sku || '')} ${h(p.brand || '')}</div></div>
        <div class="right">${money(priceOf(p) * num(unit.multiplier))}<div class="muted">${h(unit.name)} · ${stockText(p, unit) || '—'}</div></div></div></div>`;
    }).join('') || '<div class="empty">No products. Sync to download them.</div>';
}

function pickProduct() {
  const m = sheet('Add product', `<input id="pp-q" placeholder="Search name, code, brand" autofocus><div class="card list" id="pp-list" style="margin-top:10px">${productRows('')}</div>`);
  m.querySelector('#pp-q').addEventListener('input', (e) => { m.querySelector('#pp-list').innerHTML = productRows(e.target.value); });
}

function customerForm() {
  sheet('New customer', `<form data-form="customer">
    <label>Shop / customer name *</label><input name="name" required>
    <label>Owner / business name</label><input name="business_name">
    <label>Mobile</label><input name="mobile" inputmode="tel">
    <label>Address</label><input name="address">
    <label>City / area</label><input name="city">
    ${shopFields({})}
    <button class="btn block" style="margin-top:14px">Save customer</button></form>`);
}

function openCustomer(key) {
  const c = findCustomer(key);
  if (!c) return;
  const { due, pending } = customerDue(c);
  const invoices = c.local_id ? [...S.invoices.values()].filter((i) => i.contact_id === c.local_id).sort((a, b) => a.transaction_date.localeCompare(b.transaction_date)) : [];
  sheet(custName(c), `
    ${visitBlock(c, key)}
    ${c.photo_url ? `<img src="${h(photoUrl(c.photo_url))}" class="shop-photo" alt="">` : ''}
    <div class="card"><div class="muted">${h(c.mobile || '')} ${h(c.address || '')} ${h(c.city || '')}</div>
      <div class="muted">${h([routeName(c.route_id) && 'Route ' + routeName(c.route_id), c.outlet_type, c.outlet_class && 'Class ' + c.outlet_class].filter(Boolean).join(' · '))}</div>
      ${c.position ? `<div style="margin-top:6px">📍 <a href="${h(mapLink(c.position))}" target="_blank" rel="noopener">Navigate to shop</a></div>` : '<div class="muted" style="margin-top:6px">📍 No location yet — tap Edit shop at the shop and “Set location here”.</div>'}
      ${S.editsSent[c.local_id] ? `<div class="muted">Your changes went to the office ${h(ago(S.editsSent[c.local_id]))}</div>` : ''}
      <div class="row" style="margin-top:8px"><div class="grow">Balance due</div><b class="big">${money(due)}</b></div>
      ${pending ? `<div class="row muted"><div class="grow">Collected, waiting approval</div>−${money(pending)}</div>` : ''}</div>
    ${invoices.length ? `<div class="card list"><b>Unpaid invoices</b>${invoices.map((i) => `<div class="item row"><div class="grow">${h(i.invoice_no)}<div class="muted">${h(i.transaction_date.slice(0, 10))} · total ${money(i.final_total)}</div></div><b>${money(i.due)}</b></div>`).join('')}</div>` : ''}
    <div class="row">
      <button class="btn ok grow" data-act="order-for" data-key="${h(key)}">New order</button>
      <button class="btn grow" data-act="collect" data-key="${h(key)}">Collect payment</button>
    </div>
    ${c.local_id ? `<button class="btn light block" style="margin-top:8px" data-act="edit-shop" data-key="${h(key)}">✎ Edit shop (location, photo, phone…)</button>` : ''}
    ${c.mobile ? `<a class="btn light block" style="margin-top:8px;text-decoration:none" href="tel:${h(c.mobile)}">Call ${h(c.mobile)}</a>` : ''}`);
}

// ---------- visits (check in at a shop, leave it with a result) ----------
const NO_ORDER_REASONS = ['Shop closed', 'Owner not there', 'Has enough stock', 'No money / credit problem', 'Price problem', 'Other'];
const hhmm = (t) => String(t || '').slice(11, 16);

/** Metres between two GPS points. */
function distanceM(lat1, lng1, lat2, lng2) {
  const r = 6371000, rad = Math.PI / 180;
  const a = Math.sin((lat2 - lat1) * rad / 2) ** 2 + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin((lng2 - lng1) * rad / 2) ** 2;
  return Math.round(2 * r * Math.asin(Math.min(1, Math.sqrt(a))));
}
const posOf = (c) => { const p = String(c.position || '').split(',').map(Number); return p.length === 2 && !isNaN(p[0]) && !isNaN(p[1]) ? p : null; };

const routeDays = (r) => (r.days || []).map((d) => DAYS[d]).join(', ') || 'no days set';
/** Routes the booker works: their own first, then routes not assigned to anyone. */
const myRoutes = () => (S.routes || []).filter((r) => r.booker_id === S.user.id).concat((S.routes || []).filter((r) => !r.booker_id));

/** Today's routes for this booker (their own; when none, the routes nobody is assigned to). */
function todayRoutes() {
  const wd = ((new Date().getDay() + 6) % 7) + 1;
  const today = (S.routes || []).filter((r) => (r.days || []).includes(wd));
  const own = today.filter((r) => r.booker_id === S.user.id);
  return own.length ? own : today.filter((r) => !r.booker_id);
}

/** Visits of today by shop key (finished ones and the open one). */
function visitedToday() {
  const today = nowStr().slice(0, 10);
  const map = new Map();
  (S.visits || []).filter((v) => String(v.started_at).startsWith(today)).forEach((v) => map.set(v.key, v));
  return map;
}
const outcomeLabel = (v) => (v.outcome === 'order' ? 'order' : v.outcome === 'payment' ? 'payment' : (v.reason || 'no order'));

function todayCard() {
  const routes = todayRoutes();
  if (!routes.length) return '';
  const ids = routes.map((r) => r.id);
  const shops = [...S.customers.values()].filter((c) => ids.includes(num(c.route_id)))
    .sort((a, b) => ids.indexOf(num(a.route_id)) - ids.indexOf(num(b.route_id)) || num(a.visit_sequence) - num(b.visit_sequence) || a.name.localeCompare(b.name));
  const done = visitedToday();
  const visited = shops.filter((c) => done.has(c.key)).length;
  const orders = shops.filter((c) => (done.get(c.key) || {}).outcome === 'order').length;
  return `<div class="card list">
    <div class="row"><div class="grow"><b>📍 Today's route: ${routes.map((r) => h(r.name)).join(', ')}</b>
      <div class="muted">${visited} of ${shops.length} shops visited · ${orders} with orders · ${routes.some((r) => r.booker_id === S.user.id) ? 'assigned to you' : 'open route (no booker set)'}</div></div>
      <span class="pill ${shops.length && visited === shops.length ? 'ok' : 'warn'}">${shops.length ? Math.round(visited * 100 / shops.length) : 0}%</span></div>
    ${shops.map((c, i) => {
      const v = done.get(c.key);
      const now = S.visit && S.visit.key === c.key;
      return `<div class="item tap row" data-act="open-customer" data-key="${h(c.key)}"><div class="muted" style="width:24px">${i + 1}</div>
        <div class="grow"><b>${h(custName(c))}</b><div class="muted">${h([c.city, c.address].filter(Boolean).join(' · '))}${c.position ? '' : ' · no location yet'}</div></div>
        ${now ? '<span class="pill warn">in shop now</span>' : v ? `<span class="pill ${v.outcome === 'order' ? 'ok' : ''}">✓ ${h(hhmm(v.started_at))} · ${h(outcomeLabel(v))}</span>`
          : (c.position ? `<a class="btn small light" style="text-decoration:none" href="${h(mapLink(c.position))}" target="_blank" rel="noopener">Go ➜</a>` : '')}</div>`;
    }).join('') || '<div class="empty">No shops on this route yet. The office adds them in Sell → Booker routes.</div>'}</div>`;
}

function visitBanner() {
  if (!S.visit) return '';
  return `<div class="visit-bar tap" data-act="open-customer" data-key="${h(S.visit.key)}">🏪 In <b>${h(S.visit.customer_name)}</b> since ${h(hhmm(S.visit.started_at))} — tap to leave the shop</div>`;
}

function visitBlock(c, key) {
  const v = S.visit;
  if (v && v.key !== key) {
    return `<div class="card" style="border:2px solid var(--warn)">You are still checked in at <b>${h(v.customer_name)}</b>.
      <button class="btn block" style="margin-top:8px" data-act="leave-shop">🚪 Leave ${h(v.customer_name)} first</button></div>`;
  }
  if (!v) {
    return `<button class="btn ok block big-btn" data-act="check-in" data-key="${h(key)}">📍 Check in — I am at this shop</button>`;
  }
  const far = v.distance_m !== null && v.distance_m > S.radius;
  const where = v.lat === null ? '<span style="color:var(--bad)">No GPS at check-in</span>'
    : v.distance_m === null ? '<span class="muted">This shop has no saved location yet</span>'
    : far ? `<span style="color:var(--bad)">⚠ ${v.distance_m} m from the shop's saved location — the office will see this</span>`
    : `<span style="color:var(--ok)">✓ At the shop (${v.distance_m} m)</span>`;
  return `<div class="card" style="border:2px solid ${far ? 'var(--bad)' : 'var(--ok)'}">
    <div class="row"><b class="grow">🟢 In this shop since ${h(hhmm(v.started_at))}</b></div>
    <div style="margin:4px 0">${where}</div>
    ${!c.position && v.lat !== null && v.accuracy_m <= 50 && !v.locationSaved ? '<button class="btn light block" style="margin-top:6px" data-act="save-shop-location">📍 Save this as the shop location</button>' : ''}
    ${v.locationSaved ? '<div class="muted">📍 Saved as the shop location</div>' : ''}
    ${v.photo ? `<img src="${v.photo}" class="shop-photo" alt="">` : `<label class="btn light block" style="text-align:center;margin-top:6px">📷 Add photo (optional)<input type="file" accept="image/*" capture="environment" data-act="visit-photo-in" style="display:none"></label>`}
    <div class="muted" style="margin-top:6px">${(v.orders || []).length} order(s) · ${(v.payments || []).length} payment(s) in this visit</div>
    <button class="btn bad block" style="margin-top:8px" data-act="leave-shop">🚪 Leave shop</button></div>`;
}

async function checkIn(key) {
  const c = findCustomer(key);
  if (!c || S.visit) return;
  toast('Finding your location…');
  let g = null;
  try { g = await getGps(); } catch (e) {
    if (!confirm(e.message + '\n\nCheck in without location? The office will see "no GPS".')) return;
  }
  const p = posOf(c);
  S.visit = {
    uuid: uuid(), key, contact_id: c.local_id || null, customer_uuid: c.local_id ? null : c.uuid, customer_name: custName(c),
    route_id: num(c.route_id) || null, started_at: nowStr(),
    lat: g ? g.lat : null, lng: g ? g.lng : null, accuracy_m: g ? Math.round(g.accuracy) : null,
    distance_m: g && p ? distanceM(g.lat, g.lng, p[0], p[1]) : null, photo: null, orders: [], payments: [],
  };
  await db.set('visit', S.visit);
  render();
  openCustomer(key);
}

async function visitAdd(key, list, id) {
  if (!S.visit || S.visit.key !== key) return;
  S.visit[list].push(id);
  await db.set('visit', S.visit);
}

function leaveForm() {
  const v = S.visit;
  if (!v) return;
  const result = v.orders.length ? `🛒 ${v.orders.length} order(s)` : v.payments.length ? `💵 ${v.payments.length} payment(s)` : '';
  sheet('Leave ' + v.customer_name, `<form data-form="leave">
    ${result ? `<div class="card"><b>Result:</b> ${result}</div>` : `<label>Why no order?</label>
      <div class="card list" style="margin:0">${NO_ORDER_REASONS.map((r, i) => `<label class="item row" style="gap:10px"><input type="radio" name="reason" value="${h(r)}" ${i === 0 ? '' : ''} style="width:auto"> ${h(r)}</label>`).join('')}</div>`}
    <label>Note (optional)</label><input name="note">
    <button class="btn bad block" style="margin-top:14px">🚪 Leave shop</button></form>`);
}

async function leaveShop(form) {
  const v = S.visit;
  if (!v) return;
  const f = new FormData(form);
  const reason = f.get('reason') || null;
  if (!v.orders.length && !v.payments.length && !reason) { toast('Choose why there is no order'); return; }
  const outcome = v.orders.length ? 'order' : v.payments.length ? 'payment' : reason === 'Shop closed' ? 'closed' : 'no_order';
  const item = {
    uuid: v.uuid, type: 'visit', contact_id: v.contact_id, customer_uuid: v.customer_uuid, customer_name: v.customer_name, route_id: v.route_id,
    started_at: v.started_at, ended_at: nowStr(), lat: v.lat, lng: v.lng, accuracy_m: v.accuracy_m, distance_m: v.distance_m,
    photo: v.photo, outcome, reason, note: f.get('note') || null, order_uuids: v.orders, payment_uuids: v.payments, created: nowStr(),
  };
  await addToOutbox(item);
  const since = new Date(Date.now() - 3 * 86400000).toISOString().slice(0, 10);
  S.visits = (S.visits || []).filter((x) => String(x.started_at) >= since)
    .concat([{ uuid: v.uuid, key: v.key, customer_name: v.customer_name, started_at: v.started_at, ended_at: item.ended_at, outcome, reason, distance_m: v.distance_m }]);
  S.visit = null;
  await Promise.all([db.set('visit', null), db.set('visits', S.visits)]);
  closeSheet();
  S.tab = 'home';
  render();
  toast('Visit saved');
  sync();
}

/** First visit at a shop with no location: the booker's GPS becomes the shop location (filled at once on the PC). */
async function saveShopLocation() {
  const v = S.visit;
  if (!v || !v.contact_id || v.lat === null) return;
  await addToOutbox({ uuid: uuid(), type: 'customer_update', contact_id: v.contact_id, customer_name: v.customer_name,
    fields: { position: v.lat.toFixed(7) + ',' + v.lng.toFixed(7), accuracy_m: v.accuracy_m }, photo: null, created: nowStr() });
  v.locationSaved = true;
  await db.set('visit', v);
  openCustomer(v.key);
  toast('Shop location saved');
  sync();
}

/** Route, shop type / class, GPS and photo: on the new-customer form and on Edit shop. */
function shopFields(c) {
  return `
    <label>Route</label><select name="route_id"><option value="">${(S.routes || []).length ? '—' : 'No routes yet (office: Sell → Booker routes)'}</option>${(S.routes || []).map((r) => `<option value="${r.id}" ${num(c.route_id) === r.id ? 'selected' : ''}>${h(r.name)}${(r.days || []).length ? ' · ' + r.days.map((d) => DAYS[d]).join(' ') : ''}</option>`).join('')}</select>
    <div class="row"><div class="grow"><label>Shop type</label><select name="outlet_type"><option value="">—</option>${((S.outletTypes || []).length ? S.outletTypes : OUTLET_TYPES).map((t) => `<option ${c.outlet_type === t ? 'selected' : ''}>${h(t)}</option>`).join('')}</select></div>
      <div style="width:90px"><label>Class</label><select name="outlet_class"><option value="">—</option>${['A', 'B', 'C'].map((x) => `<option ${c.outlet_class === x ? 'selected' : ''}>${x}</option>`).join('')}</select></div></div>
    <label>Shop location</label>
    <div class="card" style="margin:0"><div id="gps-text" class="muted">${c.position ? '📍 Saved: ' + h(c.position) : 'Not set yet'}</div>
      <button type="button" class="btn block" style="margin-top:8px" data-act="gps-here">📍 Set location here (stand at the shop)</button></div>
    <input type="hidden" name="position"><input type="hidden" name="accuracy_m">
    <label>Shop photo</label>
    <div id="photo-prev">${c.photo_url ? `<img src="${h(photoUrl(c.photo_url))}" class="shop-photo" alt="">` : ''}</div>
    <label class="btn light block" style="text-align:center;margin:0">📷 ${c.photo_url ? 'Take a new photo' : 'Take photo'}<input type="file" accept="image/*" capture="environment" data-act="photo-in" style="display:none"></label>
    <input type="hidden" name="photo">`;
}

/** Edit an existing shop. The office approves changes to filled-in values; empty fields are filled at once. */
function editShopForm(key) {
  const c = findCustomer(key);
  if (!c || !c.local_id) return;
  sheet('Edit ' + custName(c), `<form data-form="shop-edit" data-key="${h(key)}">
    <label>Shop / customer name</label><input name="name" value="${h(c.name)}">
    <label>Owner / business name</label><input name="business_name" value="${h(c.business_name || '')}">
    <label>Mobile</label><input name="mobile" inputmode="tel" value="${h(c.mobile || '')}">
    <label>Address</label><input name="address" value="${h(c.address || '')}">
    <label>City / area</label><input name="city" value="${h(c.city || '')}">
    ${shopFields(c)}
    <div class="muted" style="margin-top:10px">Empty details are saved at once. Changes to details the office already has are checked by the office first.</div>
    <button class="btn ok block" style="margin-top:14px">Save changes</button></form>`);
}

/** Current GPS position, as accurate as the phone can get in 20 seconds. */
function getGps() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) { reject(new Error('This phone cannot give a location')); return; }
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
      (e) => reject(new Error(e.code === 1 ? 'Location is blocked: allow Location for this app in the phone settings' : 'No GPS signal. Step outside and try again')),
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  });
}

async function setGpsHere(btn) {
  const form = btn.closest('form');
  const text = form.querySelector('#gps-text');
  btn.disabled = true;
  text.textContent = 'Finding your location…';
  try {
    const g = await getGps();
    S.gpsFix = g;
    if (g.accuracy > 500) {
      // A computer (no GPS) or no satellite fix: the guess is kilometres off, never save it as the shop.
      text.innerHTML = `<span style="color:var(--bad)">This device cannot find its exact location (±${g.accuracy >= 1000 ? Math.round(g.accuracy / 1000) + ' km' : Math.round(g.accuracy) + ' m'}). Use the booker's phone at the shop with Location (GPS) switched on.</span>`;
      return;
    }
    if (g.accuracy > 50) {
      // Weak fix (indoors, or a computer guessing from Wi-Fi): let the booker retry outside or keep it knowingly.
      text.innerHTML = `<span style="color:var(--bad)">GPS is weak (±${Math.round(g.accuracy)} m). Best: step outside the shop and tap again.</span>
        <button type="button" class="btn small light" style="margin-top:6px" data-act="gps-use">Use it anyway (±${Math.round(g.accuracy)} m)</button>`;
      return;
    }
    useGps(form);
  } catch (e) {
    text.innerHTML = `<span style="color:var(--bad)">${h(e.message)}</span>`;
  } finally {
    btn.disabled = false;
  }
}

/** Put the last GPS fix into the shop form (the office sees its accuracy). */
function useGps(form) {
  const g = S.gpsFix;
  if (!g) return;
  form.querySelector('[name=position]').value = g.lat.toFixed(7) + ',' + g.lng.toFixed(7);
  form.querySelector('[name=accuracy_m]').value = Math.round(g.accuracy);
  form.querySelector('#gps-text').innerHTML = `<b style="color:${g.accuracy > 50 ? 'var(--warn)' : 'var(--ok)'}">📍 Location set</b> (±${Math.round(g.accuracy)} m) <a href="${h(mapLink(g.lat + ',' + g.lng))}" target="_blank" rel="noopener">check on map</a>`;
}

/** A camera photo made small (longest side 1280 px, JPEG) so it uploads quickly on mobile data. */
function shrinkPhoto(file) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      const k = Math.min(1, 1280 / Math.max(img.width, img.height));
      const cv = document.createElement('canvas');
      cv.width = Math.round(img.width * k); cv.height = Math.round(img.height * k);
      cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
      URL.revokeObjectURL(url);
      resolve(cv.toDataURL('image/jpeg', 0.7));
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('That is not a picture')); };
    img.src = url;
  });
}

/** Shop details from the form that differ from what the phone has (blank = not changed). */
function shopChanges(f, c) {
  const fields = {};
  ['name', 'business_name', 'mobile', 'address', 'city', 'outlet_type', 'outlet_class', 'route_id'].forEach((k) => {
    const v = String(f.get(k) || '').trim();
    if (v !== '' && v !== String(c[k] ?? '')) fields[k] = k === 'route_id' ? num(v) : v;
  });
  if (f.get('position')) { fields.position = f.get('position'); fields.accuracy_m = num(f.get('accuracy_m')); }
  return fields;
}

async function saveShopEdit(form) {
  const c = findCustomer(form.dataset.key);
  const f = new FormData(form);
  const fields = shopChanges(f, c);
  const photo = f.get('photo') || null;
  if (!Object.keys(fields).length && !photo) { toast('Nothing changed'); return; }
  await addToOutbox({ uuid: uuid(), type: 'customer_update', contact_id: c.local_id, customer_name: custName(c), fields, photo, created: nowStr() });
  closeSheet();
  render();
  toast('Saved. It goes to the office with the next sync.');
  sync();
}

function paymentForm(key) {
  const c = findCustomer(key);
  const invoices = c.local_id ? [...S.invoices.values()].filter((i) => i.contact_id === c.local_id).sort((a, b) => a.transaction_date.localeCompare(b.transaction_date)) : [];
  const m = sheet('Collect from ' + c.name, `<form data-form="payment" data-key="${h(key)}">
    <label>Amount *</label><input name="amount" inputmode="decimal" required>
    <label>Method</label><select name="method"><option value="cash">Cash</option><option value="cheque">Cheque</option><option value="bank_transfer">Bank transfer</option></select>
    <div id="cheque-box" style="display:none"><label>Cheque number</label><input name="cheque_number"></div>
    <div id="bank-box" style="display:none"><label>Bank reference</label><input name="bank_ref"></div>
    ${invoices.length ? `<label>Pay against invoices (optional — otherwise oldest dues first)</label><div class="card list">${invoices.map((i) => `
      <div class="item row"><input type="checkbox" name="inv" value="${i.id}" style="width:auto"><div class="grow">${h(i.invoice_no)}<div class="muted">due ${money(i.due)}</div></div>
      <input name="inv_amt_${i.id}" inputmode="decimal" value="${num(i.due)}" style="width:110px"></div>`).join('')}</div>` : ''}
    <label>Note</label><input name="note">
    <button class="btn ok block" style="margin-top:14px">Save payment</button></form>`);
  m.querySelector('[name=method]').addEventListener('change', (e) => {
    m.querySelector('#cheque-box').style.display = e.target.value === 'cheque' ? '' : 'none';
    m.querySelector('#bank-box').style.display = e.target.value === 'bank_transfer' ? '' : 'none';
  });
}

// ---------- slips ----------
function slipText(o) {
  const line = '-'.repeat(32);
  const out = [];
  if (S.business) out.push(S.business.toUpperCase(), line);
  if (o.type === 'order') {
    out.push('ORDER SLIP ' + o.number, 'Date: ' + (o.order_date || o.created || ''), 'Customer: ' + (o.customer_name || ''));
    if ((S.locations || []).length > 1 && (o.location_name || locName(o.location_id))) out.push('Location: ' + (o.location_name || locName(o.location_id)));
    out.push(line);
    (o.lines || []).forEach((l) => {
      out.push(l.name || ('Item ' + l.variation_id));
      out.push(`  ${qtyFmt(l.quantity)} ${l.unit_name || ''} x ${money(l.unit_price)} = ${money(num(l.quantity) * num(l.unit_price))}`);
    });
    out.push(line, 'TOTAL: ' + money(o.total));
    if (o.note) out.push('Note: ' + o.note);
  } else {
    out.push('PAYMENT RECEIPT ' + o.number, 'Date: ' + (o.paid_on || o.created || ''), 'Customer: ' + (o.customer_name || ''), line,
      'Amount: ' + money(o.amount), 'Method: ' + String(o.method || 'cash').replace('_', ' '));
    if (o.cheque_number) out.push('Cheque no: ' + o.cheque_number);
    if (o.bank_ref) out.push('Bank ref: ' + o.bank_ref);
  }
  out.push(line, 'Booker: ' + (S.user ? S.user.name : ''), 'Status: ' + (o.status || 'not sent'));
  return out.join('\n');
}

function openSlip(uuid) {
  const o = S.outbox.get(uuid) || S.history.get(uuid);
  if (!o) return;
  const text = slipText({ ...o, status: S.outbox.has(uuid) ? (o.state === 'error' ? 'not accepted: ' + o.error : 'not sent yet') : o.status });
  sheet(o.type === 'order' ? 'Order slip' : 'Receipt', `<div class="slip">${h(text)}</div>
    ${o.wa_sent ? '<div class="noprint" style="margin-top:8px"><span class="pill ok">✓ Sent to the customer on WhatsApp</span></div>' : ''}
    <div class="row noprint" style="margin-top:10px"><button class="btn grow" data-act="whatsapp" data-uuid="${uuid}" style="background:#25d366">${o.wa_sent ? 'Send again on WhatsApp' : 'Send on WhatsApp'}</button>${navigator.share ? `<button class="btn light grow" data-act="share" data-uuid="${uuid}">Share…</button>` : ''}<button class="btn light grow" data-act="print">Print</button></div>
    ${o.state === 'error' ? `<div class="row noprint" style="margin-top:8px"><button class="btn light grow" data-act="retry" data-uuid="${uuid}">Try again</button><button class="btn bad grow" data-act="discard" data-uuid="${uuid}">Delete</button></div>` : ''}`);
}

/**
 * "Send on WhatsApp": the cloud sends the slip to the customer through the shop's own WhatsApp service (the device
 * connected in the POS). Orders are sent automatically when uploaded; this sends again.
 */
async function whatsapp(uuid) {
  if (S.outbox.has(uuid)) {
    await sync();
    if (S.outbox.has(uuid)) { toast('No internet now. The customer gets it on WhatsApp automatically when this is sent.', 4000); return; }
  }
  try {
    const res = await api('POST', '/whatsapp', { uuid });
    const o = S.history.get(uuid);
    if (o) { o.wa_sent = true; await db.put('history', o); }
    toast('✓ ' + res.message);
  } catch (e) {
    toast(e.message || 'Could not send', 4000);
  }
}

async function share(uuid) {
  const o = S.outbox.get(uuid) || S.history.get(uuid);
  try { await navigator.share({ text: slipText(o) }); } catch (e) { /* closed */ }
}

// ---------- actions ----------
async function saveOrder() {
  const d = S.draft;
  const c = findCustomer(d.customer);
  if (!c || !d.lines.length) return;
  const lines = [];
  let total = 0, short = false;
  for (const l of d.lines) {
    const p = S.products.get(l.variation_id);
    if (!p) { toast('Remove the product that is no longer available'); return; }
    const unit = (p.units || []).find((u) => u.id === l.unit_id) || { id: null, name: p.unit, multiplier: 1, allow_decimal: 1 };
    const qty = num(l.qty);
    if (qty <= 0) { toast('Quantity must be more than 0: ' + p.name); return; }
    if (!num(unit.allow_decimal) && Math.floor(qty) !== qty) { toast(unit.name + ' must be a whole number: ' + p.name); return; }
    const price = lineInfo(l).price;
    const free = freeStock(p.variation_id);
    if (p.enable_stock && free !== null && qty * num(unit.multiplier) > free) short = true;
    lines.push({ variation_id: p.variation_id, sub_unit_id: unit.id, quantity: qty, unit_price: price, multiplier: num(unit.multiplier) || 1, name: p.name, unit_name: unit.name });
    total += qty * price;
  }
  if (short && !confirm('Some items are more than the available stock. Save anyway? The office will decide.')) return;

  const seq = await nextSeq('order');
  const order = {
    uuid: uuid(), type: 'order', number: `${S.user.code}-${pad(seq, 4)}`, seq,
    contact_id: c.local_id || null, customer_uuid: c.local_id ? null : c.uuid, customer_name: custName(c),
    location_id: curLocation(), location_name: locName(curLocation()),
    order_date: nowStr(), note: d.note, lines, total, short_stock: short, created: nowStr(),
  };
  await addToOutbox(order);
  await visitAdd(d.customer, 'orders', order.uuid);
  S.draft = { customer: null, lines: [], note: '' };
  S.cartOpen = false;
  await saveDraft();
  render();
  openSlip(order.uuid);
  sync();
}

async function savePayment(form) {
  const c = findCustomer(form.dataset.key);
  const f = new FormData(form);
  const amount = num(f.get('amount'));
  if (amount <= 0) { toast('Enter the amount'); return; }
  const allocations = f.getAll('inv').map((id) => ({ invoice_id: Number(id), amount: num(f.get('inv_amt_' + id)) })).filter((a) => a.amount > 0);
  if (allocations.reduce((s, a) => s + a.amount, 0) > amount + 0.0001) { toast('Invoice amounts are more than the payment'); return; }
  const seq = await nextSeq('receipt');
  const p = {
    uuid: uuid(), type: 'payment', number: `${S.user.code}-R-${pad(seq, 4)}`, seq,
    contact_id: c.local_id || null, customer_uuid: c.local_id ? null : c.uuid, customer_name: custName(c),
    amount, method: f.get('method'), cheque_number: f.get('cheque_number') || null, bank_ref: f.get('bank_ref') || null,
    note: f.get('note') || null, allocations, paid_on: nowStr(), created: nowStr(),
  };
  await addToOutbox(p);
  await visitAdd(form.dataset.key, 'payments', p.uuid);
  render();
  openSlip(p.uuid);
  sync();
}

async function saveCustomer(form) {
  const f = new FormData(form);
  const name = String(f.get('name') || '').trim();
  if (!name) return;
  const c = { uuid: uuid(), type: 'customer', name, business_name: f.get('business_name') || null, mobile: f.get('mobile') || null,
    address: f.get('address') || null, city: f.get('city') || null, route_id: num(f.get('route_id')) || null,
    outlet_type: f.get('outlet_type') || null, outlet_class: f.get('outlet_class') || null,
    position: f.get('position') || null, photo: f.get('photo') || null, created: nowStr() };
  await addToOutbox(c);
  closeSheet();
  S.draft.customer = 'u' + c.uuid;
  await saveDraft();
  S.tab = 'order';
  render();
  toast('Customer saved');
  sync();
}

async function login(form) {
  const f = new FormData(form);
  const btn = form.querySelector('button');
  btn.disabled = true;
  try {
    const res = await api('POST', '/login', { username: f.get('username'), password: f.get('password'), device_name: navigator.userAgent.slice(0, 180) });
    if (S.user && S.user.id !== res.user.id) {
      if (S.outbox.size && !confirm('Another booker\'s unsent work is on this phone and will be deleted. Continue?')) { btn.disabled = false; return; }
      await db.clear(['products', 'customers', 'invoices', 'outbox', 'history']);
      S.products.clear(); S.customers.clear(); S.invoices.clear(); S.outbox.clear(); S.history.clear();
      S.since = null; S.seq = { order: 1, receipt: 1 };
    }
    S.token = res.token;
    if (res.business) { S.business = res.business; await db.set('business', res.business); }
    S.user = res.user;
    // Never reuse a slip number already sent (reinstall, second phone).
    S.seq = { order: Math.max(S.seq.order || 1, res.next_order_seq), receipt: Math.max(S.seq.receipt || 1, res.next_receipt_seq) };
    if (res.locations) { S.locations = res.locations; await db.set('locations', S.locations); }
    await Promise.all([db.set('token', S.token), db.set('user', S.user), db.set('seq', S.seq)]);
    render();
    await sync(true);
  } catch (e) {
    toast(e.message || 'Cannot reach the server', 4000);
    btn.disabled = false;
  }
}

async function logout() {
  if (S.outbox.size && !confirm(S.outbox.size + ' item(s) are not sent yet. They stay on this phone and go after you log in again. Log out?')) return;
  try { await api('POST', '/logout'); } catch (e) { /* offline: token dies with the booker's block or expiry */ }
  S.token = null;
  await db.set('token', null);
  render();
}

document.addEventListener('click', async (e) => {
  if (e.target.closest('a[href]')) return;
  const el = e.target.closest('[data-act]');
  if (!el || el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') return;
  const act = el.dataset.act;
  const d = S.draft;
  const i = Number(el.dataset.i);
  switch (act) {
    case 'close': S.cartOpen = false; closeSheet(); break;
    case 'add': {
      // Tap a product card: one more of its first unit (e.g. 1 CTN 24).
      const p = S.products.get(Number(el.dataset.v));
      const unit = (p.units || [])[0];
      const uid = unit ? unit.id : null;
      const line = d.lines.find((l) => l.variation_id === p.variation_id && l.unit_id === uid);
      if (line) line.qty = num(line.qty) + 1; else d.lines.push({ variation_id: p.variation_id, unit_id: uid, qty: 1 });
      S.posQuery = '';
      await saveDraft(); refreshPos();
      if ($('#prod-q')) { $('#prod-q').value = ''; $('#prod-dd').innerHTML = ''; $('#prod-q').focus(); }
      break;
    }
    case 'pos-brand': S.posBrand = el.dataset.brand; render(); break;
    case 'open-cart': openCart(); break;
    case 'tab': S.cartOpen = false; closeSheet(); S.tab = el.dataset.tab; render(); window.scrollTo(0, 0); break;
    case 'sync': sync(true); break;
    case 'logout': logout(); break;
    case 'pick-customer': pickCustomer(); break;
    case 'pick-product': pickProduct(); break;
    case 'add-customer': customerForm(); break;
    case 'open-customer': openCustomer(el.dataset.key); break;
    case 'edit-shop': editShopForm(el.dataset.key); break;
    case 'gps-here': setGpsHere(el); break;
    case 'gps-use': useGps(el.closest('form')); break;
    case 'check-in': checkIn(el.dataset.key); break;
    case 'route-shops': S.custRoute = num(el.dataset.id); S.custQuery = ''; S.tab = 'customers'; render(); window.scrollTo(0, 0); break;
    case 'leave-shop': leaveForm(); break;
    case 'save-shop-location': saveShopLocation(); break;
    case 'choose-customer': d.customer = el.dataset.key; await saveDraft(); closeSheet(); render(); if ($('#prod-q')) $('#prod-q').focus(); break;
    case 'order-for': d.customer = el.dataset.key; await saveDraft(); closeSheet(); S.tab = 'order'; render(); break;
    case 'collect': paymentForm(el.dataset.key); break;
    case 'choose-product': {
      const p = S.products.get(Number(el.dataset.v));
      const unit = (p.units || [])[0];
      d.lines.push({ variation_id: p.variation_id, unit_id: unit ? unit.id : null, qty: 1 });
      await saveDraft(); closeSheet(); render(); break;
    }
    case 'line-del': d.lines.splice(i, 1); await saveDraft(); refreshPos(); break;
    case 'qty': d.lines[i].qty = Math.max(0, num(d.lines[i].qty) + Number(el.dataset.d)); await saveDraft(); refreshPos(); break;
    case 'clear-order': if (confirm('Clear this order?')) { S.draft = { customer: null, lines: [], note: '' }; S.cartOpen = false; closeSheet(); await saveDraft(); render(); } break;
    case 'save-order': saveOrder(); break;
    case 'open-slip': openSlip(el.dataset.uuid); break;
    case 'whatsapp': whatsapp(el.dataset.uuid); break;
    case 'share': share(el.dataset.uuid); break;
    case 'print': window.print(); break;
    case 'retry': {
      const o = S.outbox.get(el.dataset.uuid);
      if (o) { o.state = null; o.error = null; await db.put('outbox', o); closeSheet(); render(); sync(true); }
      break;
    }
    case 'discard':
      if (confirm('Delete this from the phone? It was not accepted by the office.')) { S.outbox.delete(el.dataset.uuid); await db.del('outbox', el.dataset.uuid); closeSheet(); render(); }
      break;
  }
});

document.addEventListener('change', async (e) => {
  const el = e.target;
  const i = Number(el.dataset.i);
  if (el.dataset.act === 'pos-location') { S.location = Number(el.value); await db.set('location', S.location); render(); toast('Booking for ' + locName(S.location)); }
  if (el.dataset.act === 'line-unit') { S.draft.lines[i].unit_id = Number(el.value); delete S.draft.lines[i].price; await saveDraft(); refreshPos(); }
  if (el.dataset.act === 'price-in') {
    const v = String(el.value).replace(/,/g, '').trim();
    if (v === '' || isNaN(Number(v)) || Number(v) < 0) delete S.draft.lines[i].price; else S.draft.lines[i].price = Number(v);
    await saveDraft(); refreshPos();
  }
  if (el.dataset.act === 'visit-photo-in' && el.files && el.files[0] && S.visit) {
    try {
      S.visit.photo = await shrinkPhoto(el.files[0]);
      await db.set('visit', S.visit);
      openCustomer(S.visit.key);
    } catch (err) { toast(err.message); }
  }
  if (el.dataset.act === 'photo-in' && el.files && el.files[0]) {
    const form = el.closest('form');
    try {
      const data = await shrinkPhoto(el.files[0]);
      form.querySelector('[name=photo]').value = data;
      form.querySelector('#photo-prev').innerHTML = `<img src="${data}" class="shop-photo" alt="">`;
    } catch (err) { toast(err.message); }
  }
  if (el.dataset.act === 'cust-route') { S.custRoute = num(el.value) || null; $('#cust-list').innerHTML = customerRows(S.custQuery || '', 'open-customer'); }
  if (el.dataset.act === 'qty-in') { S.draft.lines[i].qty = num(el.value); await saveDraft(); refreshPos(); }
});

document.addEventListener('input', (e) => {
  const el = e.target;
  if (el.dataset.act === 'note') { S.draft.note = el.value; saveDraft(); }
  if (el.dataset.act === 'pos-search') { S.posQuery = el.value; $('#prod-dd').innerHTML = productDropdown(); }
  if (el.dataset.act === 'pos-cust') { $('#cust-dd').innerHTML = customerDropdown(el.value); }
  if (el.dataset.act === 'cust-search') { S.custQuery = el.value; $('#cust-list').innerHTML = customerRows(el.value, 'open-customer'); }
});

document.addEventListener('submit', (e) => {
  const form = e.target;
  e.preventDefault();
  ({ login, customer: saveCustomer, payment: savePayment, 'shop-edit': saveShopEdit, leave: leaveShop }[form.dataset.form] || (() => {}))(form);
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && e.target.id === 'prod-q') { e.preventDefault(); const first = document.querySelector('#prod-dd .dd-item[data-v]'); if (first) first.click(); }
  if (e.key === 'Escape') document.querySelectorAll('.dd').forEach((d) => { d.innerHTML = ''; });
});

document.addEventListener('focusin', (e) => {
  if (e.target.id === 'cust-q') { e.target.select(); $('#cust-dd').innerHTML = customerDropdown(''); }
});

document.addEventListener('mousedown', (e) => {
  if (!e.target.closest('.ig-wrap')) document.querySelectorAll('.dd').forEach((d) => { d.innerHTML = ''; });
});

window.addEventListener('online', () => { renderBar(); sync(); });
window.addEventListener('offline', renderBar);

(async function start() {
  await db.open();
  await loadState();
  render();
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
  if (S.token) sync();
  setInterval(() => sync(), SYNC_EVERY_MS);
  setInterval(renderBar, 30000);
})();
