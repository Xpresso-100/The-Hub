/* The Hub - Hub stock page (build spec 8b). hq + hub only.
 * Reads GET api/inventory and records a stock count with POST api/inventory/adjustments.
 * Every API value placed into markup goes through esc() (Hub.escapeHtml). */
(function () {
  'use strict';

  var esc = Hub.escapeHtml;
  var user = null;
  var useFallback = false;
  var NOTE_NEGATIVE = 'more used than received';

  function $(id) {
    return document.getElementById(id);
  }

  function show(el, visible) {
    el.classList.toggle('hidden', !visible);
  }

  function message(kind, text, errors) {
    var html = '';
    if (text) {
      html = '<div class="message ' + esc(kind) + '">' + esc(text);
      if (errors) {
        html += '<ul>' + Object.keys(errors).map(function (k) {
          return '<li>' + esc(k) + ': ' + esc(errors[k]) + '</li>';
        }).join('') + '</ul>';
      }
      html += '</div>';
    }
    $('stock-message').innerHTML = html;
  }

  function failure(r) {
    var d = r.data || {};
    message('error', d.message || ('Request failed (HTTP ' + r.status + ')'), d.errors);
  }

  function emptyRow(text) {
    $('stock-body').innerHTML = '<tr><td colspan="4" class="muted">' + esc(text) + '</td></tr>';
  }

  /* The hub location being viewed: null for a hub user (the server uses their own). */
  function chosenLocation() {
    if (user.role !== 'hq') {
      return null;
    }
    var raw = useFallback ? $('stock-location-id').value : $('stock-location').value;
    var id = Number(raw);
    return raw !== '' && Number.isInteger(id) && id > 0 ? id : 0;
  }

  function optionsFor(items, selected) {
    return items.map(function (it) {
      return '<option value="' + esc(it.sku) + '"' + (it.sku === selected ? ' selected' : '') + '>' +
        esc(it.sku) + ' - ' + esc(it.name) + (it.unit ? ' (' + esc(it.unit) + ')' : '') + '</option>';
    }).join('');
  }

  function renderRows(items) {
    if (items.length === 0) {
      emptyRow('No ingredients are set up yet.');
      return;
    }
    $('stock-body').innerHTML = items.map(function (it) {
      var negative = Number(it.on_hand) < 0;
      return '<tr><td>' + esc(it.sku) + '</td><td>' + esc(it.name) + '</td><td>' + esc(it.unit) +
        '</td><td class="num">' + esc(Hub.qty(it.on_hand)) +
        (negative ? ' <span class="muted">(' + esc(NOTE_NEGATIVE) + ')</span>' : '') + '</td></tr>';
    }).join('');
  }

  function loadStock() {
    var id = chosenLocation();
    if (id === 0) {
      $('stock-meta').textContent = 'Choose a hub location to see its stock.';
      emptyRow('No hub location chosen.');
      return Promise.resolve();
    }
    var query = id === null ? '' : '?location_id=' + encodeURIComponent(id);
    $('stock-meta').textContent = 'Loading…';
    return Hub.api('GET', 'api/inventory' + query).then(function (r) {
      if (r.status !== 200) {
        failure(r);
        $('stock-meta').textContent = 'Stock could not be loaded.';
        emptyRow('');
        return;
      }
      var items = r.data.items || [];
      var keep = $('adj-sku').value;
      $('adj-sku').innerHTML = optionsFor(items, keep);
      renderRows(items);
      $('stock-meta').textContent = user.role === 'hq'
        ? 'Showing location ' + r.data.location_id + '.'
        : 'Showing ' + user.location.name + '.';
    });
  }

  function saveCount(ev) {
    ev.preventDefault();
    var qty = Number($('adj-qty').value);
    var reason = $('adj-reason').value.trim();
    if ($('adj-qty').value === '' || !isFinite(qty) || qty === 0) {
      message('error', 'Enter a count that is not zero, for example 12.5 or -2.');
      return;
    }
    if (reason.length < 1 || reason.length > 200) {
      message('error', 'Enter a reason of 1 to 200 characters.');
      return;
    }
    var body = { sku: $('adj-sku').value, qty_delta: qty, reason: reason };
    if (user.role === 'hq') {
      var id = chosenLocation();
      if (!id) {
        message('error', 'Choose a hub location first.');
        return;
      }
      body.location_id = id;
    }
    $('adj-save').disabled = true;
    Hub.api('POST', 'api/inventory/adjustments', body).then(function (r) {
      $('adj-save').disabled = false;
      if (r.status !== 201) {
        failure(r);
        return;
      }
      message('ok', 'Saved. ' + r.data.movement.sku + ' on hand is now ' + Hub.qty(r.data.on_hand) + '.');
      $('adj-qty').value = '';
      $('adj-reason').value = '';
      return loadStock();
    }, function () {
      $('adj-save').disabled = false;
      message('error', 'Could not reach the server. The count was not saved.');
    });
  }

  /* hq: hub locations from the admin list (lane C); if that is unavailable, a plain id box. */
  function loadLocations() {
    return Hub.api('GET', 'api/admin/locations').then(function (r) {
      var list = r.status === 200 && r.data && Array.isArray(r.data.locations) ? r.data.locations : [];
      var hubs = list.filter(function (l) {
        return l.type === 'hub';
      });
      if (hubs.length === 0) {
        useFallback = true;
        show($('stock-location').previousElementSibling, false);
        show($('stock-location'), false);
        show($('location-fallback'), true);
        return;
      }
      $('stock-location').innerHTML = hubs.map(function (l) {
        return '<option value="' + esc(l.id) + '">' + esc(l.name) + '</option>';
      }).join('');
    }, function () {
      useFallback = true;
      show($('stock-location').previousElementSibling, false);
      show($('stock-location'), false);
      show($('location-fallback'), true);
    });
  }

  Hub.requireRole('hq', 'hub').then(function (u) {
    user = u;
    Hub.renderNav(u);
    $('adj-form').addEventListener('submit', saveCount);
    if (u.role !== 'hq') {
      return loadStock();
    }
    show($('location-picker'), true);
    $('stock-location').addEventListener('change', loadStock);
    $('stock-location-go').addEventListener('click', loadStock);
    return loadLocations().then(loadStock);
  });
})();
