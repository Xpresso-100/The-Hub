<?php
declare(strict_types=1);

namespace Hub\Modules\Recipes;

use Hub\Auth\Auth;
use Hub\Http\HttpError;
use Hub\Http\Request;
use Hub\Http\Response;

/** Recipes API (SPEC s8.1-8.2): hq and hub; franchise 403. */
final class RecipeController
{
    public static function list(Request $req, array $params = []): Response
    {
        Auth::requireRole($req, 'hq', 'hub');
        return Response::ok(['recipes' => RecipeService::list($req->query('include_inactive') === '1')]);
    }

    public static function show(Request $req, array $params): Response
    {
        Auth::requireRole($req, 'hq', 'hub');
        $recipe = RecipeService::find((int) $params['id']);
        if ($recipe === null) {
            throw HttpError::notFound();
        }
        return Response::ok(['recipe' => $recipe]);
    }

    public static function create(Request $req, array $params = []): Response
    {
        Auth::requireRole($req, 'hq', 'hub');
        return Response::created(['recipe' => RecipeService::create($req->json())]);
    }

    public static function update(Request $req, array $params): Response
    {
        Auth::requireRole($req, 'hq', 'hub');
        return Response::ok(['recipe' => RecipeService::update((int) $params['id'], $req->json())]);
    }
}
