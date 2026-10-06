/* The Hub - recipes, production batches and ingredient inventory (SPEC s8.7). hq + hub only.
 * Every server value placed into innerHTML goes through Hub.escapeHtml.
 */
(function () {
  'use strict';

  var esc = Hub.escapeHtml;
  var user = null;
  var recipes = [];
  var ingredients = [];
  var editingId = null;
  var inventoryLocation = null;

  function $(id) {
    return document.getElementById(id);
  }

  function show(el, visible) {
    el.classList.toggle('hidden', !visible);
  }

  function message(id, kind, text, errors) {
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
    $(id).innerHTML = html;
  }

  function failure(id, r) {
    var d = r.data || {};
    message(id, 'error', d.message || ('Request failed (HTTP ' + r.status + ')'), d.errors);
  }

  function today() {
    var d = new Date();
    var local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 10);
  }

  /* ---- recipes ---- */

  function loadRecipes() {
    var q = $('show-inactive').checked ? '?include_inactive=1' : '';
    return Hub.api('GET', 'api/recipes' + q).then(function (r) {
      if (r.status !== 200) {
        failure('recipes-message', r);
        return;
      }
      recipes = r.data.recipes;
      renderRecipes();
      renderBatchRecipes();
    });
  }

  function renderRecipes() {
    if (!recipes.length) {
      $('recipes-body').innerHTML = '<tr><td colspan="6" class="muted">No recipes yet.</td></tr>';
      return;
    }
    $('recipes-body').innerHTML = recipes.map(function (rc) {
      return '<tr><td>' + esc(rc.finished_sku) + '</td><td>' + esc(rc.name) +
        (rc.is_sample ? ' <span class="badge low_stock">SAMPLE</span>' : '') + '</td><td>' + esc(rc.unit) +
        '</td><td class="num">' + esc(Hub.money(rc.cost_per_unit)) + '</td><td>' + (rc.active ? 'Active' : 'Inactive') +
        '</td><td><button type="button" class="secondary" data-edit="' + esc(rc.id) + '">Edit</button></td></tr>';
    }).join('');
  }

  function ingredientOptions(selected) {
    var list = ingredients.slice();
    if (selected && !list.some(function (i) { return i.sku === selected; })) {
      list.push({ sku: selected, name: selected, unit: '' });
    }
    return list.map(function (i) {
      return '<option value="' + esc(i.sku) + '"' + (i.sku === selected ? ' selected' : '') + '>' +
        esc(i.sku + ' - ' + i.name + (i.unit ? ' (' + i.unit + ')' : '')) + '</option>';
    }).join('');
  }

  function addLine(sku, qty) {
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><select class="line-sku" required>' + ingredientOptions(sku) + '</select></td>' +
      '<td class="num"><input class="line-qty" type="number" min="0.001" max="100000" step="0.001" required value="' +
      esc(qty === undefined ? '' : qty) + '"></td>' +
      '<td><button type="button" class="secondary line-remove">Remove</button></td>';
    $('r-lines').appendChild(tr);
  }

  function openEditor(recipe) {
    editingId = recipe ? recipe.id : null;
    $('editor-title').textContent = recipe ? 'Edit recipe ' + recipe.finished_sku : 'New recipe';
    $('r-sku').value = recipe ? recipe.finished_sku : '';
    $('r-name').value = recipe ? recipe.name : '';
    $('r-unit').value = recipe ? recipe.unit : 'each';
    $('r-active').checked = recipe ? recipe.active : true;
    show($('r-active-wrap'), !!recipe);
    $('r-lines').innerHTML = '';
    if (recipe) {
      recipe.lines.forEach(function (l) { addLine(l.sku, l.qty); });
    } else {
      addLine();
    }
    message('editor-message');
    show($('editor'), true);
    $('editor').scrollIntoView();
  }

  function saveRecipe(ev) {
    ev.preventDefault();
    var lines = Array.prototype.map.call($('r-lines').querySelectorAll('tr'), function (tr) {
      return { sku: tr.querySelector('.line-sku').value, qty: Number(tr.querySelector('.line-qty').value) };
    });
    var body = { finished_sku: $('r-sku').value.trim(), name: $('r-name').value, unit: $('r-unit').value, lines: lines };
    if (editingId !== null) {
      body.active = $('r-active').checked;
    }
    var req = editingId === null ? Hub.api('POST', 'api/recipes', body) : Hub.api('PUT', 'api/recipes/' + editingId, body);
    req.then(function (r) {
      if (r.status !== 200 && r.status !== 201) {
        failure('editor-message', r);
        return;
      }
      show($('editor'), false);
      message('recipes-message', 'ok', 'Saved ' + r.data.recipe.finished_sku + ': cost per unit ' + Hub.money(r.data.recipe.cost_per_unit) + '.');
      loadRecipes();
    });
  }

  /* ---- batches (hub only) ---- */

  function renderBatchRecipes() {
    var current = $('b-recipe').value;
    $('b-recipe').innerHTML = recipes.filter(function (rc) { return rc.active; }).map(function (rc) {
      return '<option value="' + esc(rc.id) + '"' + (String(rc.id) === current ? ' selected' : '') + '>' +
        esc(rc.finished_sku + ' - ' + rc.name + ' (' + Hub.money(rc.cost_per_unit) + ' per ' + rc.unit + ')') + '</option>';
    }).join('');
  }

  function recordBatch(ev) {
    ev.preventDefault();
    var body = { recipe_id: Number($('b-recipe').value), quantity: Number($('b-qty').value) };
    if ($('b-date').value) {
      body.produced_on = $('b-date').value;
    }
    if ($('b-notes').value.trim()) {
      body.notes = $('b-notes').value.trim();
    }
    Hub.api('POST', 'api/batches', body).then(function (r) {
      if (r.status !== 201) {
        failure('batch-message', r);
        $('batch-result').innerHTML = '';
        return;
      }
      var b = r.data.batch;
      message('batch-message', 'ok', 'Batch #' + b.id + ' recorded: ' + b.quantity + ' x ' + b.name +
        ', total ' + Hub.money(b.total_cost) + ', cost per item ' + Hub.money(b.cost_per_item) + '.');
      var html = '<table><thead><tr><th>SKU</th><th>Ingredient</th><th class="num">Used</th><th>Unit</th>' +
        '<th class="num">Unit cost</th><th class="num">Line cost</th></tr></thead><tbody>' +
        b.lines.map(function (l) {
          return '<tr><td>' + esc(l.sku) + '</td><td>' + esc(l.name) + '</td><td class="num">' + esc(Hub.qty(l.qty_used)) +
            '</td><td>' + esc(l.unit) + '</td><td class="num">' + esc(Hub.money(l.unit_cost)) + '</td><td class="num">' +
            esc(Hub.money(l.line_cost)) + '</td></tr>';
        }).join('') +
        '<tr><th colspan="5">Total</th><th class="num">' + esc(Hub.money(b.total_cost)) + '</th></tr></tbody></table>';
      if (r.data.warnings.length) {
        html += '<div class="message error">Inventory below zero after this batch: ' + r.data.warnings.map(function (w) {
          return esc(w.sku) + ' ' + esc(Hub.qty(w.on_hand_after));
        }).join(', ') + '. Record an opening balance or stock count with an adjustment.</div>';
      }
      $('batch-result').innerHTML = html;
      $('b-qty').value = '';
      $('b-notes').value = '';
      loadInventory();
    });
  }

  /* ---- inventory ---- */

  function loadInventory() {
    if (inventoryLocation === null) {
      $('inv-body').innerHTML = '<tr><td colspan="4" class="muted">Enter a hub location id to see its inventory.</td></tr>';
      return Promise.resolve();
    }
    var q = user.role === 'hq' ? '?location_id=' + encodeURIComponent(inventoryLocation) : '';
    return Hub.api('GET', 'api/inventory' + q).then(function (r) {
      if (r.status !== 200) {
        failure('inv-message', r);
        $('inv-body').innerHTML = '';
        return;
      }
      message('inv-message');
      $('inv-body').innerHTML = r.data.items.map(function (i) {
        return '<tr><td>' + esc(i.sku) + '</td><td>' + esc(i.name) + '</td><td>' + esc(i.unit) +
          '</td><td class="num"' + (i.on_hand < 0 ? ' style="color:var(--bad)"' : '') + '>' + esc(Hub.qty(i.on_hand)) + '</td></tr>';
      }).join('');
    });
  }

  function saveAdjustment(ev) {
    ev.preventDefault();
    var body = { sku: $('adj-sku').value, qty_delta: Number($('adj-qty').value), reason: $('adj-reason').value };
    if (user.role === 'hq') {
      if (inventoryLocation === null) {
        message('adj-message', 'error', 'Choose a hub location id first.');
        return;
      }
      body.location_id = Number(inventoryLocation);
    }
    Hub.api('POST', 'api/inventory/adjustments', body).then(function (r) {
      if (r.status !== 201) {
        failure('adj-message', r);
        return;
      }
      message('adj-message', 'ok', 'Saved. ' + r.data.movement.sku + ' on hand is now ' + Hub.qty(r.data.on_hand) + '.');
      $('adj-qty').value = '';
      $('adj-reason').value = '';
      loadInventory();
    });
  }

  function loadIngredients() {
    return Hub.api('GET', 'api/ingredients').then(function (r) {
      ingredients = r.status === 200 && r.data.ingredients ? r.data.ingredients : [];
      $('adj-sku').innerHTML = ingredientOptions();
    });
  }

  /* ---- wiring ---- */

  Hub.requireRole('hq', 'hub').then(function (u) {
    user = u;
    Hub.renderNav(u);
    show($('batch-card'), u.role === 'hub');
    show($('inv-location-form'), u.role === 'hq');
    inventoryLocation = u.role === 'hub' ? u.location.id : null;
    $('b-date').value = today();
    $('b-date').max = today();

    $('show-inactive').addEventListener('change', loadRecipes);
    $('new-recipe').addEventListener('click', function () { openEditor(null); });
    $('r-cancel').addEventListener('click', function () { show($('editor'), false); });
    $('r-add-line').addEventListener('click', function () { addLine(); });
    $('r-lines').addEventListener('click', function (ev) {
      if (ev.target.classList.contains('line-remove')) {
        ev.target.closest('tr').remove();
      }
    });
    $('recipes-body').addEventListener('click', function (ev) {
      var id = ev.target.getAttribute('data-edit');
      if (id === null) {
        return;
      }
      Hub.api('GET', 'api/recipes/' + encodeURIComponent(id)).then(function (r) {
        if (r.status !== 200) {
          failure('recipes-message', r);
          return;
        }
        openEditor(r.data.recipe);
      });
    });
    $('recipe-form').addEventListener('submit', saveRecipe);
    $('batch-form').addEventListener('submit', recordBatch);
    $('adj-form').addEventListener('submit', saveAdjustment);
    $('inv-location-form').addEventListener('submit', function (ev) {
      ev.preventDefault();
      inventoryLocation = $('inv-location').value;
      loadInventory();
    });

    loadIngredients().then(loadRecipes);
    loadInventory();
  });
})();
