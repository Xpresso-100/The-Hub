<?php
declare(strict_types=1);

namespace Hub\Modules\Ordering;

use Hub\Auth\Auth;
use Hub\Http\Request;
use Hub\Http\Response;

/** GET /api/stock (SPEC s7.1) - every role, same payload; it never carries a cost, price or quantity. */
final class StockController
{
    public static function show(Request $req, array $params): Response
    {
        Auth::requireRole($req, 'hq', 'hub', 'franchise');
        return Response::ok((new StockService())->view());
    }
}
