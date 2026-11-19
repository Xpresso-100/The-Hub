<?php
declare(strict_types=1);

namespace Hub\Modules\Accounts;

use Hub\Auth\Auth;
use Hub\Http\HttpError;
use Hub\Http\Request;
use Hub\Http\Response;

/** Hub logins (build spec 2026-11-16 s8). hq only: 401 when not logged in, 403 for hub and franchise. */
final class AccountController
{
    /** GET /api/admin/locations - active hq and hub locations (never franchise). */
    public static function locations(Request $req, array $params): Response
    {
        Auth::requireRole($req, 'hq');
        return Response::ok(['locations' => AccountService::accountLocations()]);
    }

    /** GET /api/admin/users - every user, ordered by id, never a password hash. */
    public static function list(Request $req, array $params): Response
    {
        Auth::requireRole($req, 'hq');
        return Response::ok(['users' => AccountService::listUsers()]);
    }

    /** POST /api/admin/users - 201 {"user": ...}; 422 per-field errors; 409 username taken. */
    public static function create(Request $req, array $params): Response
    {
        $creator = Auth::requireRole($req, 'hq');
        $user = AccountService::create($req->json(), (int) $creator['id']);
        return Response::created(['user' => $user]);
    }

    /** PATCH /api/admin/users/{id} body {"active": true|false} - 200 {"user": ...}; 404 unknown; 409 self-deactivate. */
    public static function setActive(Request $req, array $params): Response
    {
        $actor = Auth::requireRole($req, 'hq');
        $body = $req->json();
        $active = $body['active'] ?? null;
        if (!is_bool($active)) {
            throw HttpError::validation(['active' => 'must be true or false']);
        }
        $user = AccountService::setActive((int) $params['id'], $active, (int) $actor['id']);
        return Response::ok(['user' => $user]);
    }
}
