'use strict';
/*
 * UI screenshots (T-11): every page at 375x812 and 1440x900, full page.
 *
 *   node tests/browser/ui_shots.cjs --phase before|after [--root repo|bundle]
 *
 * Starts tests/browser/serve.php exactly as run.cjs does (fresh SQLite + fixtures, test database only), logs in
 * with the fixture users read from bin/seed.php (passwords are never printed), places two test orders so the
 * order tables and status badges have rows, then writes
 *   docs/night/2026-11-19/ui/<phase>/<WxH>/<page>.png
 * and prints one line per shot with the page-level horizontal overflow (scrollWidth - clientWidth).
 * Plain Node, no packages other than Playwright (found, not installed).
 */
const path = require('path');
const fs = require('fs');
const { spawn, spawnSync } = require('child_process');

const ROOT_DIR = path.join(__dirname, '..', '..');
const SERVE_PHP = path.join(__dirname, 'serve.php');
const OUT_BASE = path.join(ROOT_DIR, 'docs', 'night', '2026-11-19', 'ui');

const VIEWPORTS = [{ width: 375, height: 812 }, { width: 1440, height: 900 }];

/* page -> the role that can see it (SPEC s1; app.js LINKS and each page's requireRole) */
const PAGES = [
  { name: 'login', file: 'login.html', role: null, ready: '#login-form' },
  { name: 'index', file: 'index.html', role: 'hq', ready: '#tiles a' },
  { name: 'ordering', file: 'ordering.html', role: 'hub', ready: '#orders-body tr' },
  { name: 'recipes', file: 'recipes.html', role: 'hub', ready: '#recipes-body tr' },
  { name: 'warehouse', file: 'warehouse.html', role: 'hq', ready: '#queue-meta' },
  { name: 'ingredients', file: 'ingredients.html', role: 'hq', ready: '#ing-body tr' },
  { name: 'stock', file: 'stock.html', role: 'hub', ready: '#stock-body tr' },
  { name: 'accounts', file: 'accounts.html', role: 'hq', ready: '#acc-body tr' },
  { name: 'reports', file: 'reports.html', role: 'hub', ready: '#report-items tr' }
];

const USERNAMES = { hq: 'hq.admin', hub: 'hub.user' };

function oneLine(text, max) {
  const t = String(text).replace(/\s+/g, ' ').trim();
  return t.length > (max || 300) ? t.slice(0, max || 300) + '...' : t;
}

function sleep(ms) {
  return new Promise(function (resolve) { setTimeout(resolve, ms); });
}

/* A fixture login by username, read from bin/seed.php (the one place the test logins are defined). */
function readFixture(username) {
  const src = fs.readFileSync(path.join(ROOT_DIR, 'bin', 'seed.php'), 'utf8');
  const re = /\[\s*'[^']*'\s*,\s*'(?:hq|hub|franchise)'\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*\]/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    if (m[1] === username) {
      return { username: m[1], email: m[2], password: m[3] };
    }
  }
  throw new Error('fixture ' + username + ' not found in bin/seed.php');
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

/* ---------- the PHP server (same as run.cjs) ---------- */

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
      stopChild(child).then(function () {
        done(reject, Object.assign(new Error('no PORT line within 90 s; stderr: ' + oneLine(err)), { started: true }));
      });
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
        break;
      }
    }
  }
  throw last || new Error('php not found');
}

async function stopChild(child) {
  if (child.exitCode !== null || child.signalCode !== null) {
    return;
  }
  const exited = new Promise(function (resolve) { child.once('exit', resolve); });
  try { child.stdin.end(); } catch (e) { /* already closed */ }
  const timedOut = await Promise.race([exited.then(function () { return false; }), sleep(10000).then(function () { return true; })]);
  if (timedOut) {
    console.log('WARN serve.php did not exit within 10 s after stdin was closed; killing it');
    if (process.platform === 'win32' && child.pid) {
      try { spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' }); } catch (e) { /* fall through */ }
    }
    child.kill();
  }
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
    return await pw.chromium.launch({ headless: true });
  } catch (e) {
    if (!/Executable doesn't exist/i.test(String(e && e.message))) {
      throw e;
    }
    const exe = newestInstalledBrowser();
    if (!exe) {
      throw e;
    }
    return await pw.chromium.launch({ headless: true, executablePath: exe });
  }
}

/* ---------- helpers ---------- */

function parseArgs() {
  const a = process.argv.slice(2);
  const o = { phase: null, root: 'repo' };
  for (let i = 0; i < a.length; i++) {
    if (a[i] === '--phase' && a[i + 1]) { o.phase = a[++i]; }
    else if (a[i] === '--root' && a[i + 1]) { o.root = a[++i]; }
  }
  return o;
}

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

