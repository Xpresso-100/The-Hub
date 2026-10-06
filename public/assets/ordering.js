/* The Hub - Stock & ordering page (SPEC s7.8).
 * franchise: stock status only. hub: stock, order ingredients at cost, own order history.
 * hq: stock, every hub order with a status control. Every value placed into innerHTML is escaped.
 */
(function () {
  'use strict';

  var esc = Hub.escapeHtml;
  var user = null;
  var ingredients = [];

  var NEXT = {
    pending: ['in_process', 'out_for_delivery', 'delivered', 'cancelled'],
    in_process: ['out_for_delivery', 'delivered', 'cancelled'],
    out_for_delivery: ['delivered'],
    delivered: [],
    cancelled: []
  };

  function $(id) {
    return document.getElementById(id);
  }

  function show(id) {
    $(id).classList.remove('hidden');
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

  /* ---- stock ---- */

  function loadStock() {
    return Hub.api('GET', 'api/stock').then(function (r) {
      if (r.status !== 200) {
        $('stock-meta').textContent = errorText(r);
        return;
      }
      var d = r.data;
      var meta = d.fincon_status === 'OK' ? 'Live from FinCon' : 'FinCon unavailable (' + d.fincon_status + ')';
      meta += d.fetched_at ? ' - last read ' + when(d.fetched_at) : ' - never read';
      if (d.stale) {
        meta += ' - may be out of date; unknown items show as in stock';
      }
      $('stock-meta').textContent = meta;
      $('stock-body').innerHTML = d.items.map(function (it) {
        return '<tr><td>' + esc(it.sku) + '</td><td>' + esc(it.name) + '</td><td>' + esc(it.unit) + '</td>' +
          '<td><span class="badge ' + esc(it.stock_status) + '">' + esc(it.stock_status.replace(/_/g, ' ')) + '</span></td></tr>';
      }).join('');
    });
  }

  /* ---- order form (hub) ---- */

  function loadIngredients() {
    return Hub.api('GET', 'api/ingredients').then(function (r) {
      if (r.status !== 200) {
        message('order-message', errorText(r), false);
        return;
      }
      ingredients = r.data.ingredients;
      $('ingredient-body').innerHTML = ingredients.map(function (it, i) {
        return '<tr><td>' + esc(it.sku) + '</td><td>' + esc(it.name) + '</td><td>' + esc(it.unit) + '</td>' +
          '<td class="num">' + esc(Hub.money(it.cost_price)) + '</td>' +
          '<td class="num"><input type="number" min="0" step="0.001" inputmode="decimal" data-i="' + esc(i) + '" ' +
          'aria-label="Quantity of ' + esc(it.name) + '"></td>' +
          '<td class="num" id="line-' + esc(i) + '">0.00</td></tr>';
      }).join('');
      updateTotal();
    });
  }

  /* Display only: the server prices every line at cost and is the source of truth. */
  function updateTotal() {
    var totalCents = 0;
    ingredients.forEach(function (it, i) {
      var input = document.querySelector('input[data-i="' + i + '"]');
      var qty = input ? Number(input.value) : 0;
      var cents = qty > 0 ? Math.round(qty * 1000 * Math.round(it.cost_price * 100) / 1000) : 0;
      $('line-' + i).textContent = Hub.money(cents / 100);
      totalCents += cents;
    });
    $('order-total').textContent = Hub.money(totalCents / 100);
  }

  function submitOrder(ev) {
    ev.preventDefault();
    clearMessage('order-message');
    var lines = [];
    ingredients.forEach(function (it, i) {
      var raw = document.querySelector('input[data-i="' + i + '"]').value.trim();
      if (raw !== '' && Number(raw) !== 0) {
        lines.push({ sku: it.sku, qty: Number(raw) });
      }
    });
    if (lines.length === 0) {
      message('order-message', 'Enter a quantity for at least one ingredient.', false);
      return;
    }
    var body = { lines: lines };
    if ($('delivery-date').value) {
      body.requested_delivery_date = $('delivery-date').value;
    }
    if ($('order-notes').value.trim()) {
      body.notes = $('order-notes').value.trim();
    }
    $('order-submit').disabled = true;
    Hub.api('POST', 'api/orders', body).then(function (r) {
      $('order-submit').disabled = false;
      if (r.status !== 201) {
        message('order-message', errorText(r), false);
        return;
      }
      var o = r.data.order;
      var note = o.hq_notification && o.hq_notification.status === 'sent'
        ? 'HQ has been notified.'
        : 'Saved, but the HQ notification failed - HQ will still see it in The Hub.';
      message('order-message', 'Order #' + o.id + ' placed, total R ' + Hub.money(o.total) + '. ' + note, true);
      $('order-form').reset();
      updateTotal();
      loadOrders();
      showDetail(o);
    }, function () {
      $('order-submit').disabled = false;
      message('order-message', 'Network error - the order may not have been placed. Check the history before retrying.', false);
    });
  }

  /* ---- history ---- */

  function loadOrders() {
    var path = 'api/orders';
    if (user.role === 'hq' && $('filter-status').value) {
      path += '?status=' + encodeURIComponent($('filter-status').value);
    }
    return Hub.api('GET', path).then(function (r) {
      if (r.status !== 200) {
        message('orders-message', errorText(r), false);
        return;
      }
      clearMessage('orders-message');
      var rows = r.data.orders;
      if (rows.length === 0) {
        $('orders-body').innerHTML = '<tr><td colspan="7" class="muted">No orders yet.</td></tr>';
        return;
      }
      $('orders-body').innerHTML = rows.map(function (o) {
        return '<tr><td>' + esc(o.id) + '</td><td>' + esc(o.location_name) + '</td><td>' + esc(when(o.created_at)) + '</td>' +
          '<td>' + esc(o.status) + '</td><td class="num">' + esc(o.line_count) + '</td>' +
          '<td class="num">' + esc(Hub.money(o.total)) + '</td>' +
          '<td><button type="button" class="secondary" data-view="' + esc(o.id) + '">View</button></td></tr>';
      }).join('');
    });
  }

  function viewOrder(id) {
    Hub.api('GET', 'api/orders/' + encodeURIComponent(id)).then(function (r) {
      if (r.status !== 200) {
        message('orders-message', errorText(r), false);
        return;
      }
      showDetail(r.data.order);
    });
  }

  function showDetail(o) {
    show('detail-card');
    $('detail-title').textContent = 'Order #' + o.id + ' - ' + o.location_name;
    var html = '<p>Status: <strong>' + esc(o.status) + '</strong> &middot; placed ' + esc(when(o.created_at)) +
      ' by ' + esc(o.created_by) + ' &middot; price basis: ' + esc(o.price_basis) + '</p>';
    html += '<p class="muted">Requested delivery: ' + esc(o.requested_delivery_date || 'not given') +
      (o.delivered_at ? ' &middot; delivered ' + esc(when(o.delivered_at)) : '') +
      ' &middot; HQ notification: ' + esc(o.hq_notification ? o.hq_notification.status : 'none') + '</p>';
    if (o.notes) {
      html += '<p>Notes: ' + esc(o.notes) + '</p>';
    }
    html += '<table><thead><tr><th>SKU</th><th>Ingredient</th><th class="num">Qty</th><th>Unit</th>' +
      '<th class="num">Unit cost (R)</th><th class="num">Line (R)</th></tr></thead><tbody>' +
      o.lines.map(function (l) {
        return '<tr><td>' + esc(l.sku) + '</td><td>' + esc(l.name) + '</td><td class="num">' + esc(Hub.qty(l.qty)) + '</td>' +
          '<td>' + esc(l.unit) + '</td><td class="num">' + esc(Hub.money(l.unit_price)) + '</td>' +
          '<td class="num">' + esc(Hub.money(l.line_total)) + '</td></tr>';
      }).join('') +
      '</tbody><tfoot><tr><th colspan="5" class="num">Total (R)</th><th class="num">' + esc(Hub.money(o.total)) + '</th></tr></tfoot></table>';

    var next = NEXT[o.status] || [];
    if (user.role === 'hq' && next.length > 0) {
      html += '<p><label for="next-status">Change status</label><select id="next-status">' +
        next.map(function (s) {
          return '<option value="' + esc(s) + '">' + esc(s) + '</option>';
        }).join('') +
        '</select> <button type="button" id="status-submit" data-id="' + esc(o.id) + '">Update status</button></p>';
    }
    html += '<div id="detail-message" class="message hidden" role="status"></div>';
    $('detail-body').innerHTML = html;
    var btn = $('status-submit');
    if (btn) {
      btn.addEventListener('click', function () {
        changeStatus(btn.getAttribute('data-id'), $('next-status').value);
      });
    }
  }

  function changeStatus(id, status) {
    if (status === 'cancelled' && !window.confirm('Cancel order #' + id + '?')) {
      return;
    }
    $('status-submit').disabled = true;
    Hub.api('PATCH', 'api/orders/' + encodeURIComponent(id) + '/status', { status: status }).then(function (r) {
      if (r.status !== 200) {
        $('status-submit').disabled = false;
        message('detail-message', errorText(r), false);
        return;
      }
      showDetail(r.data.order);
      message('detail-message', 'Status updated to ' + r.data.order.status + '.', true);
      loadOrders();
    });
  }

  /* ---- start ---- */

  Hub.me().then(function (u) {
    user = u;
    Hub.renderNav(u);
    loadStock();
    if (u.role === 'hub') {
      show('order-card');
      var d = new Date();
      $('delivery-date').min = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
      $('ingredient-body').addEventListener('input', updateTotal);
      $('order-form').addEventListener('submit', submitOrder);
      loadIngredients();
    }
    if (u.role === 'hub' || u.role === 'hq') {
      show('orders-card');
      $('orders-title').textContent = u.role === 'hq' ? 'All hub orders' : 'Order history - ' + u.location.name;
      if (u.role === 'hq') {
        show('orders-filter');
        $('filter-status').addEventListener('change', loadOrders);
      }
      $('orders-body').addEventListener('click', function (ev) {
        var id = ev.target && ev.target.getAttribute ? ev.target.getAttribute('data-view') : null;
        if (id) {
          viewOrder(id);
        }
      });
      loadOrders();
    }
  });
})();
