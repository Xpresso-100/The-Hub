<?php
declare(strict_types=1);

namespace Hub\Modules\Recipes;

use Hub\Auth\Auth;
use Hub\Http\Request;
use Hub\Http\Response;

/** Batches API (SPEC s8.3-8.4): only hub users record; hq reads any, hub its own. */
final class BatchController
{
    public static function create(Request $req, array $params = []): Response
    {
        $user = Auth::requireRole($req, 'hub');
        return Response::created(BatchService::record($user, $req->json()));
    }

    public static function show(Request $req, array $params): Response
    {
        $user = Auth::requireRole($req, 'hq', 'hub');
        return Response::ok(['batch' => BatchService::findFor($user, (int) $params['id'])]);
    }
}
