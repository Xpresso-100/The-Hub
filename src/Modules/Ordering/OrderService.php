<?php
declare(strict_types=1);

namespace Hub\Modules\Ordering;

use Hub\Clock;
use Hub\Config;
use Hub\Db;
use Hub\Http\HttpError;
use Hub\Money;

/**
 * Hub -> HQ ingredient orders at COST (SPEC s7.4-s7.7, s11). Unit prices always come from
 * ingredients.cost_cents on the server; client price fields are never read. Orders are internal
 * transfers: no VAT is added or stored. A hub user only ever sees its own location's orders.
 */
final class OrderService
{
    public const STATUSES = ['pending', 'in_process', 'out_for_delivery', 'delivered', 'cancelled'];
    public const MAX_LINES = 200;
    public const MAX_QTY_MILLI = 100000 * 1000;
    public const MAX_NOTES = 500;

    /** Forward order of the fulfilment path; cancelled sits outside it. */
    private const FLOW = ['pending' => 0, 'in_process' => 1, 'out_for_delivery' => 2, 'delivered' => 3];

    /** Validates and stores a hub order, then notifies HQ. Returns ORDER (s7.4). */
    public static function place(array $user, array $body): array
    {
        $id = Db::transaction(static function () use ($user, $body): int {
            [$lines, $deliveryDate, $notes] = self::validate($body);
            $total = 0;
            foreach ($lines as $line) {
                $total += $line['line_total_cents'];
            }
            $now = Clock::nowDb();
            $orderId = Db::insert(
                "INSERT INTO orders (location_id, created_by_user_id, status, price_basis, total_cents, requested_delivery_date, notes, created_at)
                 VALUES (?, ?, 'pending', 'cost', ?, ?, ?, ?)",
                [$user['location_id'], $user['id'], $total, $deliveryDate, $notes, $now]
            );
            foreach ($lines as $line) {
                Db::insert(
                    'INSERT INTO order_lines (order_id, sku, name_snapshot, unit_snapshot, qty_milli, unit_cost_cents, line_total_cents)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$orderId, $line['sku'], $line['name'], $line['unit'], $line['qty_milli'], $line['unit_cost_cents'], $line['line_total_cents']]
                );
            }
            return $orderId;
        });

        try {
            OrderNotifier::notify($id);
        } catch (\Throwable $e) {
            // The order is saved; a broken notification must never lose it (SPEC s11).
            error_log('HUB-ERROR order notification failed for order ' . $id . ': ' . get_class($e));
        }
        return self::shape($id) ?? throw new \RuntimeException('Order vanished after insert');
    }

    /** ORDER visible to $user, else 404 (another location's order is "not found" for a hub user). */
    public static function find(array $user, int $id): array
    {
        $sql = 'SELECT id FROM orders WHERE id = ?';
        $params = [$id];
        if ($user['role'] !== 'hq') {
            $sql .= ' AND location_id = ?';
            $params[] = $user['location_id'];
        }
        if (Db::value($sql, $params) === null) {
            throw HttpError::notFound();
        }
        return self::shape($id) ?? throw HttpError::notFound();
    }

