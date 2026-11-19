'use strict';
/*
 * Overnight end-to-end browser script (run 2026-11-19, task T-09): checks E1-E9 in headless Chromium,
 * once at 390 x 844 and once at 1366 x 768 (a fresh server per viewport).
 *
 *   node tests/browser/e2e_night.cjs --root repo      serve the working copy (default)
 *   node tests/browser/e2e_night.cjs --root bundle    serve deploy/upload/hub (the upload bundle)
 *
 * Starts tests/browser/serve_night.php (fresh SQLite + fixtures, test database only; E7 uses a second server
 * started with --fincon-down), prints one PASS/FAIL line per check per viewport, takes one screenshot per check
 * per viewport into docs/night/2026-11-19/e2e/<WxH>/, writes docs/night/2026-11-19/e2e/RESULTS.md and prints
 * the final line "E2E: <n> passed, <n> failed". Exit 0 only when nothing failed.
 *
 * A check also fails on any console error, page error or HTTP >= 400 seen by the browser while it ran, unless
 * that check declared the response as expected (each one is named in the note column). API calls the script
 * makes itself (Node fetch, localhost only) assert their status explicitly and are listed in the note.
 * Fixture logins come from bin/seed.php; passwords are never printed or written. Data the script creates
 * starts with TEST-NB- and uses @example.invalid. Plain Node, no packages other than Playwright (found, not installed).
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const { spawn, spawnSync } = require('child_process');

const ROOT_DIR = path.join(__dirname, '..', '..');
const SERVE_PHP = path.join(__dirname, 'serve_night.php');
const OUT_DIR = path.join(ROOT_DIR, 'docs', 'night', '2026-11-19', 'e2e');
const VIEWPORTS = [{ width: 390, height: 844 }, { width: 1366, height: 768 }];
const CHECKS = [
  ['E1', 'hq, hub and franchise fixture logins reach index.html with the right nav for their role'],
  ['E2', 'order pending -> in_process -> out_for_delivery -> delivered in the UI; stock credited exactly once'],
  ['E3', 'head office cancels from pending and from in_process; a hub user cannot cancel'],
  ['E4', 'a batch deducts ingredients and shows cost per item in R with exactly 2 decimals'],
  ['E5', 'finished-goods report shows the batch; CSV download works'],
  ['E6', 'franchise user sees stock status only (no cost, price, recipe, Hub pricing)'],
  ['E7', 'FinCon unavailable: orders still work and stock shows "in stock"'],
  ['E8', 'warehouse, ingredients, stock and accounts pages load and show their data'],
  ['E9', 'failed-login limit: 5 wrong passwords, then the right one is refused with the same message']
];
const CSV_HEADER = 'finished_sku,name,unit,quantity,total_cost,avg_cost_per_item,batch_count';

/* ---------- small helpers ---------- */

function oneLine(text, max) {
  const t = String(text).replace(/\s+/g, ' ').trim();
  return t.length > (max || 500) ? t.slice(0, max || 500) + '...' : t;
}

function sleep(ms) {
  return new Promise(function (resolve) { setTimeout(resolve, ms); });
}

