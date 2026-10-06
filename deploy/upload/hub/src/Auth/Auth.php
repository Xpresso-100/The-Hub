<?php
declare(strict_types=1);

namespace Hub\Auth;

use Hub\Clock;
use Hub\Config;
use Hub\Db;
use Hub\Http\HttpError;
use Hub\Http\Request;

/**
 * Login and roles (SPEC s1, s6). A user belongs to one location; the user's role IS the location type:
 * 'hq' | 'hub' | 'franchise'. Token travels in X-Auth-Token; only its sha256 is stored.
 *
 * The user array returned by user()/requireRole():
 *   ['id' => int, 'username' => string, 'email' => string, 'role' => string,
 *    'location_id' => int, 'location_name' => string, 'location_type' => string]
 */
final class Auth
{
    public const ROLES = ['hq', 'hub', 'franchise'];

    /**
     * Checks username + email + password. Returns ['token', 'expires_at' (ISO), 'user'] or null.
     * Every failure cause returns the same null (no account enumeration).
     */
    public static function attempt(string $username, string $email, string $password): ?array
    {
        $row = Db::one(
            'SELECT u.id, u.email, u.password_hash, u.active, l.active AS location_active
               FROM users u JOIN locations l ON l.id = u.location_id
              WHERE u.username = ?',
            [$username]
        );
        // Verify against a throwaway hash when the user is unknown so timing does not reveal it.
        $hash = $row !== null ? (string) $row['password_hash'] : password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
        $passwordOk = password_verify($password, $hash);
        if ($row === null || !$passwordOk || strcasecmp((string) $row['email'], $email) !== 0
            || (int) $row['active'] !== 1 || (int) $row['location_active'] !== 1) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $ttlHours = max(1, Config::int('AUTH_TOKEN_TTL_HOURS'));
        $expires = Clock::now()->modify('+' . $ttlHours . ' hours');
        $now = Clock::nowDb();
        Db::exec('DELETE FROM auth_tokens WHERE expires_at <= ?', [$now]);
        Db::insert(
            'INSERT INTO auth_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
            [(int) $row['id'], hash('sha256', $token), $expires->format('Y-m-d H:i:s'), $now]
        );
        return [
            'token' => $token,
            'expires_at' => $expires->format(DATE_ATOM),
            'user' => self::loadUser((int) $row['id']),
        ];
    }

    /** The authenticated user for this request, or HttpError 401 UNAUTHENTICATED. */
    public static function user(Request $req): array
    {
        if ($req->user !== null) {
            return $req->user;
        }
        $token = trim((string) $req->header('X-Auth-Token'));
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            throw HttpError::unauthenticated();
        }
        $row = Db::one(
            'SELECT u.id, u.username, u.email, u.location_id, l.name AS location_name, l.type AS location_type
               FROM auth_tokens t
               JOIN users u ON u.id = t.user_id
               JOIN locations l ON l.id = u.location_id
              WHERE t.token_hash = ? AND t.expires_at > ? AND u.active = 1 AND l.active = 1',
            [hash('sha256', $token), Clock::nowDb()]
        );
        if ($row === null) {
            throw HttpError::unauthenticated();
        }
        return $req->user = self::shape($row);
    }

    /** The user if their role is one of $roles: 401 when not logged in, 403 FORBIDDEN otherwise. */
    public static function requireRole(Request $req, string ...$roles): array
    {
        $user = self::user($req);
        if (!in_array($user['role'], $roles, true)) {
            throw HttpError::forbidden();
        }
        return $user;
    }

    /** Deletes the request's token. */
    public static function logout(Request $req): void
    {
        $token = trim((string) $req->header('X-Auth-Token'));
        if ($token !== '') {
            Db::exec('DELETE FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        }
    }

    /** USER as returned by the API (SPEC s6). */
    public static function publicUser(array $user): array
    {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'location' => [
                'id' => $user['location_id'],
                'name' => $user['location_name'],
                'type' => $user['location_type'],
            ],
        ];
    }

    private static function loadUser(int $id): array
    {
        $row = Db::one(
            'SELECT u.id, u.username, u.email, u.location_id, l.name AS location_name, l.type AS location_type
               FROM users u JOIN locations l ON l.id = u.location_id WHERE u.id = ?',
            [$id]
        );
        if ($row === null) {
            throw new \RuntimeException('User vanished during login');
        }
        return self::shape($row);
    }

    private static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'email' => (string) $row['email'],
            'role' => (string) $row['location_type'],
            'location_id' => (int) $row['location_id'],
            'location_name' => (string) $row['location_name'],
            'location_type' => (string) $row['location_type'],
        ];
    }
}
