<?php
declare(strict_types=1);

namespace Hub\Modules\Warehouse;

use Hub\Clock;
use Hub\Db;
use Hub\Money;

/**
 * The warehouse queue (build spec 2026-11-16 s6): every open hub order with its lines, plus the pick list
 * of what is still to be pulled. SELECT only. No money field is ever returned (no cost, price or total).
 */
final class QueueService
{
    /** Statuses shown in the queue, in badge order. */
    public const OPEN_STATUSES = ['pending', 'in_process', 'out_for_delivery'];

    /** Statuses whose lines are still to be pulled from the warehouse. */
    public const PICK_STATUSES = ['pending', 'in_process'];

    /** Order ids per IN (...) list; keeps well under the SQLite bound-parameter limit. */
    private const IN_CHUNK = 500;

    /** QUEUE: {generated_at, counts, orders, picklist}. */
    public static function queue(): array
    {
        $marks = self::marks(count(self::OPEN_STATUSES));
        $rows = Db::all(
            'SELECT o.id, o.status, o.requested_delivery_date, o.notes, o.created_at,
                    l.name AS location_name, u.username AS created_by
               FROM orders o
               JOIN locations l ON l.id = o.location_id
               JOIN users u ON u.id = o.created_by_user_id
              WHERE o.status IN (' . $marks . ')
              ORDER BY (o.requested_delivery_date IS NULL), o.requested_delivery_date, o.created_at, o.id',
            self::OPEN_STATUSES
        );

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $linesByOrder = self::linesByOrder($ids);
        $notifications = self::latestNotifications($ids);

        $counts = array_fill_keys(self::OPEN_STATUSES, 0);
        $orders = [];
        $pick = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $status = (string) $r['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            $toPick = in_array($status, self::PICK_STATUSES, true);

            $lines = [];
            foreach ($linesByOrder[$id] ?? [] as $l) {
                $sku = (string) $l['sku'];
                $milli = (int) $l['qty_milli'];
                $lines[] = [
                    'sku' => $sku,
                    'name' => (string) $l['name_snapshot'],
                    'unit' => (string) $l['unit_snapshot'],
                    'qty' => Money::toQty($milli),
                ];
                if (!$toPick) {
                    continue;
                }
                if (!isset($pick[$sku])) {
                    $pick[$sku] = ['milli' => 0, 'order_ids' => [], 'newest' => 0, 'name' => '', 'unit' => ''];
                }
                $pick[$sku]['milli'] += $milli;
                $pick[$sku]['order_ids'][$id] = $id;
                if ($id >= $pick[$sku]['newest']) {
                    // Name and unit come from the snapshot on the newest order's line.
                    $pick[$sku]['newest'] = $id;
                    $pick[$sku]['name'] = (string) $l['name_snapshot'];
                    $pick[$sku]['unit'] = (string) $l['unit_snapshot'];
                }
            }

            $orders[] = [
                'id' => $id,
                'location_name' => (string) $r['location_name'],
                'status' => $status,
                'created_at' => Clock::iso((string) $r['created_at']),
                'created_by' => (string) $r['created_by'],
                'requested_delivery_date' => $r['requested_delivery_date'] === null ? null : substr((string) $r['requested_delivery_date'], 0, 10),
                'notes' => $r['notes'] === null ? null : (string) $r['notes'],
                'hq_notification' => $notifications[$id] ?? 'none',
                'lines' => $lines,
            ];
        }

        ksort($pick, SORT_STRING);
        $picklist = [];
        foreach ($pick as $sku => $p) {
            $orderIds = array_values($p['order_ids']);
            sort($orderIds, SORT_NUMERIC);
            $picklist[] = [
                'sku' => (string) $sku,
                'name' => $p['name'],
                'unit' => $p['unit'],
                'qty' => Money::toQty($p['milli']),
                'order_ids' => $orderIds,
            ];
        }

        return [
            'generated_at' => Clock::now()->format(DATE_ATOM),
            'counts' => $counts,
            'orders' => $orders,
            'picklist' => $picklist,
        ];
    }

    /**
     * Lines of the given orders in one query per IN chunk (one query for up to 500 open orders).
     * @param list<int> $ids
     * @return array<int, list<array<string,mixed>>> order id => lines in entry order
     */
    private static function linesByOrder(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, self::IN_CHUNK) as $chunk) {
            $rows = Db::all(
                'SELECT order_id, sku, name_snapshot, unit_snapshot, qty_milli
                   FROM order_lines
                  WHERE order_id IN (' . self::marks(count($chunk)) . ')
                  ORDER BY order_id, id',
                $chunk
            );
            foreach ($rows as $row) {
                $out[(int) $row['order_id']][] = $row;
            }
        }
        return $out;
    }

    /**
     * Latest HQ notification status per order: 'sent' or 'failed' (orders without a row are left out).
     * @param list<int> $ids
     * @return array<int,string>
     */
    private static function latestNotifications(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, self::IN_CHUNK) as $chunk) {
            $rows = Db::all(
                'SELECT order_id, status
                   FROM order_notifications
                  WHERE order_id IN (' . self::marks(count($chunk)) . ')
                  ORDER BY order_id, id',
                $chunk
            );
            foreach ($rows as $row) {
                $status = (string) $row['status'];
                $out[(int) $row['order_id']] = $status === 'sent' ? 'sent' : 'failed'; // the last row wins
            }
        }
        return $out;
    }

    /** "?, ?, ?" for $n bound values (never interpolated values). */
    private static function marks(int $n): string
    {
        return implode(', ', array_fill(0, $n, '?'));
    }
}
