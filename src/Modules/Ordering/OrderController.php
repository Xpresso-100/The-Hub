<?php
declare(strict_types=1);

namespace Hub\Modules\Ordering;

use Hub\Auth\Auth;
use Hub\Http\Request;
use Hub\Http\Response;

/** Hub ingredient orders (SPEC s7.4-s7.7). Status order: 401 -> 403 -> 400 -> 404 -> 422 -> 409. */
final class OrderController
{
    /** POST /api/orders - hub only. */
    public static function create(Request $req, array $params): Response
    {
        $user = Auth::requireRole($req, 'hub');
        return Response::created(['order' => OrderService::place($user, $req->json())]);
    }

    /** GET /api/orders - hq all (filters), hub own location only. */
    public static function list(Request $req, array $params): Response
    {
        $user = Auth::requireRole($req, 'hq', 'hub');
        return Response::ok(['orders' => OrderService::list($user, $req->query('location_id'), $req->query('status'))]);
    }

    /** GET /api/orders/{id} - hq any, hub own (else 404). */
    public static function show(Request $req, array $params): Response
    {
        $user = Auth::requireRole($req, 'hq', 'hub');
        return Response::ok(['order' => OrderService::find($user, (int) $params['id'])]);
    }

    /** PATCH /api/orders/{id}/status - hq only. */
    public static function updateStatus(Request $req, array $params): Response
    {
        $user = Auth::requireRole($req, 'hq');
        $body = $req->json();
        return Response::ok(['order' => OrderService::updateStatus($user, (int) $params['id'], $body['status'] ?? null)]);
    }
}
