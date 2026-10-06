-- The Hub schema for SQLite (tests, local dev). Same tables and columns as schema.mysql.sql (SPEC s4).
-- Money in integer cents, quantities in integer thousandths of the unit. DATETIME = TEXT 'Y-m-d H:i:s' (SAST).

CREATE TABLE IF NOT EXISTS locations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  type TEXT NOT NULL CHECK (type IN ('hq', 'hub', 'franchise')),
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  location_id INTEGER NOT NULL REFERENCES locations(id),
  username TEXT NOT NULL UNIQUE,
  email TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  token_hash TEXT NOT NULL UNIQUE,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_auth_tokens_user ON auth_tokens (user_id);

CREATE TABLE IF NOT EXISTS ingredients (
  sku TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  unit TEXT NOT NULL,
  cost_cents INTEGER NOT NULL CHECK (cost_cents >= 0),
  fincon_item_no TEXT NULL,
  is_sample INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_cache (
  fincon_item_no TEXT PRIMARY KEY,
  in_stock_milli INTEGER NOT NULL,
  fetched_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  location_id INTEGER NOT NULL REFERENCES locations(id),
  created_by_user_id INTEGER NOT NULL REFERENCES users(id),
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'in_process', 'out_for_delivery', 'delivered', 'cancelled')),
  price_basis TEXT NOT NULL DEFAULT 'cost',
  total_cents INTEGER NOT NULL,
  requested_delivery_date TEXT NULL,
  notes TEXT NULL,
  created_at TEXT NOT NULL,
  status_updated_at TEXT NULL,
  delivered_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_location_created ON orders (location_id, created_at);

CREATE TABLE IF NOT EXISTS order_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES orders(id),
  sku TEXT NOT NULL,
  name_snapshot TEXT NOT NULL,
  unit_snapshot TEXT NOT NULL,
  qty_milli INTEGER NOT NULL,
  unit_cost_cents INTEGER NOT NULL,
  line_total_cents INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_order_lines_order ON order_lines (order_id);

CREATE TABLE IF NOT EXISTS order_notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES orders(id),
  channel TEXT NOT NULL,
  recipient TEXT NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('sent', 'failed')),
  error TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_order_notifications_order ON order_notifications (order_id);

CREATE TABLE IF NOT EXISTS recipes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  finished_sku TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  unit TEXT NOT NULL,
  is_sample INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS recipe_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id INTEGER NOT NULL REFERENCES recipes(id),
  sku TEXT NOT NULL REFERENCES ingredients(sku),
  qty_milli INTEGER NOT NULL CHECK (qty_milli > 0),
  UNIQUE (recipe_id, sku)
);

CREATE TABLE IF NOT EXISTS batches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  location_id INTEGER NOT NULL REFERENCES locations(id),
  recipe_id INTEGER NOT NULL REFERENCES recipes(id),
  created_by_user_id INTEGER NOT NULL REFERENCES users(id),
  finished_sku TEXT NOT NULL,
  name_snapshot TEXT NOT NULL,
  unit_snapshot TEXT NOT NULL,
  quantity INTEGER NOT NULL CHECK (quantity >= 1),
  produced_on TEXT NOT NULL,
  total_cost_cents INTEGER NOT NULL,
  cost_per_item_cents INTEGER NOT NULL,
  notes TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_batches_location_produced ON batches (location_id, produced_on);

CREATE TABLE IF NOT EXISTS batch_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch_id INTEGER NOT NULL REFERENCES batches(id),
  sku TEXT NOT NULL,
  name_snapshot TEXT NOT NULL,
  unit_snapshot TEXT NOT NULL,
  qty_used_milli INTEGER NOT NULL,
  unit_cost_cents INTEGER NOT NULL,
  line_cost_cents INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_batch_lines_batch ON batch_lines (batch_id);

CREATE TABLE IF NOT EXISTS inventory_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  location_id INTEGER NOT NULL REFERENCES locations(id),
  sku TEXT NOT NULL,
  qty_delta_milli INTEGER NOT NULL,
  reason TEXT NOT NULL CHECK (reason IN ('order_delivered', 'batch', 'adjustment')),
  ref_id INTEGER NULL,
  note TEXT NULL,
  created_by_user_id INTEGER NULL REFERENCES users(id),
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_inventory_location_sku ON inventory_movements (location_id, sku);
