/* The Hub - shared browser helpers (SPEC s2.4). Every page includes this file first.
 *
 *   Hub.api(method, path, body)  -> Promise<{status, data}>  (path is relative, e.g. 'api/orders')
 *                                   adds X-Auth-Token; a 401 sends the browser to login.html
 *   Hub.me()                     -> Promise<USER> (cached for the page)
 *   Hub.requireRole(...roles)    -> Promise<USER>; other roles are sent to index.html
 *   Hub.logout()
 *   Hub.escapeHtml(value)        -> escape EVERY value placed into innerHTML
 *   Hub.money(n), Hub.qty(n)     -> "1 234.50", "1.440"
 *   Hub.renderNav(user)          -> fills <nav id="hub-nav"> with the links the role may use
 */
(function () {
  'use strict';

  var TOKEN_KEY = 'hubToken';
  var mePromise = null;

  function token() {
    return window.sessionStorage.getItem(TOKEN_KEY);
  }

  function toLogin() {
    window.sessionStorage.removeItem(TOKEN_KEY);
    window.location.href = 'login.html';
  }

  function api(method, path, body) {
    var headers = { 'Accept': 'application/json' };
    var t = token();
    if (t) {
      headers['X-Auth-Token'] = t;
    }
    var init = { method: method, headers: headers, cache: 'no-store' };
    if (body !== undefined && body !== null) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    return fetch(path.replace(/^\//, ''), init).then(function (res) {
      if (res.status === 401 && path.indexOf('api/login') === -1) {
        toLogin();
        return new Promise(function () {});
      }
      var type = res.headers.get('Content-Type') || '';
      var parse = type.indexOf('application/json') !== -1 ? res.json() : res.text();
      return parse.then(function (data) {
        return { status: res.status, data: data };
      });
    });
  }

  function me() {
    if (!token()) {
      toLogin();
      return new Promise(function () {});
    }
    if (!mePromise) {
      mePromise = api('GET', 'api/me').then(function (r) {
        return r.data.user;
      });
    }
    return mePromise;
  }

  function requireRole() {
    var roles = Array.prototype.slice.call(arguments);
    return me().then(function (user) {
      if (roles.indexOf(user.role) === -1) {
        window.location.href = 'index.html';
        return new Promise(function () {});
      }
      return user;
    });
  }

  function logout() {
    return api('POST', 'api/logout', {}).then(toLogin, toLogin);
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function money(n) {
    var v = Number(n || 0).toFixed(2).split('.');
    return v[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + '.' + v[1];
  }

  function qty(n) {
    return Number(n || 0).toFixed(3);
  }

  var LINKS = [
    { href: 'index.html', label: 'Home', roles: ['hq', 'hub', 'franchise'] },
    { href: 'ordering.html', label: 'Stock & ordering', roles: ['hq', 'hub', 'franchise'] },
    { href: 'recipes.html', label: 'Recipes & batches', roles: ['hq', 'hub'] },
    { href: 'reports.html', label: 'Reports', roles: ['hq', 'hub'] }
  ];

  function renderNav(user) {
    var nav = document.getElementById('hub-nav');
    if (!nav) {
      return;
    }
    var html = LINKS.filter(function (l) {
      return l.roles.indexOf(user.role) !== -1;
    }).map(function (l) {
      return '<a href="' + escapeHtml(l.href) + '">' + escapeHtml(l.label) + '</a>';
    }).join('');
    html += '<span class="hub-user">' + escapeHtml(user.username) + ' &middot; ' + escapeHtml(user.location.name) +
      '</span><button type="button" id="hub-logout">Log out</button>';
    nav.innerHTML = html;
    document.getElementById('hub-logout').addEventListener('click', logout);
  }

  window.Hub = {
    TOKEN_KEY: TOKEN_KEY,
    api: api,
    me: me,
    requireRole: requireRole,
    logout: logout,
    escapeHtml: escapeHtml,
    money: money,
    qty: qty,
    renderNav: renderNav
  };
})();
