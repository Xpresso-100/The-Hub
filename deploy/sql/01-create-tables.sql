-- The Hub schema for MySQL 5.7+ / MariaDB 10.2+ (production). Same tables and columns as schema.sqlite.sql (SPEC s4).
-- Money in integer cents, quantities in integer thousandths of the unit. DATETIME holds SAST local time.
-- phpMyAdmin: run one statement at a time (multi-statement submissions fail with #1109).

CREATE TABLE IF NOT EXISTS locations (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  type VARCHAR(20) NOT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT chk_locations_type CHECK (type IN ('hq', 'hub', 'franchise'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT,
  location_id INT NOT NULL,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_location (location_id),
  CONSTRAINT fk_users_location FOREIGN KEY (location_id) REFERENCES locations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_tokens_hash (token_hash),
  KEY idx_auth_tokens_user (user_id),
  CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingredients (
  sku VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  unit VARCHAR(20) NOT NULL,
  cost_cents INT NOT NULL,
  fincon_item_no VARCHAR(25) NULL,
  is_sample TINYINT NOT NULL DEFAULT 0,
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (sku),
  CONSTRAINT chk_ingredients_cost CHECK (cost_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_cache (
  fincon_item_no VARCHAR(25) NOT NULL,
  in_stock_milli BIGINT NOT NULL,
  fetched_at DATETIME NOT NULL,
  PRIMARY KEY (fincon_item_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id INT NOT NULL AUTO_INCREMENT,
  location_id INT NOT NULL,
  created_by_user_id INT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  price_basis VARCHAR(10) NOT NULL DEFAULT 'cost',
  total_cents BIGINT NOT NULL,
  requested_delivery_date DATE NULL,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  status_updated_at DATETIME NULL,
  delivered_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_orders_location_created (location_id, created_at),
  KEY idx_orders_user (created_by_user_id),
  CONSTRAINT fk_orders_location FOREIGN KEY (location_id) REFERENCES locations (id),
  CONSTRAINT fk_orders_user FOREIGN KEY (created_by_user_id) REFERENCES users (id),
  CONSTRAINT chk_orders_status CHECK (status IN ('pending', 'in_process', 'out_for_delivery', 'delivered', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_lines (
  id INT NOT NULL AUTO_INCREMENT,
  order_id INT NOT NULL,
  sku VARCHAR(20) NOT NULL,
  name_snapshot VARCHAR(150) NOT NULL,
  unit_snapshot VARCHAR(20) NOT NULL,
  qty_milli BIGINT NOT NULL,
  unit_cost_cents INT NOT NULL,
  line_total_cents BIGINT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_order_lines_order (order_id),
  CONSTRAINT fk_order_lines_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_notifications (
  id INT NOT NULL AUTO_INCREMENT,
  order_id INT NOT NULL,
  channel VARCHAR(20) NOT NULL,
  recipient VARCHAR(190) NOT NULL,
  status VARCHAR(10) NOT NULL,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_order_notifications_order (order_id),
  CONSTRAINT fk_order_notifications_order FOREIGN KEY (order_id) REFERENCES orders (id),
  CONSTRAINT chk_order_notifications_status CHECK (status IN ('sent', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipes (
  id INT NOT NULL AUTO_INCREMENT,
  finished_sku VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  unit VARCHAR(20) NOT NULL,
  is_sample TINYINT NOT NULL DEFAULT 0,
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipes_finished_sku (finished_sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_lines (
  id INT NOT NULL AUTO_INCREMENT,
  recipe_id INT NOT NULL,
  sku VARCHAR(20) NOT NULL,
  qty_milli BIGINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_lines_recipe_sku (recipe_id, sku),
  KEY idx_recipe_lines_sku (sku),
  CONSTRAINT fk_recipe_lines_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (id),
  CONSTRAINT fk_recipe_lines_ingredient FOREIGN KEY (sku) REFERENCES ingredients (sku),
  CONSTRAINT chk_recipe_lines_qty CHECK (qty_milli > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batches (
  id INT NOT NULL AUTO_INCREMENT,
  location_id INT NOT NULL,
  recipe_id INT NOT NULL,
  created_by_user_id INT NOT NULL,
  finished_sku VARCHAR(20) NOT NULL,
  name_snapshot VARCHAR(150) NOT NULL,
  unit_snapshot VARCHAR(20) NOT NULL,
  quantity INT NOT NULL,
  produced_on DATE NOT NULL,
  total_cost_cents BIGINT NOT NULL,
  cost_per_item_cents BIGINT NOT NULL,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_batches_location_produced (location_id, produced_on),
  KEY idx_batches_recipe (recipe_id),
  KEY idx_batches_user (created_by_user_id),
  CONSTRAINT fk_batches_location FOREIGN KEY (location_id) REFERENCES locations (id),
  CONSTRAINT fk_batches_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (id),
  CONSTRAINT fk_batches_user FOREIGN KEY (created_by_user_id) REFERENCES users (id),
  CONSTRAINT chk_batches_quantity CHECK (quantity >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batch_lines (
  id INT NOT NULL AUTO_INCREMENT,
  batch_id INT NOT NULL,
  sku VARCHAR(20) NOT NULL,
  name_snapshot VARCHAR(150) NOT NULL,
  unit_snapshot VARCHAR(20) NOT NULL,
  qty_used_milli BIGINT NOT NULL,
  unit_cost_cents INT NOT NULL,
  line_cost_cents BIGINT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_batch_lines_batch (batch_id),
  CONSTRAINT fk_batch_lines_batch FOREIGN KEY (batch_id) REFERENCES batches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id INT NOT NULL AUTO_INCREMENT,
  location_id INT NOT NULL,
  sku VARCHAR(20) NOT NULL,
  qty_delta_milli BIGINT NOT NULL,
  reason VARCHAR(20) NOT NULL,
  ref_id INT NULL,
  note VARCHAR(200) NULL,
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_inventory_location_sku (location_id, sku),
  KEY idx_inventory_user (created_by_user_id),
  CONSTRAINT fk_inventory_location FOREIGN KEY (location_id) REFERENCES locations (id),
  CONSTRAINT fk_inventory_user FOREIGN KEY (created_by_user_id) REFERENCES users (id),
  CONSTRAINT chk_inventory_reason CHECK (reason IN ('order_delivered', 'batch', 'adjustment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
