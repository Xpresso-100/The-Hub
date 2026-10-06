<?php
declare(strict_types=1);

namespace Hub\Modules\Recipes;

use Hub\Auth\Auth;
use Hub\Http\Request;
use Hub\Http\Response;

/** Hub ingredient inventory (SPEC s8.5-8.6): hub own location; hq names a hub location; franchise 403. */
final class InventoryController
{
    public static function list(Request $req, array $params = []): Response
    {
        $user = Auth::requireRole($req, 'hq', 'hub');
        $locationId = InventoryService::locationFor($user, $req->query('location_id'));
        return Response::ok(['location_id' => $locationId, 'items' => InventoryService::items($locationId)]);
    }

    public static function adjust(Request $req, array $params = []): Response
    {
        $user = Auth::requireRole($req, 'hq', 'hub');
        return Response::created(InventoryService::adjust($user, $req->json()));
    }
}
