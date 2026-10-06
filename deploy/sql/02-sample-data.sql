-- SAMPLE ingredients and recipes (SPEC s5.2, locked assumption A3). Every row is is_sample = 1 and named "SAMPLE ...".
-- Real recipes are loaded when Jolandi supplies them. bin/seed.php skips this file when recipes already has rows.
-- Costs in cents (excl VAT), recipe quantities in thousandths per ONE finished unit.

INSERT INTO ingredients (sku, name, unit, cost_cents, fincon_item_no, is_sample, active, created_at, updated_at) VALUES
  ('SMP001', 'SAMPLE Cake flour', 'kg', 1250, 'F-SMP001', 1, 1, '2026-10-06 00:00:00', '2026-10-06 00:00:00'),
  ('SMP002', 'SAMPLE Castor sugar', 'kg', 1800, 'F-SMP002', 1, 1, '2026-10-06 00:00:00', '2026-10-06 00:00:00'),
  ('SMP003', 'SAMPLE Liquid eggs', 'l', 4500, 'F-SMP003', 1, 1, '2026-10-06 00:00:00', '2026-10-06 00:00:00'),
  ('SMP004', 'SAMPLE Butter', 'kg', 12000, 'F-SMP004', 1, 1, '2026-10-06 00:00:00', '2026-10-06 00:00:00'),
  ('SMP005', 'SAMPLE Cocoa powder', 'kg', 16000, 'F-SMP005', 1, 1, '2026-10-06 00:00:00', '2026-10-06 00:00:00'),
  ('SMP006', 'SAMPLE Full cream milk', 'l', 2000, NULL, 1, 1, '2026-10-06 00:00:00', '2026-10-06 00:00:00');

INSERT INTO recipes (id, finished_sku, name, unit, is_sample, active, created_at, updated_at) VALUES
  (1, 'SMP-F01', 'SAMPLE Choc muffin', 'each', 1, 1, '2026-10-06 00:00:00', '2026-10-06 00:00:00'),
  (2, 'SMP-F02', 'SAMPLE Plain scone', 'each', 1, 1, '2026-10-06 00:00:00', '2026-10-06 00:00:00'),
  (3, 'SMP-F03', 'SAMPLE Vanilla custard 1 l', 'tub', 1, 1, '2026-10-06 00:00:00', '2026-10-06 00:00:00');

-- Choc muffin = 5.59 per unit; Plain scone = 4.38; Vanilla custard 1 l = 22.66 (SPEC s5.2)
INSERT INTO recipe_lines (recipe_id, sku, qty_milli) VALUES
  (1, 'SMP001', 60),
  (1, 'SMP002', 30),
  (1, 'SMP003', 20),
  (1, 'SMP004', 15),
  (1, 'SMP005', 10),
  (2, 'SMP001', 80),
  (2, 'SMP004', 20),
  (2, 'SMP006', 40),
  (2, 'SMP002', 10),
  (3, 'SMP006', 800),
  (3, 'SMP003', 100),
  (3, 'SMP002', 120);
