/* The Hub - Accounts page (build spec 2026-11-16 s8). hq only.
 * Lists logins, creates hq and hub logins, deactivates and reactivates them.
 * The password lives only in the form field and in the one-time success message: it is never stored
 * in the browser, put in an address or logged. Every value placed into innerHTML is escaped.
 */
(function () {
  'use strict';

  var esc = Hub.escapeHtml;
  var me = null;

  /* No look-alike characters (I, O, l, 0, 1), so a password read aloud or copied by hand survives. */
  var PASSWORD_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  var PASSWORD_LENGTH = 16;

  function $(id) {
    return document.getElementById(id);
  }

  function message(id, text, ok) {
    var el = $(id);
    el.textContent = text;
    el.className = 'message ' + (ok ? 'ok' : 'error');
  }

  function clearMessage(id) {
    $(id).className = 'message hidden';
    $(id).textContent = '';
  }

  function errorText(r) {
    var d = r.data || {};
    var text = d.message || ('Request failed (' + r.status + ')');
    if (d.errors) {
      text += ' ' + Object.keys(d.errors).map(function (k) {
        return k + ': ' + d.errors[k];
      }).join('; ');
    }
    return text;
  }

  function when(iso) {
    return iso ? String(iso).replace('T', ' ').slice(0, 16) : '';
  }

  /* Uniform random characters from crypto.getRandomValues (rejection sampling, no modulo bias). */
  function suggestPassword() {
    var n = PASSWORD_CHARS.length;
    var limit = 256 - (256 % n);
    var buf = new Uint8Array(32);
    var out = '';
    while (out.length < PASSWORD_LENGTH) {
      window.crypto.getRandomValues(buf);
      for (var k = 0; k < buf.length && out.length < PASSWORD_LENGTH; k++) {
        if (buf[k] < limit) {
          out += PASSWORD_CHARS.charAt(buf[k] % n);
        }
      }
    }
    return out;
  }

  /* ---- locations ---- */

  function loadLocations() {
    return Hub.api('GET', 'api/admin/locations').then(function (r) {
      if (r.status !== 200) {
        message('acc-message', errorText(r), false);
        return;
      }
      var rows = r.data.locations;
      $('acc-location').innerHTML = '<option value="">Choose a location</option>' + rows.map(function (loc) {
        return '<option value="' + esc(loc.id) + '">' + esc(loc.name) + ' (' + esc(loc.type) + ')</option>';
      }).join('');
    });
  }

  /* ---- account list ---- */

  function loadUsers() {
    return Hub.api('GET', 'api/admin/users').then(function (r) {
      if (r.status !== 200) {
        message('acc-list-message', errorText(r), false);
        return;
      }
      var rows = r.data.users;
      if (rows.length === 0) {
        $('acc-body').innerHTML = '<tr><td colspan="7" class="muted">No accounts yet.</td></tr>';
        return;
      }
      $('acc-body').innerHTML = rows.map(function (a) {
        var isMe = me !== null && a.id === me.id;
        var action = isMe
          ? '<span class="muted">This is you</span>'
          : '<button type="button" class="secondary" data-id="' + esc(a.id) + '" data-activate="' + (a.active ? '0' : '1') + '">' +
            (a.active ? 'Deactivate' : 'Reactivate') + '</button>';
        return '<tr' + (a.active ? '' : ' class="muted"') + '><td>' + esc(a.username) + '</td><td>' + esc(a.email) + '</td>' +
          '<td>' + esc(a.location.name) + '</td><td>' + esc(a.location.type) + '</td>' +
          '<td>' + esc(when(a.created_at)) + '</td><td>' + (a.active ? 'Active' : 'Deactivated') + '</td>' +
          '<td>' + action + '</td></tr>';
      }).join('');
    });
  }

  function onListClick(ev) {
    var btn = ev.target;
    if (!btn || !btn.getAttribute || !btn.getAttribute('data-id')) {
      return;
    }
    var userId = btn.getAttribute('data-id');
    var activate = btn.getAttribute('data-activate') === '1';
    if (!activate && !window.confirm('Deactivate this account? The person is logged out at once and cannot log in until the account is reactivated.')) {
      return;
    }
    clearMessage('acc-list-message');
    btn.disabled = true;
    Hub.api('PATCH', 'api/admin/users/' + encodeURIComponent(userId), { active: activate }).then(function (r) {
      if (r.status !== 200) {
        btn.disabled = false;
        message('acc-list-message', errorText(r), false);
        return;
      }
      message('acc-list-message', (activate ? 'Reactivated ' : 'Deactivated ') + r.data.user.username + '.', true);
      loadUsers();
    }, function () {
      btn.disabled = false;
      message('acc-list-message', 'Network error - reload the page to see the current state.', false);
    });
  }

  /* ---- create form ---- */

  function byteLength(text) {
    return new TextEncoder().encode(text).length;
  }

  function submitForm(ev) {
    ev.preventDefault();
    clearMessage('acc-message');
    var username = $('acc-username').value.trim();
    var email = $('acc-email').value.trim();
    var secret = $('acc-password').value;
    var locationId = $('acc-location').value;

    /* Mirrors the server checks; the server stays the source of truth and its errors are shown. */
    var problems = [];
    if (!/^[A-Za-z0-9._-]{3,60}$/.test(username)) {
      problems.push('username: 3-60 letters, digits, dot, underscore or hyphen');
    }
    if (email === '' || email.length > 190 || email.indexOf('@') < 1) {
      problems.push('email: a valid address of at most 190 characters');
    }
    var bytes = byteLength(secret);
    if (bytes < 12 || bytes > 72 || secret.trim() === '') {
      problems.push('password: 12 or more characters (at most 72 bytes)');
    }
    if (locationId === '') {
      problems.push('location: choose one');
    }
    if (problems.length > 0) {
      message('acc-message', 'Please fix: ' + problems.join('; '), false);
      return;
    }

    $('acc-save').disabled = true;
    Hub.api('POST', 'api/admin/users', {
      username: username,
      email: email,
      password: secret,
      location_id: Number(locationId)
    }).then(function (r) {
      $('acc-save').disabled = false;
      if (r.status !== 201) {
        message('acc-message', errorText(r), false);
        return;
      }
      var created = r.data.user;
      /* Shown once, as text only. Reloading the page or the next action removes it. */
      message('acc-message', 'Account created. Give the new user these three things: username ' + created.username +
        ', email ' + created.email + ', password ' + secret + ' . They log in with all three. ' +
        'This password is not shown again.', true);
      secret = '';
      $('acc-password').value = '';
      $('acc-username').value = '';
      $('acc-email').value = '';
      loadUsers();
    }, function () {
      $('acc-save').disabled = false;
      message('acc-message', 'Network error - the account may or may not exist. Check the list before retrying.', false);
    });
  }

  /* ---- start ---- */

  Hub.requireRole('hq').then(function (u) {
    me = u;
    Hub.renderNav(u);
    $('acc-form').addEventListener('submit', submitForm);
    $('acc-suggest').addEventListener('click', function () {
      clearMessage('acc-message');
      $('acc-password').value = suggestPassword();
    });
    $('acc-body').addEventListener('click', onListClick);
    loadLocations();
    loadUsers();
  });
})();
