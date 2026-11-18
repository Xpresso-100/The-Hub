/* The Hub - Warehouse queue page (build spec 2026-11-16 s6). hq only.
 * Every open hub order and what to pull from the warehouse, refreshed every 60 seconds while the tab is visible.
 * Every value placed into innerHTML goes through Hub.escapeHtml. No money is shown on this page.
 */
(function () {
  'use strict';

  var esc = Hub.escapeHtml;
  var REFRESH_MS = 60000;
  var loading = false;   // a queue request is in flight
  var again = false;     // a refresh was asked for while one was in flight
  var busy = false;      // a status change is being sent
  var started = false;

  /* One forward step per status. The queue page never goes backwards and never cancels. */
  var NEXT = {
    pending: { to: 'in_process', label: 'Start picking' },
    in_process: { to: 'out_for_delivery', label: 'Out for delivery' },
    out_for_delivery: { to: 'delivered', label: 'Mark delivered' }
  };

  var STATUS_TEXT = {
    pending: 'Waiting',
    in_process: 'Being picked',
    out_for_delivery: 'Out for delivery',
    delivered: 'Delivered'
  };

  var COUNT_KEYS = ['pending', 'in_process', 'out_for_delivery'];

  function $(id) {
    return document.getElementById(id);
  }

  function message(text, ok, kind) {
    var el = $('queue-message');
    el.textContent = text;
    el.className = 'message ' + (ok ? 'ok' : 'error');
    el.setAttribute('data-kind', kind || 'action');
  }

  function clearLoadMessage() {
    var el = $('queue-message');
    if (el.getAttribute('data-kind') === 'load') {
      el.className = 'message hidden';
      el.textContent = '';
      el.removeAttribute('data-kind');
    }
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

  function pad(n) {
    return ('0' + n).slice(-2);
  }

  function when(iso) {
    return iso ? String(iso).replace('T', ' ').slice(0, 16) : '';
  }

  /* "waiting 3 h 10 min" (no colour, no thresholds: the business has not set any). */
  function age(iso, now) {
    var t = Date.parse(iso);
    if (isNaN(t)) {
      return '';
    }
    var mins = Math.max(0, Math.floor((now - t) / 60000));
    var days = Math.floor(mins / 1440);
    var hours = Math.floor((mins % 1440) / 60);
    var parts = [];
    if (days > 0) {
      parts.push(days + ' d');
    }
    if (days > 0 || hours > 0) {
      parts.push(hours + ' h');
    }
    parts.push((mins % 60) + ' min');
    return 'waiting ' + parts.join(' ');
  }

  /* ---- render ---- */

  function renderCounts(c) {
    $('queue-counts').innerHTML = COUNT_KEYS.map(function (k) {
      return '<div class="wh-count"><strong>' + esc(c[k] || 0) + '</strong><span>' + esc(STATUS_TEXT[k]) + '</span></div>';
    }).join('');
  }

  function renderPicklist(list) {
    $('queue-empty').hidden = true;
    if (list.length === 0) {
      $('picklist-body').innerHTML = '';
      $('queue-empty').textContent = 'Nothing waiting.';
      $('queue-empty').hidden = false;
      return;
    }
    $('picklist-body').innerHTML = list.map(function (it) {
      var refs = it.order_ids.map(function (n) {
        return '#' + n;
      }).join(', ');
      return '<tr><td>' + esc(it.sku) + '</td><td>' + esc(it.name) + '</td><td class="num">' + esc(Hub.qty(it.qty)) + '</td>' +
        '<td>' + esc(it.unit) + '</td><td>' + esc(refs) + '</td></tr>';
    }).join('');
  }

  function orderCard(o, now) {
    var next = NEXT[o.status];
    var html = '<article class="card wh-order">' +
      '<h2><span>Order #' + esc(o.id) + ' - ' + esc(o.location_name) + '</span>' +
      '<span class="wh-age">' + esc(age(o.created_at, now)) + '</span></h2>' +
      '<dl>' +
      '<dt>Status</dt><dd class="wh-status">' + esc(STATUS_TEXT[o.status] || o.status) + '</dd>' +
      '<dt>Placed</dt><dd>' + esc(when(o.created_at)) + ' by ' + esc(o.created_by) + '</dd>' +
      '<dt>Delivery wanted</dt><dd>' + esc(o.requested_delivery_date || 'none given') + '</dd>' +
      '<dt>Notes</dt><dd>' + esc(o.notes || '-') + '</dd>' +
      '<dt>Email to head office</dt><dd><span class="badge">' + esc(o.hq_notification || 'none') + '</span></dd>' +
      '</dl>';
    var rows = (o.lines || []).map(function (l) {
      return '<tr><td>' + esc(l.sku) + '</td><td>' + esc(l.name) + '</td><td class="num">' + esc(Hub.qty(l.qty)) + '</td>' +
        '<td>' + esc(l.unit) + '</td></tr>';
    }).join('');
    html += '<div class="table-scroll"><table><thead><tr><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div>';
    if (next) {
      html += '<p class="wh-next"><button type="button" data-id="' + esc(o.id) + '" data-to="' + esc(next.to) + '">' +
        esc(next.label) + '</button></p>';
    }
    return html + '</article>';
  }

  function renderOrders(orders, now) {
    if (orders.length === 0) {
      $('queue-orders').innerHTML = '';
      return;
    }
    $('queue-orders').innerHTML = orders.map(function (o) {
      return orderCard(o, now);
    }).join('');
  }

  function render(d) {
    var now = Date.now();
    var t = new Date(now);
    renderCounts(d.counts || {});
    renderPicklist(d.picklist || []);
    renderOrders(d.orders || [], now);
    $('queue-meta').textContent = 'Last updated ' + pad(t.getHours()) + ':' + pad(t.getMinutes()) +
      '. Refreshes every minute while this tab is open.';
    document.title = '(' + ((d.counts && d.counts.pending) || 0) + ') Warehouse queue';
  }

  /* ---- load (never two requests in flight) ---- */

  function load() {
    if (loading) {
      again = true;
      return;
    }
    loading = true;
    Hub.api('GET', 'api/warehouse/queue').then(function (r) {
      if (r.status !== 200) {
        message(errorText(r), false, 'load');
        return;
      }
      clearLoadMessage();
      render(r.data);
    }, function () {
      message('Network error - the queue could not be loaded. It will try again in a minute.', false, 'load');
    }).then(function () {
      loading = false;
      if (again) {
        again = false;
        load();
      }
    });
  }

  function tick() {
    if (document.hidden) {
      return;
    }
    load();
  }

  /* ---- move an order one step forward (existing PATCH api/orders/{id}/status) ---- */

  function move(id, to, btn) {
    if (busy) {
      return;
    }
    if (to === 'delivered' && !window.confirm('Mark order #' + id + ' delivered? This adds the items to the Hub\'s stock and cannot be undone.')) {
      return;
    }
    busy = true;
    btn.disabled = true;
    Hub.api('PATCH', 'api/orders/' + encodeURIComponent(id) + '/status', { status: to }).then(function (r) {
      busy = false;
      if (r.status === 200) {
        message('Order #' + id + ' is now: ' + (STATUS_TEXT[to] || to) + '.', true);
        load();
        return;
      }
      if (r.status === 409) {
        message('Someone else changed this order. Refreshing.', false);
        load();
        return;
      }
      btn.disabled = false;
      message(errorText(r), false);
    }, function () {
      busy = false;
      message('Network error - the order may not have moved. Refreshing.', false);
      load();
    });
  }

  /* ---- start ---- */

  Hub.requireRole('hq').then(function (user) {
    if (started) {
      return;
    }
    started = true;
    Hub.renderNav(user);
    $('refresh-btn').addEventListener('click', function () {
      load();
    });
    $('print-btn').addEventListener('click', function () {
      window.print();
    });
    $('queue-orders').addEventListener('click', function (ev) {
      var btn = ev.target && ev.target.closest ? ev.target.closest('button[data-to]') : null;
      if (btn && !btn.disabled) {
        move(btn.getAttribute('data-id'), btn.getAttribute('data-to'), btn);
      }
    });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        load();
      }
    });
    window.setInterval(tick, REFRESH_MS);
    load();
  });
})();
