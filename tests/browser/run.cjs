'use strict';
/*
 * Browser end-to-end test (build spec 2026-11-18, task E2.2): checks W1-W7 in headless Chromium.
 *
 *   node tests/browser/run.cjs --root repo      serve the working copy
 *   node tests/browser/run.cjs --root bundle    serve deploy/upload/hub (the upload bundle)
 *
 * Starts tests/browser/serve.php (fresh SQLite + fixtures, test database only), runs the checks, prints one
 * line per check and a final "BROWSER: <n> passed, <n> failed". Exit 0 only when nothing failed.
 * A check also fails on any console error, page error or HTTP 4xx/5xx seen while it ran.
 * Plain Node, no packages other than Playwright (found, not installed).
 */
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');

const ROOT_DIR = path.join(__dirname, '..', '..');
const SERVE_PHP = path.join(__dirname, 'serve.php');

function finish(passed, failed, note) {
  console.log('BROWSER: ' + passed + ' passed, ' + failed + ' failed' + (note ? ' (' + note + ')' : ''));
}

/* The hq fixture login, read from bin/seed.php (the one place the test logins are defined). */
function readHqFixture() {
  const src = fs.readFileSync(path.join(ROOT_DIR, 'bin', 'seed.php'), 'utf8');
  const m = /\[\s*'[^']*'\s*,\s*'hq'\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*\]/.exec(src);
  if (!m) {
    throw new Error('hq fixture row not found in bin/seed.php');
  }
  return { username: m[1], email: m[2], password: m[3] };
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

/* ---------- the PHP server ---------- */

function startServerWith(php, root) {
  return new Promise(function (resolve, reject) {
    const child = spawn(php, [SERVE_PHP, '--root', root], { cwd: ROOT_DIR, stdio: ['pipe', 'pipe', 'pipe'], windowsHide: true });
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
      child.kill();
      done(reject, Object.assign(new Error('no PORT line within 90 s; stderr: ' + oneLine(err)), { started: true }));
    }, 90000);
    child.on('error', function (e) { done(reject, e); });
    child.stderr.on('data', function (d) { err += d.toString(); });
    child.stdout.on('data', function (d) {
      out += d.toString();
      const m = /^PORT=(\d+)\s*$/m.exec(out);
      if (m) {
        done(resolve, { child: child, port: Number(m[1]) });
      }
    });
    child.on('exit', function (code) {
      done(reject, Object.assign(new Error('serve.php exited (' + code + ') before printing PORT: ' + oneLine(err + ' ' + out)), { started: true }));
    });
  });
}

async function startServer(root) {
  const candidates = [];
  if (process.env.PHP_BIN) {
    candidates.push(process.env.PHP_BIN);
  }
  candidates.push('php');
  if (process.env.LOCALAPPDATA) {
    candidates.push(path.join(process.env.LOCALAPPDATA, 'php', 'php.exe'));
  }
  let last = null;
  for (const php of candidates) {
    if (php.indexOf(path.sep) !== -1 && !fs.existsSync(php)) {
      continue;
    }
    try {
      return await startServerWith(php, root);
    } catch (e) {
      last = e;
      if (e.started) {
        break; // php ran but the app did not come up: another php would not help
      }
    }
  }
  throw last || new Error('php not found');
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
        problems.push(label + ' console error: ' + oneLine(m.text(), 200));
      }
    });
    page.on('pageerror', function (e) {
      problems.push(label + ' page error: ' + oneLine(e.message, 200));
    });
    page.on('response', function (r) {
      if (r.status() >= 400) {
        problems.push(label + ' HTTP ' + r.status() + ' ' + r.request().method() + ' ' + r.url());
      }
    });
  });
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
}

const PAGE_READY = {
  'warehouse.html': function () {
    const m = document.getElementById('queue-meta');
    return !!m && m.textContent.indexOf('Last updated') === 0;
  },
  'ingredients.html': function () {
    const b = document.getElementById('ing-body');
    return !!b && b.textContent.indexOf('Loading') === -1;
  },
  'accounts.html': function () {
    const b = document.getElementById('acc-body');
    const s = document.getElementById('acc-location');
    return !!b && b.textContent.indexOf('Loading') === -1 && !!s && s.options.length > 1;
  },
  'stock.html': function () {
    const m = document.getElementById('stock-meta');
    return !!m && m.textContent.indexOf('Showing') === 0;
  }
};

