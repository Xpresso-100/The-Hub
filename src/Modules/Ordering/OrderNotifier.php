<?php
declare(strict_types=1);

namespace Hub\Modules\Ordering;

use Hub\Clock;
use Hub\Config;
use Hub\Db;
use Hub\Mail\MailerFactory;
use Hub\Money;

/**
 * Tells HQ about a hub order (SPEC s11): one plain-text message to HQ_ORDERS_EMAIL - the same channel the
 * reference uses to tell ADMIN_EMAIL about franchise orders - plus one order_notifications row per
 * attempt ('sent' / 'failed' + error). Nothing is written to FinCon or to the reference system.
 */
final class OrderNotifier
{
    public const CHANNEL = 'email';
    public const FOOTER = 'Internal transfer at cost - not a VAT invoice.';

    public static function notify(int $orderId): void
    {
        $order = Db::one(
            'SELECT o.id, o.total_cents, o.requested_delivery_date, o.notes, o.created_at,
                    l.name AS location_name, u.username AS created_by
               FROM orders o
               JOIN locations l ON l.id = o.location_id
               JOIN users u ON u.id = o.created_by_user_id
              WHERE o.id = ?',
            [$orderId]
        );
        if ($order === null) {
            return;
        }
        $lines = Db::all(
            'SELECT sku, name_snapshot, unit_snapshot, qty_milli, unit_cost_cents, line_total_cents
               FROM order_lines WHERE order_id = ? ORDER BY id',
            [$orderId]
        );

        $to = trim(Config::get('HQ_ORDERS_EMAIL'));
        $status = 'sent';
        $error = null;
        try {
            if ($to === '') {
                throw new \RuntimeException('HQ_ORDERS_EMAIL is not configured');
            }
            MailerFactory::create()->send($to, self::subject($order), self::body($order, $lines));
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = mb_substr($e->getMessage() !== '' ? $e->getMessage() : get_class($e), 0, 255);
        }

        Db::insert(
            'INSERT INTO order_notifications (order_id, channel, recipient, status, error, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$orderId, self::CHANNEL, mb_substr($to, 0, 190), $status, $error, Clock::nowDb()]
        );
    }

    /** "Hub order #<id> - <location name>" (line breaks removed). */
    public static function subject(array $order): string
    {
        return 'Hub order #' . (int) $order['id'] . ' - ' . self::oneLine((string) $order['location_name']);
    }

    public static function body(array $order, array $lines): string
    {
        $out = [
            'Hub order #' . (int) $order['id'] . ' - ' . self::oneLine((string) $order['location_name']),
            'Placed by: ' . self::oneLine((string) $order['created_by']),
            'Placed at: ' . (string) $order['created_at'],
            'Requested delivery date: ' . ($order['requested_delivery_date'] === null ? 'not given' : substr((string) $order['requested_delivery_date'], 0, 10)),
            'Notes: ' . ($order['notes'] === null ? '-' : self::oneLine((string) $order['notes'])),
            '',
            'SKU | Item | Qty | Unit | Unit cost (R) | Line total (R)',
        ];
        foreach ($lines as $l) {
            $out[] = implode(' | ', [
                (string) $l['sku'],
                self::oneLine((string) $l['name_snapshot']),
                Money::formatQty((int) $l['qty_milli']),
                self::oneLine((string) $l['unit_snapshot']),
                Money::format((int) $l['unit_cost_cents']),
                Money::format((int) $l['line_total_cents']),
            ]);
        }
        $out[] = '';
        $out[] = 'Total (R): ' . Money::format((int) $order['total_cents']);
        $out[] = '';
        $out[] = self::FOOTER;
        return implode("\n", $out) . "\n";
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $text));
    }
}