function ok(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function milli(n) {
  return Math.round(Number(n) * 1000);
}

/* Fixture logins (role -> {username, email, password}) from bin/seed.php, the one place they are defined. */
function readFixtures() {
  const src = fs.readFileSync(path.join(ROOT_DIR, 'bin', 'seed.php'), 'utf8');
  const re = /\[\s*'[^']*'\s*,\s*'(hq|hub|franchise)'\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*\]/g;
  const out = {};
  let m;
  while ((m = re.exec(src)) !== null) {
    if (!out[m[1]]) {
      out[m[1]] = { username: m[2], email: m[3], password: m[4] };
    }
  }
  for (const role of ['hq', 'hub', 'franchise']) {
    if (!out[role]) {
      throw new Error(role + ' fixture row not found in bin/seed.php');
    }
  }
  return out;
}

function loadPlaywright() {
  const tries = [
    function () { return require('playwright'); },
    function () {
      return require(path.join(process.env.APPDATA || '', 'npm', 'node_modules', '@playwright', 'cli', 'node_modules', 'playwright'));
    }
  ];
  for (const t of tries) {
    try {
      const pw = t();
      if (pw && pw.chromium) {
        return pw;
      }
    } catch (e) {
      // try the next place
    }
  }
  return null;
}

function parseRoot() {
  const a = process.argv.slice(2);
  let root = 'repo';
  for (let i = 0; i < a.length; i++) {
    if (a[i] === '--root' && a[i + 1]) {
      root = a[++i];
    } else if (a[i].indexOf('--root=') === 0) {
      root = a[i].slice(7);
    }
  }
  return root === 'repo' || root === 'bundle' ? root : null;
}

/* ---------- the PHP server ---------- */

let PHP_USED = null;

function startServerWith(php, root, extra) {
  return new Promise(function (resolve, reject) {
    const child = spawn(php, [SERVE_PHP, '--root', root].concat(extra), { cwd: ROOT_DIR, stdio: ['pipe', 'pipe', 'pipe'], windowsHide: true });
    let out = '';
    let err = '';
    let settled = false;
    let timer = null;
    const done = function (fn, value) {
      if (!settled) {
        settled = true;
        clearTimeout(timer);
        fn(value);
      }
    };
    timer = setTimeout(function () {
      stopChild(child).then(function () {
        done(reject, Object.assign(new Error('no PORT line within 90 s; stderr: ' + oneLine(err)), { started: true }));
      });
    }, 90000);
    child.on('error', function (e) { done(reject, e); });
    child.stderr.on('data', function (d) { err += d.toString(); });
    child.stdout.on('data', function (d) {
      out += d.toString();
      const m = /^PORT=(\d+)\s*$/m.exec(out);
      const db = /^DB=(.+?)\s*$/m.exec(out);
      if (m) {
        done(resolve, { child: child, port: Number(m[1]), db: db ? db[1] : null });
      }
    });
    child.on('exit', function (code) {
      done(reject, Object.assign(new Error('serve_night.php exited (' + code + ') before printing PORT: ' + oneLine(err + ' ' + out)), { started: true }));
    });
  });
}

async function startServer(root, extra) {
  const candidates = [];
  if (PHP_USED) {
    candidates.push(PHP_USED);
  } else {
    if (process.env.PHP_BIN) {
      candidates.push(process.env.PHP_BIN);
    }
    candidates.push('php');
    if (process.env.LOCALAPPDATA) {
      candidates.push(path.join(process.env.LOCALAPPDATA, 'php', 'php.exe'));
    }
  }
  let last = null;
  for (const php of candidates) {
    if (php.indexOf(path.sep) !== -1 && !fs.existsSync(php)) {
      continue;
    }
    try {
      const s = await startServerWith(php, root, extra || []);
      PHP_USED = php;
      return s;
    } catch (e) {
      last = e;
      if (e.started) {
        break;
      }
    }
  }
  throw last || new Error('php not found');
}

/* Close serve_night.php's stdin so its own cleanup runs, wait up to 10 s, kill only as a last resort (and say so). */
async function stopChild(child) {
  if (child.exitCode !== null || child.signalCode !== null) {
    return;
  }
  const exited = new Promise(function (resolve) { child.once('exit', resolve); });
  try { child.stdin.end(); } catch (e) { /* already closed */ }
  const timedOut = await Promise.race([exited.then(function () { return false; }), sleep(10000).then(function () { return true; })]);
  if (timedOut) {
    console.log('WARN serve_night.php did not exit within 10 s after stdin was closed; killing it (its cleanup may not have run: check for php -S and var/test)');
    if (process.platform === 'win32' && child.pid) {
      try { spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' }); } catch (e) { /* fall through */ }
    }
    child.kill();
  }
}

async function stopServer(server) {
  if (server && server.child) {
    await stopChild(server.child);
  }
}

/* Read-only look at the test app's own SQLite file (the API has no movements list). */
function deliveredMovements(dbPath, orderId) {
  const code = '$p = new PDO("sqlite:" . $argv[1]); $s = $p->prepare("SELECT sku, qty_delta_milli FROM inventory_movements ' +
    'WHERE reason = \'order_delivered\' AND ref_id = ? ORDER BY id"); $s->execute([(int) $argv[2]]); echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));';
  const r = spawnSync(PHP_USED || 'php', ['-r', code, '--', dbPath, String(orderId)], { encoding: 'utf8', windowsHide: true });
  if (r.status !== 0) {
    throw new Error('test DB read failed: ' + oneLine(r.stderr || r.stdout, 200));
  }
  return JSON.parse(r.stdout).map(function (row) { return { sku: row.sku, qty_milli: Number(row.qty_delta_milli) }; });
}

/* ---------- the browser (default build first, else the newest one already installed) ---------- */

function newestInstalledBrowser() {
  const base = path.join(process.env.LOCALAPPDATA || '', 'ms-playwright');
  let names = [];
  try {
    names = fs.readdirSync(base);
  } catch (e) {
    return null;
  }
  const kinds = [
    { prefix: 'chromium_headless_shell-', exe: path.join('chrome-headless-shell-win64', 'chrome-headless-shell.exe') },
    { prefix: 'chromium-', exe: path.join('chrome-win64', 'chrome.exe') }
  ];
  for (const kind of kinds) {
    const found = names
      .filter(function (n) { return n.indexOf(kind.prefix) === 0 && /^\d+$/.test(n.slice(kind.prefix.length)); })
      .map(function (n) { return { n: Number(n.slice(kind.prefix.length)), file: path.join(base, n, kind.exe) }; })
      .filter(function (c) { return fs.existsSync(c.file); })
      .sort(function (a, b) { return b.n - a.n; });
    if (found.length > 0) {
      return found[0].file;
    }
  }
  return null;
}

async function launchBrowser(pw) {
  try {
    return { browser: await pw.chromium.launch({ headless: true }), binary: 'default' };
  } catch (e) {
    if (!/Executable doesn't exist/i.test(String(e && e.message))) {
      throw e;
    }
    const exe = newestInstalledBrowser();
    if (!exe) {
      throw e;
    }
    return { browser: await pw.chromium.launch({ headless: true, executablePath: exe }), binary: exe };
  }
}

/* ---------- problems seen by the browser ---------- */

const problems = [];

function watch(context, label) {
  context.on('page', function (page) {
    page.on('console', function (m) {
      if (m.type() === 'error') {
        const loc = m.location ? (m.location().url || '') : '';
        problems.push({ kind: 'console', url: loc, text: label + ' console error: ' + oneLine(m.text(), 200) + (loc ? ' @ ' + loc : '') });
      }
    });
    page.on('pageerror', function (e) {
      problems.push({ kind: 'pageerror', text: label + ' page error: ' + oneLine(e.message, 200) });
    });
    page.on('response', function (r) {
      if (r.status() >= 400) {
        problems.push({
          kind: 'http', status: r.status(), method: r.request().method(), url: r.url(),
          text: label + ' HTTP ' + r.status() + ' ' + r.request().method() + ' ' + r.url()
        });
      }
    });
  });
}

/* Is a browser problem one the check declared as expected? Chromium also logs a console error
 * "Failed to load resource: ... status of <n>" for the same response; that is matched by status and url. */
function expectedBy(problem, list) {
  return list.find(function (x) {
    if (problem.kind === 'http') {
      return problem.status === x.status && problem.method === x.method && problem.url.indexOf(x.urlPart) !== -1;
    }
    if (problem.kind === 'console') {
      const m = /status of (\d{3})/.exec(problem.text);
      return !!m && Number(m[1]) === x.status && (problem.url || '').indexOf(x.urlPart) !== -1;
    }
    return false;
  }) || null;
}

/* ---------- page helpers ---------- */

async function login(page, base, user) {
  await page.goto(base + '/login.html');
  await page.fill('#username', user.username);
  await page.fill('#email', user.email);
  await page.fill('#password', user.password);
  await page.click('#login-button');
  await page.waitForFunction(function () { return /\/index\.html$/.test(location.pathname); });
  await page.waitForFunction(function () {
    const w = document.getElementById('welcome');
    return !!w && /^Welcome,/.test(w.textContent);
  });
  await page.waitForFunction(function () { return !!document.querySelector('#hub-nav a'); });
}

async function token(page) {
  return page.evaluate(function () { return window.sessionStorage.getItem('hubToken'); });
}

const PAGE_READY = {
  'warehouse.html': function () {
    const m = document.getElementById('queue-meta');
    return !!m && m.textContent.indexOf('Last updated') === 0;
  },
  'ingredients.html': function () {
    const b = document.getElementById('ing-body');
    return !!b && b.textContent.indexOf('Loading') === -1 && b.querySelectorAll('tr').length > 0;
  },
  'accounts.html': function () {
    const b = document.getElementById('acc-body');
    const s = document.getElementById('acc-location');
    return !!b && b.textContent.indexOf('Loading') === -1 && !!s && s.options.length > 1;
  },
  'stock.html': function () {
    const m = document.getElementById('stock-meta');
    return !!m && m.textContent.indexOf('Showing') === 0;
  },
  'ordering.html': function () {
    const m = document.getElementById('stock-meta');
    return !!m && m.textContent.indexOf('Loading') === -1 && m.textContent.trim() !== '';
  },
  'recipes.html': function () {
    const b = document.getElementById('recipes-body');
    return !!b && b.textContent.indexOf('Loading') === -1;
  },
  'reports.html': function () {
    const t = document.getElementById('report-totals');
    return !!t && t.querySelectorAll('tr').length > 0;
  }
};

async function openPage(page, base, name) {
  await page.goto(base + '/' + name);
  await page.waitForFunction(PAGE_READY[name]);
  await page.waitForFunction(function () { return !!document.querySelector('#hub-nav a'); });
}

async function navLinks(page) {
  return page.$$eval('#hub-nav a', function (as) {
    return as.map(function (a) { return a.textContent.trim(); });
  });
}

async function tiles(page) {
  return page.$$eval('#tiles a strong', function (xs) { return xs.map(function (x) { return x.textContent.trim(); }); });
}

async function waitForPath(page, endsWith) {
  await page.waitForFunction(function (e) { return location.pathname.slice(-e.length) === e; }, endsWith);
}

/* Fills the hub ordering form and places the order; returns the order id. */
async function placeOrderUi(page, base, qtyBySku) {
  await page.goto(base + '/ordering.html');
  await page.locator('#ingredient-body tr').first().waitFor();
  for (const sku of Object.keys(qtyBySku)) {
    await page.locator('#ingredient-body tr', { hasText: sku }).locator('input').fill(String(qtyBySku[sku]));
  }
  await page.click('#order-submit');
  await page.waitForFunction(function () {
    const m = document.getElementById('order-message');
    return !!m && /placed/.test(m.textContent);
  });
  const m = /Order #(\d+) placed, total R ([0-9 ]+\.[0-9]{2})/.exec(await page.textContent('#order-message'));
  ok(m !== null, 'order message not understood: ' + oneLine(await page.textContent('#order-message'), 200));
  return { id: Number(m[1]), total: m[2] };
}

/* ---------- one viewport ---------- */

async function runViewport(browser, root, vp, F, record) {
  const vpName = vp.width + 'x' + vp.height;
  const shotDir = path.join(OUT_DIR, vpName);
  fs.mkdirSync(shotDir, { recursive: true });
  const secrets = [F.hq.password, F.hub.password, F.franchise.password];
  const contexts = [];
  let server = null;

  async function newContext(label, timeout) {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, timezoneId: 'Africa/Johannesburg', acceptDownloads: true });
    ctx.setDefaultTimeout(timeout || 15000);
    watch(ctx, vpName + ' ' + label);
    contexts.push(ctx);
    return ctx;
  }

  async function screenshot(page, file) {
    // Never photograph a password: clear password inputs, and refuse if a known secret is in the page.
    await page.evaluate(function () {
      Array.prototype.forEach.call(document.querySelectorAll('input[type=password]'), function (i) { i.value = ''; });
    });
    const html = await page.evaluate(function () {
      return document.documentElement.innerHTML + ' ' + Array.prototype.map.call(document.querySelectorAll('input,textarea'), function (i) { return i.value; }).join(' ');
    });
    if (secrets.some(function (sec) { return sec && html.indexOf(sec) !== -1; })) {
      return 'screenshot skipped: a password was on the page';
    }
    await page.screenshot({ path: file, fullPage: true });
    return null;
  }

  async function check(id, slug, fn) {
    const mark = problems.length;
    const t = { page: null, expected: [], notes: [] };
    t.expect = function (status, method, urlPart, why) {
      t.expected.push({ status: status, method: method, urlPart: urlPart, why: why, seen: 0 });
    };
    t.note = function (text) { t.notes.push(text); };
    let reason = null;
    try {
      await fn(t);
    } catch (e) {
      reason = oneLine(e && e.message ? e.message : e, 450);
    }
    await sleep(250);
    const fresh = problems.slice(mark).filter(function (p) {
      const x = expectedBy(p, t.expected);
      if (x) {
        if (p.kind === 'http') {
          x.seen++;
        }
        return false;
      }
      return true;
    });
    if (fresh.length > 0) {
      const extra = fresh.slice(0, 3).map(function (p) { return p.text; }).join(' | ');
      reason = reason ? reason + ' | also: ' + extra : extra;
    }
    t.expected.forEach(function (x) {
      if (x.seen > 0) {
        t.notes.push('expected in browser: ' + x.seen + 'x HTTP ' + x.status + ' ' + x.method + ' ' + x.urlPart + ' (' + x.why + ')');
      }
    });
    if (t.page) {
      try {
        const skipped = await screenshot(t.page, path.join(shotDir, id + '-' + slug + '.png'));
        if (skipped) {
          t.notes.push(skipped);
        }
      } catch (e) {
        t.notes.push('screenshot failed: ' + oneLine(e.message, 120));
      }
    } else {
      t.notes.push('no screenshot (check did not reach its page)');
    }
    record(vpName, id, reason, t.notes);
    console.log((reason ? 'FAIL ' : 'PASS ') + vpName + ' ' + id + ' ' + CHECKS.find(function (c) { return c[0] === id; })[1] +
      (reason ? ' ... ' + oneLine(reason, 700) : '') + (t.notes.length ? ' [' + oneLine(t.notes.join('; '), 400) + ']' : ''));
  }

  try {
    server = await startServer(root, []);
  } catch (e) {
    for (const c of CHECKS) {
      record(vpName, c[0], 'could not start the server: ' + oneLine(e.message, 300), []);
      console.log('FAIL ' + vpName + ' ' + c[0] + ' ... could not start the server: ' + oneLine(e.message, 300));
    }
    return;
  }
  const base = 'http://127.0.0.1:' + server.port;

  async function api(method, p, tok, body) {
    const headers = { 'Accept': 'application/json' };
    if (tok) {
      headers['X-Auth-Token'] = tok;
    }
    const init = { method: method, headers: headers };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    const r = await fetch(base + p, init);
    const text = await r.text();
    let json = null;
    try { json = JSON.parse(text); } catch (e) { /* not JSON */ }
    return { status: r.status, json: json, text: text, type: r.headers.get('content-type') || '' };
  }

  async function onHand(tok) {
    const r = await api('GET', '/api/inventory', tok);
    ok(r.status === 200, 'GET /api/inventory as hub -> HTTP ' + r.status);
    const out = {};
    r.json.items.forEach(function (it) { out[it.sku] = milli(it.on_hand); });
    return out;
  }

  const s = {};

  try {
    await check('E1', 'logins', async function (t) {
      const want = {
        hq: ['Home', 'Stock & ordering', 'Hub stock', 'Warehouse queue', 'Ingredients', 'Accounts'],
        hub: ['Home', 'Stock & ordering', 'Hub stock'],
        franchise: ['Home', 'Stock & ordering']
      };
      for (const role of ['hq', 'hub', 'franchise']) {
        const ctx = await newContext(role);
        const p = await ctx.newPage();
        await login(p, base, F[role]);
        const links = await navLinks(p);
        ok(JSON.stringify(links) === JSON.stringify(want[role]), role + ' nav = ' + links.join(' / ') + '; expected ' + want[role].join(' / '));
        const me = await api('GET', '/api/me', await token(p));
        ok(me.status === 200 && me.json.user.role === role, role + ' /api/me role = ' + (me.json && me.json.user ? me.json.user.role : me.status));
        s[role] = p;
        s[role + 'Token'] = await token(p);
      }
      const tl = await tiles(s.franchise);
      ok(JSON.stringify(tl) === JSON.stringify(['Stock status']), 'franchise home tiles = ' + tl.join(' / '));
      t.page = s.hub;
    });

    await check('E2', 'order-delivered', async function (t) {
      ok(s.hub && s.hq, 'E1 did not log in hq and hub');
      const before = await onHand(s.hubToken);
      const order = await placeOrderUi(s.hub, base, { SMP001: '10', SMP004: '2.5' });
      ok(order.total === '425.00', 'order total R ' + order.total + ', expected 425.00');
      s.orderId = order.id;
      const id = order.id;
      const hq = s.hq;
      await openPage(hq, base, 'warehouse.html');
      const card = function () { return hq.locator('.wh-order', { hasText: 'Order #' + id + ' -' }); };
      await card().waitFor();
      const step = async function (button, text, nextStatus) {
        await card().getByRole('button', { name: button }).click();
        await hq.waitForFunction(function (x) {
          const m = document.getElementById('queue-message');
          return !!m && m.textContent.indexOf(x) !== -1;
        }, 'Order #' + id + ' is now: ' + text + '.');
        if (nextStatus) {
          await card().filter({ has: hq.locator('.wh-status', { hasText: nextStatus }) }).waitFor();
        }
      };
      await step('Start picking', 'Being picked', 'Being picked');
      ok((await api('GET', '/api/orders/' + id, s.hqToken)).json.order.status === 'in_process', 'API status is not in_process after Start picking');
      const mid = await onHand(s.hubToken);
      ok(mid.SMP001 === before.SMP001 && mid.SMP004 === before.SMP004, 'stock changed before delivery');
      await step('Out for delivery', 'Out for delivery', 'Out for delivery');
      ok((await api('GET', '/api/orders/' + id, s.hqToken)).json.order.status === 'out_for_delivery', 'API status is not out_for_delivery');
      let dialog = null;
      hq.once('dialog', function (d) { dialog = d.type(); d.accept(); });
      await step('Mark delivered', 'Delivered', null);
      ok(dialog === 'confirm', 'no confirm dialog for Mark delivered');
      await card().waitFor({ state: 'detached' });
      ok((await api('GET', '/api/orders/' + id, s.hqToken)).json.order.status === 'delivered', 'API status is not delivered');

      const after = await onHand(s.hubToken);
      ok(after.SMP001 - before.SMP001 === 10000, 'SMP001 delta ' + (after.SMP001 - before.SMP001) / 1000 + ', expected 10.000');
      ok(after.SMP004 - before.SMP004 === 2500, 'SMP004 delta ' + (after.SMP004 - before.SMP004) / 1000 + ', expected 2.500');
      for (const sku of Object.keys(after)) {
        if (sku !== 'SMP001' && sku !== 'SMP004') {
          ok(after[sku] === before[sku], sku + ' changed on delivery');
        }
      }
      // Re-send and reload: no second credit.
      const again = await api('PATCH', '/api/orders/' + id + '/status', s.hqToken, { status: 'delivered' });
      ok(again.status === 409, 'second PATCH delivered -> HTTP ' + again.status + ', expected 409');
      t.note('API: second PATCH delivered -> 409 (expected)');
      await hq.reload();
      await hq.waitForFunction(PAGE_READY['warehouse.html']);
      ok(await hq.locator('.wh-order', { hasText: 'Order #' + id + ' -' }).count() === 0, 'delivered order still on the queue after a reload');
      await openPage(hq, base, 'ordering.html');
      await hq.locator('#orders-body button[data-view="' + id + '"]').click();
      await hq.waitForFunction(function (x) { return document.getElementById('detail-title').textContent.indexOf('Order #' + x + ' ') === 0; }, String(id));
      ok(await hq.locator('#next-status').count() === 0, 'a delivered order still offers a status control');
      await s.hub.goto(base + '/stock.html');
      await s.hub.waitForFunction(PAGE_READY['stock.html']);
      await s.hub.reload();
      await s.hub.waitForFunction(PAGE_READY['stock.html']);
      const shown = await s.hub.evaluate(function () {
        const out = {};
        Array.prototype.forEach.call(document.querySelectorAll('#stock-body tr'), function (tr) {
          if (tr.cells.length === 4) {
            out[tr.cells[0].textContent.trim()] = tr.cells[3].textContent.trim();
          }
        });
        return out;
      });
      ok(/^10\.000/.test(shown.SMP001 || '') && /^2\.500/.test(shown.SMP004 || ''), 'stock page shows SMP001 ' + shown.SMP001 + ', SMP004 ' + shown.SMP004);
      const final = await onHand(s.hubToken);
      ok(final.SMP001 === after.SMP001 && final.SMP004 === after.SMP004, 'stock moved again after the re-send / reload');
      const rows = deliveredMovements(server.db, id);
      ok(rows.length === 2, 'test DB holds ' + rows.length + ' order_delivered rows for the order, expected 2 (one per line)');
      ok(rows.some(function (r) { return r.sku === 'SMP001' && r.qty_milli === 10000; }) &&
        rows.some(function (r) { return r.sku === 'SMP004' && r.qty_milli === 2500; }), 'order_delivered rows: ' + JSON.stringify(rows));
      t.note('credit once: API on_hand delta + 2 order_delivered rows in the test DB (no movements API exists)');
      t.page = s.hub;
    });

    await check('E3', 'cancel', async function (t) {
      ok(s.hub && s.hq, 'E1 did not log in hq and hub');
      const before = await onHand(s.hubToken);
      const a = await placeOrderUi(s.hub, base, { SMP002: '1' });
      const b = await placeOrderUi(s.hub, base, { SMP003: '2' });
      const c = await placeOrderUi(s.hub, base, { SMP005: '3' });
      const hq = s.hq;
      await openPage(hq, base, 'ordering.html');
      const view = async function (id) {
        await hq.locator('#orders-body button[data-view="' + id + '"]').click();
        await hq.waitForFunction(function (x) { return document.getElementById('detail-title').textContent.indexOf('Order #' + x + ' ') === 0; }, String(id));
      };
      const setStatus = async function (status) {
        const want = 'Status updated to ' + status + '.';
        let dialog = null;
        if (status === 'cancelled') {
          hq.once('dialog', function (d) { dialog = d.type(); d.accept(); });
        }
        await hq.selectOption('#next-status', status);
        await hq.click('#status-submit');
        await hq.waitForFunction(function (x) {
          const m = document.getElementById('detail-message');
          return !!m && m.textContent === x;
        }, want);
        if (status === 'cancelled') {
          ok(dialog === 'confirm', 'no confirm dialog before cancelling');
        }
      };
      await view(a.id);
      await setStatus('cancelled');
      ok((await api('GET', '/api/orders/' + a.id, s.hqToken)).json.order.status === 'cancelled', 'order A is not cancelled');
      await view(b.id);
      await setStatus('in_process');
      ok((await api('GET', '/api/orders/' + b.id, s.hqToken)).json.order.status === 'in_process', 'order B is not in_process');
      await setStatus('cancelled');
      ok((await api('GET', '/api/orders/' + b.id, s.hqToken)).json.order.status === 'cancelled', 'order B is not cancelled');
      ok(await hq.locator('#next-status').count() === 0, 'a cancelled order still offers a status control');
      t.note('hq cancelled through the ordering.html status control (confirm accepted)');

      // A hub user cannot cancel: API refusal, and no control in the UI.
      const refused = await api('PATCH', '/api/orders/' + c.id + '/status', s.hubToken, { status: 'cancelled' });
      ok(refused.status === 403, 'hub PATCH cancelled -> HTTP ' + refused.status + ' ' + oneLine(refused.text, 120));
      ok((await api('GET', '/api/orders/' + c.id, s.hqToken)).json.order.status === 'pending', 'order C changed after the hub refusal');
      t.note('API: hub PATCH cancelled -> 403 (expected)');
      await s.hub.goto(base + '/ordering.html');
      await s.hub.locator('#orders-body button[data-view="' + c.id + '"]').click();
      await s.hub.waitForFunction(function (x) { return document.getElementById('detail-title').textContent.indexOf('Order #' + x + ' ') === 0; }, String(c.id));
      ok(await s.hub.locator('#next-status').count() === 0 && await s.hub.locator('#status-submit').count() === 0, 'hub sees a status control');
      ok(!/cancel/i.test(await s.hub.locator('#detail-body').innerText()), 'hub order detail mentions cancel');
      await s.hub.goto(base + '/warehouse.html');
      await waitForPath(s.hub, '/index.html');
      const after = await onHand(s.hubToken);
      ok(JSON.stringify(after) === JSON.stringify(before), 'cancelled orders changed the stock');
      s.pendingOrder = c.id;
      t.page = hq;
    });

    await check('E4', 'batch', async function (t) {
      ok(s.hub, 'E1 did not log in hub');
      const p = s.hub;
      const before = await onHand(s.hubToken);
      await openPage(p, base, 'recipes.html');
      t.note('recipes.html has no menu link (hidden by build spec 2026-11-16 s9); opened by URL as the hub user');
      await p.locator('#batch-card').waitFor({ state: 'visible' });
      const value = await p.evaluate(function () {
        const o = Array.prototype.find.call(document.querySelectorAll('#b-recipe option'), function (x) { return x.textContent.indexOf('SMP-F01') === 0; });
        return o ? o.value : null;
      });
      ok(value !== null, 'SMP-F01 is not in the batch recipe list');
      await p.selectOption('#b-recipe', value);
      await p.fill('#b-qty', '24');
      await p.click('#batch-form button[type=submit]');
      await p.waitForFunction(function () {
        const m = document.getElementById('batch-message');
        return !!m && /Batch #\d+ recorded/.test(m.textContent);
      });
      t.page = p;
      const msg = (await p.textContent('#batch-message')).trim();
      const m = /Batch #(\d+) recorded: 24 x (.+?), total (R ?)?([0-9 ]+\.\d+), cost per item (R ?)?([0-9 ]+\.\d+)\./.exec(msg);
      ok(m !== null, 'batch message not understood: ' + oneLine(msg, 200));
      s.batchId = Number(m[1]);
      const after = await onHand(s.hubToken);
      const want = { SMP001: -1440, SMP002: -720, SMP003: -480, SMP004: -360, SMP005: -240, SMP006: 0 };
      for (const sku of Object.keys(want)) {
        ok(after[sku] - before[sku] === want[sku], sku + ' delta ' + (after[sku] - before[sku]) / 1000 + ', expected ' + want[sku] / 1000);
      }
      ok(m[4] === '134.16', 'total ' + m[4] + ', expected 134.16');
      ok(/^\d+\.\d{2}$/.test(m[6]) && m[6] === '5.59', 'cost per item "' + m[6] + '", expected exactly 5.59 (2 decimals)');
      const wantInv = (after.SMP001 / 1000).toFixed(3);
      await p.waitForFunction(function (x) {
        const tr = Array.prototype.find.call(document.querySelectorAll('#inv-body tr'), function (r) { return r.cells.length === 4 && r.cells[0].textContent.trim() === 'SMP001'; });
        return !!tr && tr.cells[3].textContent.trim() === x;
      }, wantInv).catch(function () {
        throw new Error('the inventory table on recipes.html does not show SMP001 ' + wantInv + ' after the batch');
      });
      t.note('deltas -1.440/-0.720/-0.480/-0.360/-0.240, SMP006 0; total 134.16; cost per item 5.59');
      ok(!!m[5], 'cost per item is shown as "' + m[6] + '" with no R (rand) marker: "' + oneLine(msg, 160) + '"');
    });

    await check('E5', 'report-csv', async function (t) {
      ok(s.hq && s.batchId, 'E4 did not record a batch');
      const p = s.hq;
      await openPage(p, base, 'reports.html');
      t.note('reports.html has no menu link (hidden by build spec 2026-11-16 s9); opened by URL as hq');
      t.page = p;
      const row = p.locator('#report-batches tr', { hasText: '#' + s.batchId });
      await row.waitFor();
      const rowText = await row.innerText();
      for (const need of ['SMP-F01', '24', '5.59', '134.16']) {
        ok(rowText.indexOf(need) !== -1, 'report batch row lacks "' + need + '": ' + oneLine(rowText, 200));
      }
      const totals = await p.locator('#report-totals').innerText();
      ok(/134\.16/.test(totals), 'report totals lack 134.16: ' + oneLine(totals, 150));
      const [download, response] = await Promise.all([
        p.waitForEvent('download'),
        p.waitForResponse(function (r) { return r.url().indexOf('/api/reports/finished-goods') !== -1 && r.url().indexOf('format=csv') !== -1; }),
        p.click('#report-csv')
      ]);
      ok(response.status() === 200, 'CSV request -> HTTP ' + response.status());
      const type = response.headers()['content-type'] || '';
      ok(/^text\/csv/.test(type), 'CSV content-type is "' + type + '"');
      ok(/\.csv$/.test(download.suggestedFilename()), 'download file name "' + download.suggestedFilename() + '"');
      const file = path.join(os.tmpdir(), 'hub-e2e-night-' + process.pid + '-' + Date.now() + '.csv');
      await download.saveAs(file);
      const lines = fs.readFileSync(file, 'utf8').replace(/^﻿/, '').split(/\r?\n/).filter(function (l) { return l !== ''; });
      fs.unlinkSync(file);
      ok(lines[0] === CSV_HEADER, 'CSV first line "' + oneLine(lines[0] || '', 120) + '"');
      ok(lines.some(function (l) { return /^SMP-F01,/.test(l) && /,24,134\.16,5\.59,1$/.test(l); }), 'CSV has no SMP-F01 row 24,134.16,5.59,1: ' + oneLine(lines.join(' | '), 200));
      ok(/^TOTAL,,,24,134\.16,,1$/.test(lines[lines.length - 1]), 'CSV last line "' + oneLine(lines[lines.length - 1], 120) + '"');
      t.note('CSV ' + type + ', header row as SPEC s9.1, item and TOTAL rows match');
    });

    await check('E6', 'franchise', async function (t) {
      ok(s.franchise, 'E1 did not log in franchise');
      const p = s.franchise;
      const bodies = [];
      const onResp = async function (r) {
        if ((r.headers()['content-type'] || '').indexOf('application/json') !== -1) {
          try { bodies.push({ url: r.url(), text: await r.text() }); } catch (e) { /* body gone */ }
        }
      };
      p.on('response', onResp);
      await openPage(p, base, 'ordering.html');
      await p.waitForFunction(function () { return document.querySelectorAll('#stock-body tr').length > 0; });
      t.page = p;
      ok(await p.locator('#order-card').isHidden(), 'franchise sees the order form');
      ok(await p.locator('#orders-card').isHidden(), 'franchise sees order history');
      ok(await p.locator('#detail-card').isHidden(), 'franchise sees an order detail');
      const visible = await p.evaluate(function () { return document.body.innerText; });
      ok(!/cost|price|recipe|batch/i.test(visible), 'franchise page text mentions cost/price/recipe/batch');
      ok(!/R\s?\d|\d+\.\d{2}\b/.test(visible), 'franchise page text shows a money amount');
      const rows = await p.$$eval('#stock-body tr', function (trs) { return trs.map(function (tr) { return tr.cells.length; }); });
      ok(rows.length === 6 && rows.every(function (n) { return n === 4; }), 'stock table rows ' + rows.length);
      const hidden = await p.evaluate(function () { return document.documentElement.innerHTML; });
      ok(hidden.indexOf('12.50') === -1 && hidden.indexOf('120.00') === -1, 'a cost value is in the page markup');
      for (const name of ['recipes.html', 'reports.html', 'stock.html', 'warehouse.html', 'ingredients.html', 'accounts.html']) {
        await p.goto(base + '/' + name);
        await waitForPath(p, '/index.html');
      }
      await p.waitForFunction(function () { return !!document.querySelector('#hub-nav a'); });
      p.off('response', onResp);
      await sleep(200);
      for (const b of bodies) {
        ok(!/"[^"]*(cost|price|recipe)[^"]*"\s*:/i.test(b.text), 'a JSON response to franchise has a cost/price/recipe key: ' + b.url);
      }
      const denied = [];
      for (const ep of ['/api/ingredients', '/api/recipes', '/api/inventory', '/api/orders', '/api/reports/finished-goods', '/api/admin/recipes', '/api/admin/batches', '/api/batches/' + s.batchId]) {
        const r = await api('GET', ep, s.franchiseToken);
        ok(r.status === 403, 'franchise GET ' + ep + ' -> HTTP ' + r.status + ', expected 403');
        denied.push(ep);
      }
      t.note('every franchise visit to recipes/reports/stock/warehouse/ingredients/accounts lands on index.html; API: ' + denied.length + ' routes -> 403 (expected)');
      await openPage(p, base, 'ordering.html');
      await p.waitForFunction(function () { return document.querySelectorAll('#stock-body tr').length > 0; });
    });

    await check('E8', 'pages', async function (t) {
      ok(s.hq, 'E1 did not log in hq');
      const p = s.hq;
      await openPage(p, base, 'warehouse.html');
      await p.locator('.wh-order', { hasText: 'Order #' + s.pendingOrder + ' -' }).waitFor();
      ok(await p.locator('#picklist-body tr').count() >= 1, 'warehouse pick list is empty with an open order');
      ok((await p.locator('#queue-counts').innerText()).indexOf('Waiting') !== -1, 'warehouse counts not shown');
      await openPage(p, base, 'ingredients.html');
      const ing = await p.locator('#ing-body tr', { hasText: 'SMP001' }).innerText();
      ok(/12\.50/.test(ing), 'ingredients row SMP001 lacks its cost 12.50: ' + oneLine(ing, 120));
      ok(await p.locator('#ing-body tr').count() >= 6, 'ingredients list has fewer than 6 rows');
      await openPage(p, base, 'accounts.html');
      for (const who of [F.hq.username, F.hub.username, F.franchise.username]) {
        ok(await p.locator('#acc-body tr', { hasText: who }).count() === 1, 'accounts list lacks ' + who);
      }
      await openPage(p, base, 'stock.html');
      const hubId = await p.evaluate(function () {
        const o = Array.prototype.find.call(document.querySelectorAll('#stock-location option'), function (x) { return x.textContent.trim() === 'The Hub'; });
        return o ? o.value : null;
      });
      ok(hubId !== null, 'stock.html location picker has no "The Hub" option');
      await p.selectOption('#stock-location', hubId);
      await p.waitForFunction(function (x) { return document.getElementById('stock-meta').textContent === 'Showing location ' + x + '.'; }, hubId);
      ok(await p.locator('#stock-body tr').count() === 6, 'stock table does not have 6 rows');
      const smp1 = await p.locator('#stock-body tr', { hasText: 'SMP001' }).innerText();
      ok(/8\.560/.test(smp1), 'stock SMP001 row (10.000 delivered - 1.440 batch) shows ' + oneLine(smp1, 120));
      for (const name of ['warehouse.html', 'ingredients.html', 'accounts.html', 'stock.html']) {
        await openPage(p, base, name);
        await p.waitForLoadState('networkidle');
        const w = await p.evaluate(function () { return document.documentElement.scrollWidth; });
        ok(w <= vp.width, name + ' scrolls sideways: scrollWidth ' + w + ' > ' + vp.width);
      }
      t.page = p;
      t.note('screenshot: stock.html (the last of the four pages)');
    });

    await check('E9', 'login-lock', async function (t) {
      ok(s.hq, 'E1 did not log in hq');
      const hq = s.hq;
      await openPage(hq, base, 'accounts.html');
      const user = { username: 'TEST-NB-lock-' + vp.width, email: 'test-nb-lock-' + vp.width + '@example.invalid', password: '' };
      await hq.fill('#acc-username', user.username);
      await hq.fill('#acc-email', user.email);
      await hq.click('#acc-suggest');
      user.password = await hq.inputValue('#acc-password');
      ok(user.password.length >= 12, 'suggested password shorter than 12');
      secrets.push(user.password);
      await hq.selectOption('#acc-location', { label: 'The Hub (hub)' });
      await hq.click('#acc-save');
      await hq.waitForFunction(function () {
        const m = document.getElementById('acc-message');
        return !!m && /Account created/.test(m.textContent);
      });
      await hq.locator('#acc-body tr', { hasText: user.username }).waitFor();
      await hq.goto(base + '/index.html'); // move off the page that shows the new password once

      // The password works (a fresh context, then logged out by closing it).
      const okCtx = await newContext('lock-ok');
      const okPage = await okCtx.newPage();
      await login(okPage, base, user);
      await okCtx.close();

      const ctx = await newContext('lock');
      const p = await ctx.newPage();
      await p.goto(base + '/login.html');
      t.page = p;
      const attempt = async function (password) {
        await p.fill('#username', user.username);
        await p.fill('#email', user.email);
        await p.fill('#password', password);
        const [resp] = await Promise.all([
          p.waitForResponse(function (r) { return r.url().slice(-'/api/login'.length) === '/api/login' && r.request().method() === 'POST'; }),
          p.click('#login-button')
        ]);
        await p.waitForFunction(function () {
          const m = document.getElementById('login-message');
          const b = document.getElementById('login-button');
          return !!m && !m.classList.contains('hidden') && m.textContent !== '' && !b.disabled;
        });
        return { status: resp.status(), message: (await p.textContent('#login-message')).trim() };
      };
      t.expect(401, 'POST', '/api/login', 'wrong password x5, then the right password while locked');
      const wrong = [];
      for (let i = 1; i <= 5; i++) {
        wrong.push(await attempt('TEST-NB-wrong-' + i + '-x'));
      }
      wrong.forEach(function (w, i) {
        ok(w.status === 401, 'wrong password ' + (i + 1) + ' -> HTTP ' + w.status);
        ok(w.message === wrong[0].message, 'wrong-password messages differ: "' + w.message + '" vs "' + wrong[0].message + '"');
      });
      const locked = await attempt(user.password);
      await p.fill('#password', '');
      ok(locked.status === 401, 'right password after 5 failures -> HTTP ' + locked.status + ', expected 401 (locked)');
      ok(/\/login\.html$/.test(new URL(p.url()).pathname), 'browser left the login page');
      ok(locked.message === wrong[0].message, 'locked message "' + locked.message + '" differs from wrong-password message "' + wrong[0].message + '"');
      t.note('same message both ways: "' + locked.message + '"; user ' + user.username);
    });

    // E7 on its own server: FinCon adapter pointed at a closed localhost port.
    for (const ctx of contexts.splice(0)) {
      try { await ctx.close(); } catch (e) { /* gone */ }
    }
    await stopServer(server);
    server = null;
    let down = null;
    try {
      down = await startServer(root, ['--fincon-down']);
    } catch (e) {
      record(vpName, 'E7', 'could not start the --fincon-down server: ' + oneLine(e.message, 300), []);
      console.log('FAIL ' + vpName + ' E7 ... could not start the --fincon-down server: ' + oneLine(e.message, 300));
    }
    if (down) {
      server = down;
      const dbase = 'http://127.0.0.1:' + down.port;
      await check('E7', 'fincon-down', async function (t) {
        const hubCtx = await newContext('fincon-down hub', 30000);
        const hp = await hubCtx.newPage();
        await login(hp, dbase, F.hub);
        await openPage(hp, dbase, 'ordering.html');
        const meta = (await hp.textContent('#stock-meta')).trim();
        ok(/^FinCon unavailable \((FINCON_UNREACHABLE|FINCON_MAINTENANCE)\)/.test(meta), 'stock meta "' + meta + '"');
        const badges = await hp.$$eval('#stock-body .badge', function (xs) { return xs.map(function (x) { return x.textContent.trim(); }); });
        ok(badges.length === 6 && badges.every(function (b) { return b === 'in stock'; }), 'stock badges: ' + badges.join(', '));
        const order = await placeOrderUi(hp, dbase, { SMP001: '3', SMP004: '1' });
        ok(order.total === '157.50', 'order total R ' + order.total + ', expected 157.50 (3 x 12.50 + 1 x 120.00)');
        await hp.waitForFunction(function () { return document.querySelectorAll('#stock-body .badge').length === 6; });
        t.page = hp;
        const hqCtx = await newContext('fincon-down hq', 30000);
        const q = await hqCtx.newPage();
        await login(q, dbase, F.hq);
        await openPage(q, dbase, 'warehouse.html');
        const card = q.locator('.wh-order', { hasText: 'Order #' + order.id + ' -' });
        await card.waitFor();
        await card.getByRole('button', { name: 'Start picking' }).click();
        await q.waitForFunction(function (x) {
          const m = document.getElementById('queue-message');
          return !!m && m.textContent.indexOf(x) !== -1;
        }, 'Order #' + order.id + ' is now: Being picked.');
        t.note('stock meta "' + oneLine(meta, 80) + '"; order #' + order.id + ' placed and moved to in_process');
      });
    }
  } catch (e) {
    console.log('FAIL ' + vpName + ' RUN unexpected error ... ' + oneLine(e && e.message ? e.message : e, 500));
    record(vpName, 'RUN', 'unexpected error: ' + oneLine(e && e.message ? e.message : e, 300), []);
  } finally {
    for (const ctx of contexts.splice(0)) {
      try { await ctx.close(); } catch (e) { /* gone */ }
    }
    await stopServer(server);
  }
}

