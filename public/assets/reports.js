// M3-reports UI (SPEC s9.4): finished-goods report with CSV download, admin recipe list, admin batch list.
// Every API value placed into innerHTML goes through Hub.escapeHtml.
(function () {
  'use strict';

  var esc = Hub.escapeHtml;
  var user = null;

  function el(id) {
    return document.getElementById(id);
  }

  function showMessage(id, text, kind) {
    el(id).innerHTML = text ? '<div class="message ' + esc(kind || 'error') + '">' + esc(text) + '</div>' : '';
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

  function query(params) {
    var parts = [];
    Object.keys(params).forEach(function (k) {
      if (params[k] !== '' && params[k] !== null && params[k] !== undefined) {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
      }
    });
    return parts.length ? '?' + parts.join('&') : '';
  }

  function reportParams() {
    var p = { from: el('report-from').value, to: el('report-to').value };
    if (user.role === 'hq') {
      p.location_id = el('report-location').value;
    }
    return p;
  }

  function batchRow(b, withCreatedBy) {
    return '<tr><td>#' + esc(b.id) + '</td><td>' + esc(b.produced_on) + '</td><td>' + esc(b.location_name) +
      '</td><td>' + esc(b.finished_sku) + ' &middot; ' + esc(b.name) + '</td><td class="num">' + esc(b.quantity) +
      '</td><td class="num">' + esc(Hub.money(b.cost_per_item)) + '</td><td class="num">' + esc(Hub.money(b.total_cost)) +
      '</td>' + (withCreatedBy ? '<td>' + esc(b.created_by || '') + '</td>' : '') + '</tr>';
  }

  function loadReport() {
    showMessage('report-message', '');
    return Hub.api('GET', 'api/reports/finished-goods' + query(reportParams())).then(function (r) {
      if (r.status !== 200) {
        showMessage('report-message', errorText(r));
        return;
      }
      var d = r.data;
      el('report-from').value = d.from;
      el('report-to').value = d.to;
      el('report-items').innerHTML = d.items.length ? d.items.map(function (i) {
        return '<tr><td>' + esc(i.finished_sku) + '</td><td>' + esc(i.name) + '</td><td>' + esc(i.unit) +
          '</td><td class="num">' + esc(i.quantity) + '</td><td class="num">' + esc(Hub.money(i.total_cost)) +
          '</td><td class="num">' + esc(Hub.money(i.avg_cost_per_item)) + '</td><td class="num">' + esc(i.batch_count) + '</td></tr>';
      }).join('') : '<tr><td colspan="7" class="muted">No batches produced in this period.</td></tr>';
      el('report-totals').innerHTML = '<tr><th colspan="3">Total</th><th class="num">' + esc(d.totals.quantity) +
        '</th><th class="num">' + esc(Hub.money(d.totals.total_cost)) + '</th><th></th><th class="num">' +
        esc(d.totals.batch_count) + '</th></tr>';
      el('report-batches').innerHTML = d.batches.map(function (b) {
        return batchRow(b, false);
      }).join('');
    });
  }

  function downloadCsv() {
    showMessage('report-message', '');
    var p = reportParams();
    p.format = 'csv';
    Hub.api('GET', 'api/reports/finished-goods' + query(p)).then(function (r) {
      if (r.status !== 200) {
        showMessage('report-message', errorText(r));
        return;
      }
      var blob = new Blob([r.data], { type: 'text/csv;charset=utf-8' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'finished-goods_' + (p.from || 'month') + '_' + (p.to || 'today') + '.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    });
  }

  function loadRecipes() {
    return Hub.api('GET', 'api/admin/recipes').then(function (r) {
      if (r.status !== 200) {
        showMessage('recipes-message', errorText(r));
        return;
      }
      var recipes = r.data.recipes;
      el('admin-recipes').innerHTML = recipes.map(function (x) {
        var status = (x.active ? 'Active' : 'Inactive') + (x.is_sample ? ' &middot; SAMPLE' : '');
        return '<tr><td>' + esc(x.finished_sku) + '</td><td>' + esc(x.name) + '</td><td>' + esc(x.unit) +
          '</td><td>' + status + '</td><td class="num">' + esc(x.line_count) + '</td><td class="num">' +
          esc(Hub.money(x.cost_per_unit)) + '</td><td class="num">' + esc(x.batch_count) + '</td><td>' +
          esc(x.last_produced_on || '-') + '</td></tr>';
      }).join('');
      el('batches-recipe').innerHTML = '<option value="">All recipes</option>' + recipes.map(function (x) {
        return '<option value="' + esc(x.id) + '">' + esc(x.finished_sku + ' - ' + x.name) + '</option>';
      }).join('');
    });
  }

  function loadBatches() {
    showMessage('batches-message', '');
    var p = { from: el('batches-from').value, to: el('batches-to').value, recipe_id: el('batches-recipe').value };
    if (user.role === 'hq') {
      p.location_id = el('batches-location').value;
    }
    return Hub.api('GET', 'api/admin/batches' + query(p)).then(function (r) {
      if (r.status !== 200) {
        showMessage('batches-message', errorText(r));
        return null;
      }
      el('admin-batches').innerHTML = r.data.batches.length ? r.data.batches.map(function (b) {
        return batchRow(b, true);
      }).join('') : '<tr><td colspan="8" class="muted">No batches.</td></tr>';
      return r.data.batches;
    });
  }

  // HQ location filter: the locations that have recorded batches (there is no locations API in Phase 1).
  function fillLocations(batches) {
    var seen = {};
    var options = '<option value="">All locations</option>';
    (batches || []).forEach(function (b) {
      if (!seen[b.location_id]) {
        seen[b.location_id] = true;
        options += '<option value="' + esc(b.location_id) + '">' + esc(b.location_name) + '</option>';
      }
    });
    el('report-location').innerHTML = options;
    el('batches-location').innerHTML = options;
    el('report-location-wrap').classList.remove('hidden');
    el('batches-location-wrap').classList.remove('hidden');
  }

  Hub.requireRole('hq', 'hub').then(function (u) {
    user = u;
    Hub.renderNav(u);
    el('report-form').addEventListener('submit', function (e) {
      e.preventDefault();
      loadReport();
    });
    el('report-csv').addEventListener('click', downloadCsv);
    el('batches-form').addEventListener('submit', function (e) {
      e.preventDefault();
      loadBatches();
    });
    loadReport();
    loadRecipes();
    loadBatches().then(function (batches) {
      if (u.role === 'hq') {
        fillLocations(batches);
      }
    });
  });
})();