async function openPage(page, base, name) {
  await page.goto(base + '/' + name);
  await page.waitForFunction(PAGE_READY[name]);
  await page.waitForFunction(function () { return !!document.querySelector('#hub-nav a'); });
}

async function readOnHand(page, sku) {
  await page.waitForFunction(function (s) {
    return Array.prototype.some.call(document.querySelectorAll('#stock-body tr'), function (tr) {
      return tr.cells.length === 4 && tr.cells[0].textContent.trim() === s;
    });
  }, sku);
  return page.evaluate(function (s) {
    const tr = Array.prototype.find.call(document.querySelectorAll('#stock-body tr'), function (row) {
      return row.cells.length === 4 && row.cells[0].textContent.trim() === s;
    });
    return parseFloat(tr.cells[3].textContent);
  }, sku);
}

async function navLinks(page) {
  return page.$$eval('#hub-nav a', function (as) {
    return as.map(function (a) { return a.textContent.trim(); });
  });
}

async function waitForPath(page, endsWith) {
  await page.waitForFunction(function (e) { return location.pathname.slice(-e.length) === e; }, endsWith);
}

/* ---------- the checks ---------- */

async function main() {
  const root = parseRoot();
  if (root === null) {
    console.log('Usage: node tests/browser/run.cjs --root repo|bundle');
    finish(0, 1, 'bad --root');
    return 1;
  }
  const pw = loadPlaywright();
  if (!pw) {
    finish(0, 1, 'playwright not found');
    return 1;
  }
  let HQ;
  try {
    HQ = readHqFixture();
  } catch (e) {
    finish(0, 1, oneLine(e.message, 200));
    return 1;
  }

  let server = null;
  let browser = null;
  let passed = 0;
  let failed = 0;
  try {
    server = await startServer(root);
    const launched = await launchBrowser(pw);
    browser = launched.browser;
    console.log('BROWSER-BINARY: ' + launched.binary);
  } catch (e) {
    finish(0, 1, 'could not start: ' + oneLine(e.message, 400));
    await shutdown(server, browser);
    return 1;
  }
  const base = 'http://127.0.0.1:' + server.port;

  // State carried between checks.
  const s = { hq: null, hubCtx: null, hubPage: null, user: null, orderId: null, before: {}, pendingBefore: null };

  async function check(id, title, fn) {
    const mark = problems.length;
    let reason = null;
    try {
      await fn();
    } catch (e) {
      reason = oneLine(e && e.message ? e.message : e, 450);
    }
    await sleep(200);
    const fresh = problems.slice(mark);
    if (fresh.length > 0) {
      const extra = fresh.slice(0, 3).join(' | ');
      reason = reason ? reason + ' | also: ' + extra : extra;
    }
    if (reason) {
      failed++;
      console.log('FAIL ' + id + ' ' + title + ' ... ' + oneLine(reason, 700));
    } else {
      passed++;
      console.log('PASS ' + id + ' ' + title);
    }
  }

  try {
    const hqCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    hqCtx.setDefaultTimeout(15000);
    watch(hqCtx, 'hq');
    s.hq = await hqCtx.newPage();

    await check('W1', 'login page loads, hq logs in, index offers Warehouse, Ingredients, Accounts, Hub stock and not Recipes', async function () {
      const p = s.hq;
      await p.goto(base + '/login.html');
      ok(await p.locator('#login-form').isVisible(), 'login form not visible');
      ok(/log in/i.test(await p.title()), 'login page title is "' + (await p.title()) + '"');
      await login(p, base, HQ);
      const links = await navLinks(p);
      for (const need of ['Warehouse queue', 'Ingredients', 'Accounts', 'Hub stock']) {
        ok(links.indexOf(need) !== -1, 'nav lacks "' + need + '"; nav = ' + links.join(' / '));
      }
      const tiles = await p.$$eval('#tiles a strong', function (xs) { return xs.map(function (x) { return x.textContent.trim(); }); });
      for (const need of ['Warehouse queue', 'Ingredients', 'Accounts', 'Hub stock']) {
        ok(tiles.indexOf(need) !== -1, 'home tiles lack "' + need + '"; tiles = ' + tiles.join(' / '));
      }
      const text = await p.evaluate(function () { return document.body.innerText; });
      ok(!/recipes?/i.test(text), 'the index page mentions Recipes');
      const hrefs = await p.$$eval('a', function (as) { return as.map(function (a) { return a.getAttribute('href') || ''; }); });
      ok(!hrefs.some(function (h) { return /recipes/i.test(h); }), 'the index page links to recipes');
    });

    await check('W2', 'warehouse, ingredients, accounts and stock pages load with zero console errors', async function () {
      for (const name of ['warehouse.html', 'ingredients.html', 'accounts.html', 'stock.html']) {
        try {
          await openPage(s.hq, base, name);
          ok(await s.hq.locator('h2').first().isVisible(), 'no heading visible');
          await s.hq.waitForLoadState('networkidle');
        } catch (e) {
          throw new Error(name + ': ' + oneLine(e.message, 250));
        }
      }
    });

    await check('W3', 'Accounts: create a hub user through the form, password shown once, user in the list', async function () {
      const p = s.hq;
      await openPage(p, base, 'accounts.html');
      const user = { username: 'hub.e2e', email: 'hub.e2e@example.invalid', password: '' };
      await p.fill('#acc-username', user.username);
      await p.fill('#acc-email', user.email);
      await p.click('#acc-suggest');
      user.password = await p.inputValue('#acc-password');
      ok(user.password.length >= 12, 'suggested password is shorter than 12 characters');
      await p.selectOption('#acc-location', { label: 'The Hub (hub)' });
      await p.click('#acc-save');
      await p.waitForFunction(function () {
        const m = document.getElementById('acc-message');
        return !!m && /Account created/.test(m.textContent);
      });
      const msg = await p.textContent('#acc-message');
      ok(msg.split(user.password).length - 1 === 1, 'the password is not shown exactly once in the success message');
      ok(/not shown again/i.test(msg), 'message does not say the password is not shown again');
      ok((await p.inputValue('#acc-password')) === '', 'password field was not cleared');
      const row = p.locator('#acc-body tr', { hasText: user.username });
      await row.waitFor();
      const rowText = await row.innerText();
      ok(rowText.indexOf(user.email) !== -1, 'list row lacks the email: ' + oneLine(rowText, 150));
      ok(/The Hub/.test(rowText) && /Active/.test(rowText), 'list row lacks location or Active status: ' + oneLine(rowText, 150));
      s.user = user;
      // Reload: the password must be gone from the page.
      await p.reload();
      await p.waitForFunction(PAGE_READY['accounts.html']);
      const after = await p.evaluate(function () { return document.body.innerText + ' ' + document.documentElement.innerHTML; });
      ok(after.indexOf(user.password) === -1, 'the password is still on the page after a reload');
      ok(await p.locator('#acc-body tr', { hasText: user.username }).count() === 1, 'the user is not listed after a reload');
    });

    await check('W4', 'second browser context: hub user logs in, ordering and Hub stock show, Accounts and Ingredients do not', async function () {
      ok(s.user && s.user.password, 'W3 did not create the hub user');
      s.hubCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      s.hubCtx.setDefaultTimeout(15000);
      watch(s.hubCtx, 'hub');
      s.hubPage = await s.hubCtx.newPage();
      const p = s.hubPage;
      await login(p, base, s.user);
      const links = await navLinks(p);
      for (const need of ['Stock & ordering', 'Hub stock']) {
        ok(links.indexOf(need) !== -1, 'hub nav lacks "' + need + '"; nav = ' + links.join(' / '));
      }
      for (const bad of ['Accounts', 'Ingredients', 'Warehouse queue']) {
        ok(links.indexOf(bad) === -1, 'hub nav shows "' + bad + '"');
      }
      const tiles = await p.$$eval('#tiles a strong', function (xs) { return xs.map(function (x) { return x.textContent.trim(); }); });
      ok(tiles.indexOf('Hub stock') !== -1 && tiles.indexOf('Stock & ordering') !== -1, 'hub home tiles = ' + tiles.join(' / '));
      ok(tiles.indexOf('Accounts') === -1 && tiles.indexOf('Ingredients') === -1, 'hub home shows an HQ tile: ' + tiles.join(' / '));

      await p.goto(base + '/ordering.html');
      await p.locator('#order-card').waitFor({ state: 'visible' });
      await p.goto(base + '/stock.html');
      await p.waitForFunction(PAGE_READY['stock.html']);
      s.before.SMP001 = await readOnHand(p, 'SMP001');
      s.before.SMP004 = await readOnHand(p, 'SMP004');

      for (const name of ['accounts.html', 'ingredients.html']) {
        await p.goto(base + '/' + name);
        await waitForPath(p, '/index.html');
      }
    });

    await check('W5', 'hub places an order on the ordering page; hq warehouse page shows it after a reload, with the title count', async function () {
      ok(s.hubPage, 'W4 did not open the hub context');
      const hq = s.hq;
      await openPage(hq, base, 'warehouse.html');
      s.pendingBefore = await hq.evaluate(function () {
        const m = /^\((\d+)\) Warehouse queue/.exec(document.title);
        return m ? Number(m[1]) : -1;
      });
      ok(s.pendingBefore >= 0, 'warehouse title has no count before the order: "' + (await hq.title()) + '"');

      const p = s.hubPage;
      await p.goto(base + '/ordering.html');
      await p.locator('#ingredient-body tr').first().waitFor();
      await p.locator('#ingredient-body tr', { hasText: 'SMP001' }).locator('input').fill('10');
      await p.locator('#ingredient-body tr', { hasText: 'SMP004' }).locator('input').fill('2.5');
      const shown = (await p.textContent('#order-total')).trim();
      ok(shown === '425.00', 'order form total is "' + shown + '", expected 425.00 (fixture costs)');
      await p.click('#order-submit');
      await p.waitForFunction(function () {
        const m = document.getElementById('order-message');
        return !!m && /placed/.test(m.textContent);
      });
      const msg = await p.textContent('#order-message');
      const m = /Order #(\d+) placed, total R ([0-9 ]+\.[0-9]{2})/.exec(msg);
      ok(m !== null, 'order message not understood: ' + oneLine(msg, 200));
      s.orderId = m[1];
      ok(m[2] === '425.00', 'placed order total is R ' + m[2] + ', expected 425.00');

      await hq.reload();
      await hq.waitForFunction(PAGE_READY['warehouse.html']);
      const card = hq.locator('.wh-order', { hasText: 'Order #' + s.orderId + ' -' });
      await card.waitFor();
      const cardText = await card.innerText();
      for (const need of ['SMP001', '10.000', 'SMP004', '2.500', 'Waiting']) {
        ok(cardText.indexOf(need) !== -1, 'order card lacks "' + need + '": ' + oneLine(cardText, 200));
      }
      const want = '(' + (s.pendingBefore + 1) + ') Warehouse queue';
      await hq.waitForFunction(function (t) { return document.title === t; }, want);
    });

    await check('W6', 'hq marks the order through to delivered (confirm accepted); hub stock page shows the new quantity', async function () {
      ok(s.orderId, 'W5 did not place an order');
      const hq = s.hq;
      const id = s.orderId;
      const orderCard = function () {
        return hq.locator('.wh-order', { hasText: 'Order #' + id + ' -' });
      };
      const step = async function (button, messageText, nextStatus) {
        await orderCard().getByRole('button', { name: button }).click();
        await hq.waitForFunction(function (t) {
          const m = document.getElementById('queue-message');
          return !!m && m.textContent.indexOf(t) !== -1;
        }, 'Order #' + id + ' is now: ' + messageText + '.');
        if (nextStatus) {
          await orderCard().filter({ has: hq.locator('.wh-status', { hasText: nextStatus }) }).waitFor();
        }
      };
      await step('Start picking', 'Being picked', 'Being picked');
      await step('Out for delivery', 'Out for delivery', 'Out for delivery');

      let dialog = null;
      hq.once('dialog', function (d) {
        dialog = { type: d.type(), message: d.message() };
        d.accept();
      });
      await step('Mark delivered', 'Delivered', null);
      ok(dialog !== null, 'no confirm dialog appeared for Mark delivered');
      ok(dialog.type === 'confirm' && /delivered/i.test(dialog.message), 'unexpected dialog: ' + dialog.type + ' "' + oneLine(dialog.message, 150) + '"');
      await orderCard().waitFor({ state: 'detached' });

      const p = s.hubPage;
      await p.goto(base + '/stock.html');
      await p.waitForFunction(PAGE_READY['stock.html']);
      const a = await readOnHand(p, 'SMP001');
      const b = await readOnHand(p, 'SMP004');
      ok(Math.abs(a - (s.before.SMP001 + 10)) < 0.0005, 'SMP001 on hand is ' + a + ', expected ' + (s.before.SMP001 + 10));
      ok(Math.abs(b - (s.before.SMP004 + 2.5)) < 0.0005, 'SMP004 on hand is ' + b + ', expected ' + (s.before.SMP004 + 2.5));
    });

    await check('W7', 'at 375 x 812 the four pages have no page-level sideways scroll', async function () {
      // W6 delivered the only order, so the warehouse page would be measured empty. Place a
      // fresh order as the hub user first, so the pick-list table and an order card are on the page.
      ok(s.hubPage, 'W4 did not open the hub context');
      const hubP = s.hubPage;
      await hubP.goto(base + '/ordering.html');
      await hubP.locator('#ingredient-body tr').first().waitFor();
      await hubP.locator('#ingredient-body tr', { hasText: 'SMP001' }).locator('input').fill('10');
      await hubP.click('#order-submit');
      await hubP.waitForFunction(function () {
        const m = document.getElementById('order-message');
        return !!m && /placed/.test(m.textContent);
      });
      const placed = /Order #(\d+) placed/.exec(await hubP.textContent('#order-message'));
      ok(placed !== null, 'W7 could not place the order that fills the warehouse page');

      const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
      ctx.setDefaultTimeout(15000);
      watch(ctx, 'phone');
      const p = await ctx.newPage();
      await login(p, base, HQ);
      const wide = [];
      for (const name of ['warehouse.html', 'ingredients.html', 'accounts.html', 'stock.html']) {
        await openPage(p, base, name);
        await p.waitForLoadState('networkidle');
        if (name === 'warehouse.html') {
          const card = p.locator('.wh-order', { hasText: 'Order #' + placed[1] + ' -' });
          await card.waitFor();
          ok(await card.locator('table').count() === 1, 'W7: the order card has no table on the warehouse page');
          ok(await p.locator('#picklist-body tr').count() >= 1, 'W7: the pick-list table has no rows on the warehouse page');
        }
        const w = await p.evaluate(function () { return document.documentElement.scrollWidth; });
        if (!(w <= 375)) {
          wide.push(name + ' scrollWidth=' + w);
        }
      }
      await ctx.close();
      ok(wide.length === 0, 'sideways scroll at 375 px: ' + wide.join('; '));
    });
  } catch (e) {
    failed++;
    console.log('FAIL RUN unexpected error ... ' + oneLine(e && e.message ? e.message : e, 500));
  }

  await shutdown(server, browser);
  finish(passed, failed);
  return failed === 0 ? 0 : 1;
}

async function shutdown(server, browser) {
  if (browser) {
    try { await browser.close(); } catch (e) { /* already gone */ }
  }
  if (server && server.child) {
    const child = server.child;
    const exited = new Promise(function (resolve) { child.on('exit', resolve); });
    try { child.stdin.end(); } catch (e) { /* already closed */ }
    const timedOut = await Promise.race([exited.then(function () { return false; }), sleep(15000).then(function () { return true; })]);
    if (timedOut) {
      child.kill();
    }
  }
}

main().then(function (code) {
  process.exit(code);
}, function (e) {
  finish(0, 1, 'unexpected: ' + oneLine(e && e.message ? e.message : e, 400));
  process.exit(1);
});
