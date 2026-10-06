<?php
declare(strict_types=1);

namespace Hub\Modules\Reports;

use Hub\Auth\Auth;
use Hub\Http\HttpError;
use Hub\Http\Request;
use Hub\Http\Response;

/** SPEC s9.2-9.3: admin recipe and batch lists (hq + hub, read only). */
final class AdminListController
{
    public static function recipes(Request $req, array $params = []): Response
    {
        $user = Auth::requireRole($req, 'hq', 'hub');
        $locationId = $user['role'] === 'hub' ? (int) $user['location_id'] : null;
        return Response::ok(['recipes' => ReportService::adminRecipes($locationId)]);
    }

    public static function batches(Request $req, array $params = []): Response
    {
        $user = Auth::requireRole($req, 'hq', 'hub');
        $errors = [];
        $from = ReportController::date($req, 'from', null, $errors);
        $to = ReportController::date($req, 'to', null, $errors);
        if ($from !== null && $to !== null && $from > $to) {
            $errors['from'] = 'must not be after to';
        }
        $recipeId = ReportController::positiveInt($req, 'recipe_id', $errors);
        $locationId = ReportController::locationScope($req, $user, $errors);
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        return Response::ok(['batches' => ReportService::adminBatches([
            'from' => $from,
            'to' => $to,
            'location_id' => $locationId,
            'recipe_id' => $recipeId,
        ])]);
    }
}