    /**
     * Order summaries, newest first (s7.5). hub: own location, any location_id ignored.
     * hq: all, optional location_id / status filters (raw query values; invalid -> 422).
     */
    public static function list(array $user, ?string $locationId, ?string $status): array
    {
        $where = [];
        $params = [];
        if ($user['role'] === 'hq') {
            $errors = [];
            if ($locationId !== null && preg_match('/^[1-9][0-9]{0,9}$/', $locationId) !== 1) {
                $errors['location_id'] = 'must be a location id';
            }
            if ($status !== null && !in_array($status, self::STATUSES, true)) {
                $errors['status'] = 'must be one of ' . implode(', ', self::STATUSES);
            }
            if ($errors !== []) {
                throw HttpError::validation($errors);
            }
            if ($locationId !== null) {
                $where[] = 'o.location_id = ?';
                $params[] = (int) $locationId;
            }
            if ($status !== null) {
                $where[] = 'o.status = ?';
                $params[] = $status;
            }
        } else {
            $where[] = 'o.location_id = ?';
            $params[] = $user['location_id'];
        }
        $rows = Db::all(
            'SELECT o.id, o.location_id, l.name AS location_name, o.status, o.total_cents, o.created_at,
                    (SELECT COUNT(*) FROM order_lines ol WHERE ol.order_id = o.id) AS line_count
               FROM orders o JOIN locations l ON l.id = o.location_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY o.created_at DESC, o.id DESC',
            $params
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'location_id' => (int) $r['location_id'],
            'location_name' => (string) $r['location_name'],
            'status' => (string) $r['status'],
            'total' => Money::toRands((int) $r['total_cents']),
            'line_count' => (int) $r['line_count'],
            'created_at' => Clock::iso((string) $r['created_at']),
        ], $rows);
    }

    /**
     * HQ moves an order along pending -> in_process -> out_for_delivery -> delivered (forward only,
     * skipping allowed); cancelled only from pending/in_process. Delivery credits the hub's ledger once.
     */
    public static function updateStatus(array $user, int $id, mixed $status): array
    {
        if (Db::value('SELECT id FROM orders WHERE id = ?', [$id]) === null) {
            throw HttpError::notFound();
        }
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw HttpError::validation(['status' => 'must be one of in_process, out_for_delivery, delivered, cancelled']);
        }

        Db::transaction(static function () use ($user, $id, $status): void {
            $order = Db::one('SELECT status, location_id FROM orders WHERE id = ?', [$id]);
            if ($order === null) {
                throw HttpError::notFound();
            }
            $from = (string) $order['status'];
            if (!self::canMove($from, $status)) {
                throw HttpError::conflict('An order cannot move from ' . $from . ' to ' . $status . '.');
            }
            $now = Clock::nowDb();
            $changed = Db::exec(
                'UPDATE orders SET status = ?, status_updated_at = ?, delivered_at = ? WHERE id = ? AND status = ?',
                [$status, $now, $status === 'delivered' ? $now : null, $id, $from]
            );
            if ($changed !== 1) {
                throw HttpError::conflict('The order was changed by someone else. Reload and try again.');
            }
            if ($status !== 'delivered') {
                return;
            }
            $already = (int) Db::value(
                "SELECT COUNT(*) FROM inventory_movements WHERE reason = 'order_delivered' AND ref_id = ?",
                [$id]
            );
            if ($already > 0) {
                return;
            }
            foreach (Db::all('SELECT sku, qty_milli FROM order_lines WHERE order_id = ? ORDER BY id', [$id]) as $line) {
                Db::insert(
                    "INSERT INTO inventory_movements (location_id, sku, qty_delta_milli, reason, ref_id, note, created_by_user_id, created_at)
                     VALUES (?, ?, ?, 'order_delivered', ?, NULL, ?, ?)",
                    [(int) $order['location_id'], (string) $line['sku'], (int) $line['qty_milli'], $id, $user['id'], $now]
                );
            }
        });

        return self::shape($id) ?? throw HttpError::notFound();
    }

    public static function canMove(string $from, string $to): bool
    {
        if ($from === 'delivered' || $from === 'cancelled') {
            return false;
        }
        if ($to === 'cancelled') {
            return $from === 'pending' || $from === 'in_process';
        }
        return isset(self::FLOW[$from], self::FLOW[$to]) && self::FLOW[$to] > self::FLOW[$from];
    }

    /** ORDER (s7.4) for an id, or null. */
    public static function shape(int $id): ?array
    {
        $o = Db::one(
            'SELECT o.*, l.name AS location_name, u.username AS created_by
               FROM orders o
               JOIN locations l ON l.id = o.location_id
               JOIN users u ON u.id = o.created_by_user_id
              WHERE o.id = ?',
            [$id]
        );
        if ($o === null) {
            return null;
        }
        $lines = array_map(static fn (array $l): array => [
            'sku' => (string) $l['sku'],
            'name' => (string) $l['name_snapshot'],
            'unit' => (string) $l['unit_snapshot'],
            'qty' => Money::toQty((int) $l['qty_milli']),
            'unit_price' => Money::toRands((int) $l['unit_cost_cents']),
            'line_total' => Money::toRands((int) $l['line_total_cents']),
        ], Db::all('SELECT * FROM order_lines WHERE order_id = ? ORDER BY id', [$id]));
        $note = Db::one('SELECT channel, status FROM order_notifications WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$id]);

        return [
            'id' => (int) $o['id'],
            'location_id' => (int) $o['location_id'],
            'location_name' => (string) $o['location_name'],
            'status' => (string) $o['status'],
            'price_basis' => (string) $o['price_basis'],
            'requested_delivery_date' => $o['requested_delivery_date'] === null ? null : substr((string) $o['requested_delivery_date'], 0, 10),
            'notes' => $o['notes'] === null ? null : (string) $o['notes'],
            'created_at' => Clock::iso((string) $o['created_at']),
            'created_by' => (string) $o['created_by'],
            'status_updated_at' => Clock::iso($o['status_updated_at'] === null ? null : (string) $o['status_updated_at']),
            'delivered_at' => Clock::iso($o['delivered_at'] === null ? null : (string) $o['delivered_at']),
            'lines' => $lines,
            'total' => Money::toRands((int) $o['total_cents']),
            'hq_notification' => $note === null ? null : ['channel' => (string) $note['channel'], 'status' => (string) $note['status']],
        ];
    }

    /**
     * SPEC s7.4 validation. Returns [lines priced at current cost, ?delivery date, ?notes]; 422 otherwise.
     * Error fields: lines, lines.<n>, lines.<n>.sku, lines.<n>.qty (n = 0-based), requested_delivery_date, notes.
     */
    private static function validate(array $body): array
    {
        $errors = [];
        $out = [];
        $lines = $body['lines'] ?? null;
        if (!is_array($lines) || $lines === [] || !array_is_list($lines)) {
            $errors['lines'] = 'at least one line is required';
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['lines'] = 'at most ' . self::MAX_LINES . ' lines';
        } else {
            $catalogue = [];
            foreach (Db::all('SELECT sku, name, unit, cost_cents, is_sample, active FROM ingredients') as $row) {
                $catalogue[(string) $row['sku']] = $row;
            }
            $production = Config::isProduction();
            $seen = [];
            foreach ($lines as $i => $line) {
                if (!is_array($line) || ($line !== [] && array_is_list($line))) {
                    $errors['lines.' . $i] = 'must be an object with sku and qty';
                    continue;
                }
                $sku = $line['sku'] ?? null;
                $ingredient = is_string($sku) ? ($catalogue[$sku] ?? null) : null;
                if ($ingredient === null || (int) $ingredient['active'] !== 1) {
                    $errors['lines.' . $i . '.sku'] = 'unknown or inactive ingredient';
                } elseif (isset($seen[$sku])) {
                    $errors['lines.' . $i . '.sku'] = 'duplicate sku';
                } elseif ($production && (int) $ingredient['is_sample'] === 1) {
                    $errors['lines.' . $i . '.sku'] = 'sample ingredient';
                }
                if (is_string($sku)) {
                    $seen[$sku] = true;
                }

                $milli = Money::parseMilli($line['qty'] ?? null);
                if ($milli === null) {
                    $errors['lines.' . $i . '.qty'] = 'must be a number with at most 3 decimals';
                } elseif ($milli <= 0) {
                    $errors['lines.' . $i . '.qty'] = 'must be greater than 0';
                } elseif ($milli > self::MAX_QTY_MILLI) {
                    $errors['lines.' . $i . '.qty'] = 'must be at most 100000';
                }

                if (!isset($errors['lines.' . $i . '.sku']) && !isset($errors['lines.' . $i . '.qty'])) {
                    $cost = (int) $ingredient['cost_cents'];
                    $out[] = [
                        'sku' => (string) $sku,
                        'name' => (string) $ingredient['name'],
                        'unit' => (string) $ingredient['unit'],
                        'qty_milli' => $milli,
                        'unit_cost_cents' => $cost,
                        'line_total_cents' => Money::lineCents($milli, $cost),
                    ];
                }
            }
        }

        $deliveryDate = $body['requested_delivery_date'] ?? null;
        if ($deliveryDate !== null && (!Clock::isDate($deliveryDate) || $deliveryDate < Clock::today())) {
            $errors['requested_delivery_date'] = 'must be a date (YYYY-MM-DD), today or later';
        }

        $notes = $body['notes'] ?? null;
        if ($notes !== null) {
            if (!is_string($notes) || mb_strlen(trim($notes)) > self::MAX_NOTES) {
                $errors['notes'] = 'must be text of at most ' . self::MAX_NOTES . ' characters';
            } else {
                $notes = trim($notes) === '' ? null : trim($notes);
            }
        }

        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        return [$out, $deliveryDate, $notes];
    }
}
