<?php
declare(strict_types=1);

namespace Hub\Modules\Warehouse;

use Hub\Auth\Auth;
use Hub\Http\Request;
use Hub\Http\Response;

/** GET /api/warehouse/queue (build spec 2026-11-16 s6) - hq only: 401 not logged in, 403 hub and franchise. */
final class QueueController
{
    public static function show(Request $req, array $params): Response
    {
        Auth::requireRole($req, 'hq');
        return Response::ok(QueueService::queue());
    }
}