/* ---------- RESULTS.md ---------- */

function writeResults(results, binary) {
  const names = VIEWPORTS.map(function (v) { return v.width + 'x' + v.height; });
  let passed = 0;
  let failed = 0;
  const esc = function (x) { return String(x).replace(/\|/g, '\\|').replace(/\r?\n/g, ' '); };
  const lines = [
    '# T-09 end-to-end browser results (2026-11-19)',
    '',
    'Produced by `node tests/browser/e2e_night.cjs` on localhost (fresh SQLite test database per server, fixtures from `bin/seed.php`).',
    'Browser binary: ' + esc(binary) + '. Screenshots: `docs/night/2026-11-19/e2e/<WxH>/<id>-<slug>.png`.',
    '',
    '| Check | ' + names.join(' | ') + ' | note |',
    '|---|' + names.map(function () { return '---'; }).join('|') + '|---|'
  ];
  const ids = CHECKS.map(function (c) { return c[0]; });
  if (names.some(function (n) { return results[n] && results[n].RUN; })) {
    ids.push('RUN');
  }
  for (const id of ids) {
    const title = id === 'RUN' ? 'run error' : CHECKS.find(function (c) { return c[0] === id; })[1];
    const cells = [];
    const notes = [];
    for (const n of names) {
      const r = results[n] && results[n][id];
      if (!r) {
        if (id === 'RUN') {
          cells.push('-');
          continue;
        }
        cells.push('FAIL');
        failed++;
        notes.push(n + ': not run');
        continue;
      }
      cells.push(r.reason ? 'FAIL' : 'PASS');
      if (r.reason) {
        failed++;
        notes.push(n + ' FAIL: ' + r.reason);
      } else {
        passed++;
      }
      if (r.notes.length) {
        notes.push(n + ': ' + r.notes.join('; '));
      }
    }
    lines.push('| ' + id + ' ' + esc(title) + ' | ' + cells.join(' | ') + ' | ' + esc(oneLine(notes.join(' / '), 1500)) + ' |');
  }
  const last = 'E2E: ' + passed + ' passed, ' + failed + ' failed';
  lines.push('', last, '');
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(path.join(OUT_DIR, 'RESULTS.md'), lines.join('\n'), 'utf8');
  return { passed: passed, failed: failed, line: last };
}