/* Two hub orders so the order history, detail and warehouse queue have rows (test database only). */
async function placeOrders(page) {
  return page.evaluate(async function () {
    const out = [];
    for (const lines of [[{ sku: 'SMP001', qty: 10 }, { sku: 'SMP004', qty: 2.5 }], [{ sku: 'SMP001', qty: 4 }]]) {
      const r = await Hub.api('POST', 'api/orders', { lines: lines, notes: 'ui shot order' });
      out.push(r.status);
    }
    return out;
  });
}

async function shoot(page, outDir, name, vp) {
  await page.waitForLoadState('networkidle');
  await page.evaluate(function () {
    Array.prototype.forEach.call(document.querySelectorAll('.table-scroll'), function (el) { el.scrollLeft = 0; });
  });
  await sleep(400);
  const overflow = await page.evaluate(function () {
    const d = document.documentElement;
    return d.scrollWidth - d.clientWidth;
  });
  const file = path.join(outDir, name + '.png');
  await page.screenshot({ path: file, fullPage: true });
  console.log('SHOT ' + vp.width + 'x' + vp.height + ' ' + name + ' overflow=' + overflow);
}

async function main() {
  const args = parseArgs();
  if ((args.phase !== 'before' && args.phase !== 'after') || (args.root !== 'repo' && args.root !== 'bundle')) {
    console.log('Usage: node tests/browser/ui_shots.cjs --phase before|after [--root repo|bundle]');
    return 1;
  }
  const pw = loadPlaywright();
  if (!pw) {
    console.log('playwright not found');
    return 1;
  }
  const users = { hq: readFixture(USERNAMES.hq), hub: readFixture(USERNAMES.hub) };
  let server = null;
  let browser = null;
  let code = 0;
  try {
    server = await startServer(args.root);
    browser = await launchBrowser(pw);
    const base = 'http://127.0.0.1:' + server.port;
    let seeded = false;
    let moved = false;
    for (const vp of VIEWPORTS) {
      const outDir = path.join(OUT_BASE, args.phase, vp.width + 'x' + vp.height);
      fs.mkdirSync(outDir, { recursive: true });
      const contexts = {};
      for (const role of ['hq', 'hub']) {
        const ctx = await browser.newContext({ viewport: vp });
        ctx.setDefaultTimeout(15000);
        const page = await ctx.newPage();
        await login(page, base, users[role]);
        contexts[role] = { ctx: ctx, page: page };
      }
      if (!seeded) {
        console.log('SEED orders status ' + (await placeOrders(contexts.hub.page)).join(','));
        seeded = true;
      }
      for (const p of PAGES) {
        let page;
        if (p.role === null) {
          const anon = await browser.newContext({ viewport: vp });
          page = await anon.newPage();
          contexts.anon = { ctx: anon, page: page };
        } else {
          page = contexts[p.role].page;
        }
        await page.goto(base + '/' + p.file);
        try {
          await page.waitForSelector(p.ready, { state: 'attached' });
        } catch (e) {
          console.log('WARN ' + p.name + ' ready selector ' + p.ready + ' not seen: ' + oneLine(e.message, 120));
        }
        if (p.name === 'warehouse' && !moved) {
          // one order moves Waiting -> Being picked through the real button
          const btn = page.locator('.wh-next button').first();
          if (await btn.count() > 0) {
            await btn.click();
            await page.waitForTimeout(800);
          }
          moved = true;
        }
        if (p.name === 'ordering') {
          // show the detail of the first order through the real View button
          const view = page.locator('#orders-body button[data-view]').first();
          if (await view.count() > 0) {
            await view.click();
            await page.waitForSelector('#detail-card:not(.hidden)');
          }
        }
        await shoot(page, outDir, p.name, vp);
        if (p.name === 'login') {
          // the error state: a wrong password through the real form
          await page.fill('#username', 'nobody');
          await page.fill('#email', 'nobody@example.invalid');
          await page.fill('#password', 'not-a-real-password');
          await page.click('#login-button');
          try {
            await page.waitForSelector('#login-message:not(.hidden)');
          } catch (e) {
            console.log('WARN login error message not shown');
          }
          await shoot(page, outDir, 'login-error', vp);
        }
      }
      for (const k of Object.keys(contexts)) {
        if (contexts[k] && contexts[k].ctx) {
          await contexts[k].ctx.close();
        }
      }
    }
    console.log('DONE ' + args.phase + ' -> ' + path.relative(ROOT_DIR, path.join(OUT_BASE, args.phase)));
  } catch (e) {
    console.log('FAILED: ' + oneLine(e && e.message ? e.message : e, 500));
    code = 1;
  }
  if (browser) {
    try { await browser.close(); } catch (e) { /* already gone */ }
  }
  if (server && server.child) {
    await stopChild(server.child);
  }
  return code;
}

main().then(function (c) { process.exit(c); });
