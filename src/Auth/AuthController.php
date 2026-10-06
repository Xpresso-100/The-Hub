<?php
declare(strict_types=1);

namespace Hub\Auth;

use Hub\Http\HttpError;
use Hub\Http\Request;
use Hub\Http\Response;

/** Core API (SPEC s6): health, login, logout, me. */
final class AuthController
{
    public static function health(Request $req, array $params = []): Response
    {
        return Response::ok(['status' => 'ok']);
    }

    public static function login(Request $req, array $params = []): Response
    {
        $body = $req->json();
        $errors = [];
        foreach (['username', 'email', 'password'] as $field) {
            if (!isset($body[$field]) || !is_string($body[$field]) || trim($body[$field]) === '') {
                $errors[$field] = 'required';
            }
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        $result = Auth::attempt(trim($body['username']), trim($body['email']), $body['password']);
        if ($result === null) {
            throw new HttpError(401, 'INVALID_CREDENTIALS', 'Those login details are not valid.');
        }
        return Response::ok([
            'token' => $result['token'],
            'expires_at' => $result['expires_at'],
            'user' => Auth::publicUser($result['user']),
        ]);
    }

    public static function logout(Request $req, array $params = []): Response
    {
        Auth::user($req);
        Auth::logout($req);
        return Response::ok();
    }

    public static function me(Request $req, array $params = []): Response
    {
        return Response::ok(['user' => Auth::publicUser(Auth::user($req))]);
    }
}
