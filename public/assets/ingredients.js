/* The Hub - ingredients screen (head office). Lists, adds, edits and deactivates ingredients.
 * Uses GET api/ingredients?include_inactive=1 and PUT api/admin/ingredients/{sku}.
 * The PUT replaces every field, so every save sends the full object.
 * Cost is always sent as a JSON number (the server refuses a string).
 */
(function () {
  'use strict';

  var esc = Hub.escapeHtml;
  var COST_RE = /^\d{1,9}(\.\d{1,2})?$/;
  var SKU_RE = /^[A-Z0-9-]{2,20}$/;
  var FIELDS = ['sku', 'name', 'unit', 'cost', 'fincon'];

  var items = [];
  var editing = null; // the SKU being edited, or null when adding
  var busy = false;

  function byId(id) {
    return document.getElementById(id);
  }

  function showMessage(el, kind, text) {
    el.className = 'message ' + kind;
    el.textContent = text;
  }

  function hideMessage(el) {
    el.className = 'message hidden';
    el.textContent = '';
  }

  function clearFieldErrors() {
    FIELDS.forEach(function (f) {
      byId('ing-' + f + '-err').textContent = '';
    });
  }

  function setFieldError(field, text) {
    byId('ing-' + field + '-err').textContent = text;
  }

  function findItem(sku) {
    for (var n = 0; n < items.length; n++) {
      if (items[n].sku === sku) {
        return items[n];
      }
    }
    return null;
  }

  /* The one place a request body is built. costNumber must already be a number. */
  function buildBody(name, unit, costNumber, finconItem, active) {
    return {
      name: name,
      unit: unit,
      cost_price: costNumber,
      fincon_item_no: finconItem === '' ? null : finconItem,
      active: active
    };
  }

  function putIngredient(sku, body) {
    return Hub.api('PUT', 'api/admin/ingredients/' + encodeURIComponent(sku), body);
  }

  function renderRows() {
    var showInactive = byId('ing-show-inactive').checked;
    var rows = items.filter(function (it) {
      return showInactive || it.active;
    });
    var body = byId('ing-body');
    if (rows.length === 0) {
      body.innerHTML = '<tr><td colspan="8" class="muted">' +
        (items.length === 0 ? 'No ingredients yet. Add the first one below.' : 'No active ingredients. Tick "Show inactive ingredients" to see the rest.') +
        '</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (it) {
      var cls = it.active ? '' : ' class="muted"';
      var sample = it.is_sample ? '<span class="badge low_stock">SAMPLE</span>' : '';
      var status = it.active ? 'Active' : 'Inactive';
      var toggle = it.active ? 'Deactivate' : 'Reactivate';
      return '<tr' + cls + '>' +
        '<td>' + esc(it.sku) + '</td>' +
        '<td>' + esc(it.name) + '</td>' +
        '<td>' + esc(it.unit) + '</td>' +
        '<td class="num">' + esc(Hub.money(it.cost_price)) + '</td>' +
        '<td>' + esc(it.fincon_item_no) + '</td>' +
        '<td>' + sample + '</td>' +
        '<td>' + esc(status) + '</td>' +
        '<td><button type="button" class="secondary" data-act="edit" data-sku="' + esc(it.sku) + '">Edit</button> ' +
        '<button type="button" class="secondary" data-act="toggle" data-sku="' + esc(it.sku) + '">' + esc(toggle) + '</button></td>' +
        '</tr>';
    }).join('');
  }

  function load() {
    var msg = byId('ing-list-message');
    return Hub.api('GET', 'api/ingredients?include_inactive=1').then(function (r) {
      if (r.status !== 200 || !r.data || !Array.isArray(r.data.ingredients)) {
        showMessage(msg, 'error', 'Could not load the ingredients. Refresh the page and try again.');
        return;
      }
      hideMessage(msg);
      items = r.data.ingredients;
      renderRows();
    }, function () {
      showMessage(msg, 'error', 'Could not reach the server. Check the connection and refresh.');
    });
  }

  function resetForm() {
    editing = null;
    byId('ing-form').reset();
    byId('ing-active').checked = true;
    byId('ing-sku').readOnly = false;
    byId('ing-form-title').textContent = 'Add an ingredient';
    clearFieldErrors();
  }

  function startEdit(sku) {
    var it = findItem(sku);
    if (!it) {
      return;
    }
    editing = it.sku;
    byId('ing-sku').value = it.sku;
    byId('ing-sku').readOnly = true;
    byId('ing-name').value = it.name;
    byId('ing-unit').value = it.unit;
    byId('ing-cost').value = Number(it.cost_price).toFixed(2);
    byId('ing-fincon').value = it.fincon_item_no === null ? '' : it.fincon_item_no;
    byId('ing-active').checked = !!it.active;
    byId('ing-form-title').textContent = 'Edit ' + it.sku;
    clearFieldErrors();
    hideMessage(byId('ing-message'));
    byId('ing-name').focus();
  }

  /* Shows the server's per-field errors; returns true if any field was matched. */
  function showServerErrors(errors) {
    var map = { 'sku': 'sku', 'name': 'name', 'unit': 'unit', 'cost_price': 'cost', 'fincon_item_no': 'fincon' };
    var shown = false;
    Object.keys(errors || {}).forEach(function (key) {
      if (map[key]) {
        setFieldError(map[key], String(errors[key]));
        shown = true;
      }
    });
    return shown;
  }

  function reportFailure(r, msgEl) {
    var data = r.data || {};
    var matched = data.errors ? showServerErrors(data.errors) : false;
    var text = data.message ? String(data.message) : 'The server refused the change (HTTP ' + r.status + ').';
    if (matched && r.status === 422) {
      text = 'Some fields are not valid. See the messages under each field.';
    }
    showMessage(msgEl, 'error', text);
  }

  function costChangeOk(it, costNumber) {
    if (!it || Math.round(Number(it.cost_price) * 100) === Math.round(costNumber * 100)) {
      return true;
    }
    return window.confirm('Change ' + it.name + ' from R ' + Hub.money(it.cost_price) + ' to R ' + Hub.money(costNumber) +
      '? New orders only. Existing orders and batches keep their old cost.');
  }

  function onSave(ev) {
    ev.preventDefault();
    if (busy) {
      return;
    }
    var msg = byId('ing-message');
    hideMessage(msg);
    clearFieldErrors();

    var sku = editing !== null ? editing : byId('ing-sku').value.trim().toUpperCase();
    var name = byId('ing-name').value.trim();
    var unit = byId('ing-unit').value.trim();
    var costText = byId('ing-cost').value.trim();
    var finconItem = byId('ing-fincon').value.trim();
    var active = byId('ing-active').checked;
    var bad = false;

    if (!SKU_RE.test(sku)) {
      setFieldError('sku', 'Required: 2 to 20 characters, capital letters, digits and hyphen only.');
      bad = true;
    }
    if (name.length < 1 || name.length > 150) {
      setFieldError('name', 'Required, 1 to 150 characters.');
      bad = true;
    }
    if (unit.length < 1 || unit.length > 20) {
      setFieldError('unit', 'Required, 1 to 20 characters.');
      bad = true;
    }
    if (!COST_RE.test(costText)) {
      setFieldError('cost', 'Enter a number such as 12 or 12.50: digits, an optional point, at most 2 decimals.');
      bad = true;
    }
    if (finconItem.length > 25) {
      setFieldError('fincon', 'At most 25 characters, or leave it empty.');
      bad = true;
    }
    if (bad) {
      showMessage(msg, 'error', 'Fix the fields marked below, then save again.');
      return;
    }

    var costNumber = Number(costText);
    if (editing !== null && !costChangeOk(findItem(editing), costNumber)) {
      return;
    }

    busy = true;
    byId('ing-save').disabled = true;
    putIngredient(sku, buildBody(name, unit, costNumber, finconItem, active)).then(function (r) {
      if (r.status === 200 || r.status === 201) {
        var verb = r.status === 201 ? 'added' : 'saved';
        resetForm();
        showMessage(msg, 'ok', sku + ' ' + verb + '.');
        return load();
      }
      reportFailure(r, msg);
    }, function () {
      showMessage(msg, 'error', 'Could not reach the server. Nothing was saved. Try again.');
    }).then(function () {
      busy = false;
      byId('ing-save').disabled = false;
    });
  }

  function onToggle(sku) {
    var it = findItem(sku);
    var listMsg = byId('ing-list-message');
    if (!it || busy) {
      return;
    }
    var next = !it.active;
    busy = true;
    // Full object, cost unchanged, only active flipped.
    var costNumber = Number(it.cost_price);
    var fincon = it.fincon_item_no === null ? '' : it.fincon_item_no;
    putIngredient(it.sku, buildBody(it.name, it.unit, costNumber, fincon, next)).then(function (r) {
      if (r.status === 200) {
        return load().then(function () {
          showMessage(listMsg, 'ok', it.sku + (next ? ' reactivated.' : ' deactivated.'));
        });
      }
      reportFailure(r, listMsg);
    }, function () {
      showMessage(listMsg, 'error', 'Could not reach the server. Nothing was changed.');
    }).then(function () {
      busy = false;
    });
  }

  Hub.requireRole('hq').then(function (user) {
    Hub.renderNav(user);
    byId('ing-form').addEventListener('submit', onSave);
    byId('ing-clear').addEventListener('click', function () {
      resetForm();
      hideMessage(byId('ing-message'));
    });
    byId('ing-show-inactive').addEventListener('change', renderRows);
    byId('ing-body').addEventListener('click', function (ev) {
      var btn = ev.target.closest ? ev.target.closest('button[data-act]') : null;
      if (!btn) {
        return;
      }
      var sku = btn.getAttribute('data-sku');
      if (btn.getAttribute('data-act') === 'edit') {
        startEdit(sku);
      } else {
        onToggle(sku);
      }
    });
    load();
  });
})();
