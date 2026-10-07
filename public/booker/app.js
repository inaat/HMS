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
  draft: { customer: null, lines: [], note: '' }, tab: 'order', syncing: false, online: navigator.onLine,
};

async function loadState() {
  for (const k of ['token', 'user', 'since', 'stock', 'stockAt', 'lastSync', 'seq', 'draft']) {
    const v = await db.get(k);
    if (v !== undefined && v !== null) S[k] = v;
  }
  if (!Array.isArray(S.draft.lines)) S.draft = { customer: null, lines: [], note: '' };
  (await db.all('products')).forEach((p) => S.products.set(p.variation_id, p));
  (await db.all('customers')).forEach((c) => S.customers.set(c.key, c));
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
  m.addEventListener('click', (e) => { if (e.target === m) closeSheet(); });
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
const custKey = (row) => (row.local_id ? 'c' + row.local_id : 'u' + row.uuid);
const custName = (c) => (c ? c.name + (c.business_name ? ' (' + c.business_name + ')' : '') : '');

/** Free stock in base units for a variation: what the server says, less this phone's unsent orders. */
function freeStock(variation_id) {
  const s = S.stock[variation_id];
  if (s === null || s === undefined) return null;
  let held = 0;
  S.outbox.forEach((o) => {
    if (o.type === 'order') o.lines.forEach((l) => { if (l.variation_id === variation_id) held += num(l.quantity) * num(l.multiplier || 1); });
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
    if (manual) toast('Synced');
  } catch (e) {
    if (e.status === 401) {
      S.token = null;
      await db.set('token', null);
      toast(e.message, 4000);
    } else if (manual) {
      toast('Sync failed: ' + e.message, 4000);
    }
  } finally {
    S.syncing = false;
    render();
  }
}

async function upload() {
  const items = [...S.outbox.values()].filter((o) => o.state !== 'error').sort((a, b) => a.created.localeCompare(b.created));
  if (!items.length) return;
  const body = { customers: [], orders: [], payments: [] };
  items.forEach((o) => body[o.type === 'customer' ? 'customers' : o.type + 's'].push(o));
  const res = await api('POST', '/upload', body);

  const done = [];
  ['customers', 'orders', 'payments'].forEach((k) => (res[k] || []).forEach((r) => {
    const item = S.outbox.get(r.uuid);
    if (!item) return;
    if (r.result === 'saved' || r.result === 'duplicate') {
      done.push(item.uuid);
      if (item.type !== 'customer') {
        const hist = { ...item, status: r.status || 'pending', short_stock: !!r.short_stock, sent: nowStr() };
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
    const row = { ...c, key };
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

  S.stock = data.stock || {};
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
  const title = { order: 'New order', customers: 'Customers', activity: 'My orders & payments', more: 'Account' }[S.tab];
  const waiting = S.outbox.size;
  app.innerHTML = `
    <header><h1>${h(title)}</h1><button data-act="sync">${S.syncing ? 'Syncing…' : '⟳ Sync'}</button></header>
    <div class="bar" id="bar"></div>
    <main>${{ order: orderView, customers: customersView, activity: activityView, more: moreView }[S.tab]()}</main>
    <nav>
      ${[['order', '🛒', 'Order'], ['customers', '👥', 'Customers'], ['activity', '📋', 'My work' + (waiting ? ' (' + waiting + ')' : '')], ['more', '⚙️', 'Account']]
        .map(([t, i, l]) => `<button data-act="tab" data-tab="${t}" class="${S.tab === t ? 'on' : ''}"><b>${i}</b>${h(l)}</button>`).join('')}
    </nav>`;
  renderBar();
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
    ${S.syncing ? '<span class="pill">syncing…</span>' : ''}`;
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

function orderView() {
  const d = S.draft;
  const c = d.customer ? findCustomer(d.customer) : null;
  let total = 0;
  const lines = d.lines.map((l, i) => {
    const p = S.products.get(l.variation_id);
    if (!p) return `<div class="card"><span class="pill bad">Product no longer available</span> <button class="btn small bad" data-act="line-del" data-i="${i}">Remove</button></div>`;
    const unit = (p.units || []).find((u) => u.id === l.unit_id) || { id: null, name: p.unit, multiplier: 1 };
    const price = num(p.price) * num(unit.multiplier);
    const lineTotal = price * num(l.qty);
    total += lineTotal;
    const free = freeStock(p.variation_id);
    const short = p.enable_stock && free !== null && num(l.qty) * num(unit.multiplier) > free;
    return `<div class="card">
      <div class="row"><div class="grow"><b>${h(p.name)}</b><div class="muted">${h(p.sku || '')} · ${money(price)} / ${h(unit.name)}</div></div>
        <button class="x" data-act="line-del" data-i="${i}">&times;</button></div>
      <div class="row" style="margin-top:8px">
        ${(p.units || []).length > 1 ? `<select data-act="line-unit" data-i="${i}" style="width:auto;flex:1">${p.units.map((u) => `<option value="${u.id}" ${u.id === l.unit_id ? 'selected' : ''}>${h(u.name)}</option>`).join('')}</select>` : `<span class="grow">${h(unit.name)}</span>`}
        <div class="qty"><button data-act="qty" data-i="${i}" data-d="-1">−</button><input inputmode="decimal" data-act="qty-in" data-i="${i}" value="${h(l.qty)}"><button data-act="qty" data-i="${i}" data-d="1">+</button></div>
      </div>
      <div class="row muted" style="margin-top:6px"><span class="grow">Available: ${stockText(p, unit) || 'not tracked'} ${short ? '<span class="pill warn">short stock</span>' : ''}</span><b>${money(lineTotal)}</b></div>
    </div>`;
  }).join('');

  return `
    <div class="card tap" data-act="pick-customer">
      ${c ? `<div class="row"><div class="grow"><b>${h(custName(c))}</b><div class="muted">${h(c.mobile || '')} ${h(c.city || '')}</div></div><span class="muted">Due ${money(customerDue(c).due)}</span></div>`
        : '<div class="row"><b class="grow">Choose customer</b><span>›</span></div>'}
    </div>
    ${lines || '<div class="empty">No items yet.</div>'}
    <button class="btn light block" data-act="pick-product">+ Add product</button>
    <label>Note for the shop</label>
    <textarea rows="2" data-act="note">${h(d.note)}</textarea>
    <div class="total"><div class="grow"><div class="muted">${d.lines.length} item(s)</div><div class="big">${money(total)}</div></div>
      ${d.lines.length || d.customer ? '<button class="btn small bad" data-act="clear-order">Clear</button>' : ''}
      <button class="btn ok" data-act="save-order" ${!c || !d.lines.length ? 'disabled' : ''}>Save order</button></div>`;
}

function customersView() {
  return `
    <div class="row" style="margin-bottom:10px"><input id="cust-q" placeholder="Search name, mobile, city" data-act="cust-search" class="grow" value="${h(S.custQuery || '')}">
      <button class="btn small" data-act="add-customer">+ New</button></div>
    <div class="card list" id="cust-list">${customerRows(S.custQuery || '', 'open-customer')}</div>`;
}

function customerRows(q, act) {
  const list = [...S.customers.values()].filter((c) => !q || match([c.name, c.business_name, c.mobile, c.city].join(' '), q))
    .sort((a, b) => a.name.localeCompare(b.name)).slice(0, 60);
  const newOnes = [...S.outbox.values()].filter((o) => o.type === 'customer' && (!q || match([o.name, o.mobile, o.city].join(' '), q)));
  const rows = [
    ...newOnes.map((o) => `<div class="item tap" data-act="${act}" data-key="u${o.uuid}"><b>${h(o.name)}</b> <span class="pill warn">new · not sent</span><div class="muted">${h(o.mobile || '')} ${h(o.city || '')}</div></div>`),
    ...list.map((c) => {
      const { due, pending } = customerDue(c);
      return `<div class="item tap" data-act="${act}" data-key="${h(c.key)}"><div class="row"><div class="grow"><b>${h(custName(c))}</b>
        ${c.status === 'pending' ? '<span class="pill">new</span>' : ''}<div class="muted">${h(c.mobile || '')} ${h(c.city || '')}</div></div>
        <div class="right"><div class="${due > 0 ? '' : 'muted'}">${money(due)}</div>${pending ? `<div class="muted">−${money(pending)} collected</div>` : ''}</div></div></div>`;
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
  const items = [...S.outbox.values()].filter((o) => o.type !== 'customer').map((o) => ({ ...o, status: o.state === 'error' ? 'error' : 'not sent' }))
    .concat([...S.history.values()])
    .sort((a, b) => String(b.created).localeCompare(String(a.created))).slice(0, 150);
  const errorsC = [...S.outbox.values()].filter((o) => o.type === 'customer' && o.state === 'error');
  const pill = (s) => ({ 'not sent': 'warn', error: 'bad', pending: '', received: '', approved: 'ok', invoiced: 'ok', rejected: 'bad' }[s] ?? '');
  const label = (s) => ({ pending: 'sent', received: 'at office', approved: 'approved', invoiced: 'invoiced' }[s] || s);
  return `
    ${errorsC.map((o) => `<div class="card"><b>New customer ${h(o.name)}</b> <span class="pill bad">not accepted</span><div class="muted">${h(o.error)}</div>
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
        <div class="right">${money(num(p.price) * num(unit.multiplier))}<div class="muted">${h(unit.name)} · ${stockText(p, unit) || '—'}</div></div></div></div>`;
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
    <button class="btn block" style="margin-top:14px">Save customer</button></form>`);
}

function openCustomer(key) {
  const c = findCustomer(key);
  if (!c) return;
  const { due, pending } = customerDue(c);
  const invoices = c.local_id ? [...S.invoices.values()].filter((i) => i.contact_id === c.local_id).sort((a, b) => a.transaction_date.localeCompare(b.transaction_date)) : [];
  sheet(custName(c), `
    <div class="card"><div class="muted">${h(c.mobile || '')} ${h(c.address || '')} ${h(c.city || '')}</div>
      <div class="row" style="margin-top:8px"><div class="grow">Balance due</div><b class="big">${money(due)}</b></div>
      ${pending ? `<div class="row muted"><div class="grow">Collected, waiting approval</div>−${money(pending)}</div>` : ''}</div>
    ${invoices.length ? `<div class="card list"><b>Unpaid invoices</b>${invoices.map((i) => `<div class="item row"><div class="grow">${h(i.invoice_no)}<div class="muted">${h(i.transaction_date.slice(0, 10))} · total ${money(i.final_total)}</div></div><b>${money(i.due)}</b></div>`).join('')}</div>` : ''}
    <div class="row">
      <button class="btn ok grow" data-act="order-for" data-key="${h(key)}">New order</button>
      <button class="btn grow" data-act="collect" data-key="${h(key)}">Collect payment</button>
    </div>
    ${c.mobile ? `<a class="btn light block" style="margin-top:8px;text-decoration:none" href="tel:${h(c.mobile)}">Call ${h(c.mobile)}</a>` : ''}`);
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
  if (o.type === 'order') {
    out.push('ORDER SLIP ' + o.number, 'Date: ' + (o.order_date || o.created || ''), 'Customer: ' + (o.customer_name || ''), line);
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
    <div class="row noprint" style="margin-top:10px"><button class="btn grow" data-act="share" data-uuid="${uuid}">Share / WhatsApp</button><button class="btn light grow" data-act="print">Print</button></div>
    ${o.state === 'error' ? `<div class="row noprint" style="margin-top:8px"><button class="btn light grow" data-act="retry" data-uuid="${uuid}">Try again</button><button class="btn bad grow" data-act="discard" data-uuid="${uuid}">Delete</button></div>` : ''}`);
}

async function share(uuid) {
  const o = S.outbox.get(uuid) || S.history.get(uuid);
  const text = slipText(o);
  if (navigator.share) {
    try { await navigator.share({ text }); return; } catch (e) { if (e.name === 'AbortError') return; }
  }
  location.href = 'https://wa.me/?text=' + encodeURIComponent(text);
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
    const price = num(p.price) * num(unit.multiplier);
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
    order_date: nowStr(), note: d.note, lines, total, short_stock: short, created: nowStr(),
  };
  await addToOutbox(order);
  S.draft = { customer: null, lines: [], note: '' };
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
  render();
  openSlip(p.uuid);
  sync();
}

async function saveCustomer(form) {
  const f = new FormData(form);
  const name = String(f.get('name') || '').trim();
  if (!name) return;
  const c = { uuid: uuid(), type: 'customer', name, business_name: f.get('business_name') || null, mobile: f.get('mobile') || null,
    address: f.get('address') || null, city: f.get('city') || null, created: nowStr() };
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
    S.user = res.user;
    // Never reuse a slip number already sent (reinstall, second phone).
    S.seq = { order: Math.max(S.seq.order || 1, res.next_order_seq), receipt: Math.max(S.seq.receipt || 1, res.next_receipt_seq) };
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
  const el = e.target.closest('[data-act]');
  if (!el || el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') return;
  const act = el.dataset.act;
  const d = S.draft;
  const i = Number(el.dataset.i);
  switch (act) {
    case 'close': closeSheet(); break;
    case 'tab': S.tab = el.dataset.tab; render(); window.scrollTo(0, 0); break;
    case 'sync': sync(true); break;
    case 'logout': logout(); break;
    case 'pick-customer': pickCustomer(); break;
    case 'pick-product': pickProduct(); break;
    case 'add-customer': customerForm(); break;
    case 'open-customer': openCustomer(el.dataset.key); break;
    case 'choose-customer': d.customer = el.dataset.key; await saveDraft(); closeSheet(); render(); break;
    case 'order-for': d.customer = el.dataset.key; await saveDraft(); closeSheet(); S.tab = 'order'; render(); break;
    case 'collect': paymentForm(el.dataset.key); break;
    case 'choose-product': {
      const p = S.products.get(Number(el.dataset.v));
      const unit = (p.units || [])[0];
      d.lines.push({ variation_id: p.variation_id, unit_id: unit ? unit.id : null, qty: 1 });
      await saveDraft(); closeSheet(); render(); break;
    }
    case 'line-del': d.lines.splice(i, 1); await saveDraft(); render(); break;
    case 'qty': d.lines[i].qty = Math.max(0, num(d.lines[i].qty) + Number(el.dataset.d)); await saveDraft(); render(); break;
    case 'clear-order': if (confirm('Clear this order?')) { S.draft = { customer: null, lines: [], note: '' }; await saveDraft(); render(); } break;
    case 'save-order': saveOrder(); break;
    case 'open-slip': openSlip(el.dataset.uuid); break;
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
  if (el.dataset.act === 'line-unit') { S.draft.lines[i].unit_id = Number(el.value); await saveDraft(); render(); }
  if (el.dataset.act === 'qty-in') { S.draft.lines[i].qty = num(el.value); await saveDraft(); render(); }
});

document.addEventListener('input', (e) => {
  const el = e.target;
  if (el.dataset.act === 'note') { S.draft.note = el.value; saveDraft(); }
  if (el.dataset.act === 'cust-search') { S.custQuery = el.value; $('#cust-list').innerHTML = customerRows(el.value, 'open-customer'); }
});

document.addEventListener('submit', (e) => {
  const form = e.target;
  e.preventDefault();
  ({ login, customer: saveCustomer, payment: savePayment }[form.dataset.form] || (() => {}))(form);
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