/* ---------- main ---------- */

async function main() {
  const root = parseRoot();
  if (root === null) {
    console.log('Usage: node tests/browser/e2e_night.cjs --root repo|bundle');
    console.log('E2E: 0 passed, 1 failed (bad --root)');
    return 1;
  }
  const pw = loadPlaywright();
  if (!pw) {
    console.log('E2E: 0 passed, 1 failed (playwright not found)');
    return 1;
  }
  let F;
  try {
    F = readFixtures();
  } catch (e) {
    console.log('E2E: 0 passed, 1 failed (' + oneLine(e.message, 200) + ')');
    return 1;
  }
  let launched;
  try {
    launched = await launchBrowser(pw);
  } catch (e) {
    console.log('E2E: 0 passed, 1 failed (browser: ' + oneLine(e.message, 300) + ')');
    return 1;
  }
  console.log('BROWSER-BINARY: ' + launched.binary);
  const results = {};
  const record = function (vp, id, reason, notes) {
    results[vp] = results[vp] || {};
    results[vp][id] = { reason: reason, notes: notes };
  };
  try {
    for (const vp of VIEWPORTS) {
      await runViewport(launched.browser, root, vp, F, record);
    }
  } finally {
    try { await launched.browser.close(); } catch (e) { /* gone */ }
  }
  const sum = writeResults(results, launched.binary);
  console.log(sum.line);
  return sum.failed === 0 ? 0 : 1;
}

main().then(function (code) {
  process.exit(code);
}, function (e) {
  console.log('E2E: 0 passed, 1 failed (unexpected: ' + oneLine(e && e.message ? e.message : e, 400) + ')');
  process.exit(1);
});
