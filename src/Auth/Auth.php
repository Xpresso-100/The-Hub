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

    /** 5 failed logins for one username inside 15 minutes lock that username for 15 minutes. */
    private const LOCK_FAILURES = 5;
    private const LOCK_SECONDS = 900;
    /** bcrypt (cost 10, same as real hashes) of a random password nobody knows; verified when the username does not exist. */
    private const DUMMY_HASH = '$2y$10$hX.VIMJVz1OwrjjG4JsS1.avRPlFEpyp61u3JlEscMT1loV2l5LKO';

    /**
     * Checks username + email + password. Returns ['token', 'expires_at' (ISO), 'user'] or null.
     * Every failure cause returns the same null (no account enumeration).
     */
    public static function attempt(string $username, string $email, string $password): ?array
    {
        $row = Db::one(
            'SELECT u.id, u.username, u.email, u.password_hash, u.active, l.active AS location_active
               FROM users u JOIN locations l ON l.id = u.location_id
              WHERE u.username = ?',
            [$username]
        );
        // The counter is keyed on the STORED username when the account exists: the database matches usernames
        // case- and accent-insensitively, so keying on the typed spelling would let "HQ.Admin" dodge the lock
        // on "hq.admin". An unknown username is keyed on what was typed.
        $key = self::attemptKey($row !== null ? (string) $row['username'] : $username);
        $clockNow = Clock::now();
        // Insert first, then judge the lock only from rows inserted BEFORE this one (lower id). Parallel guesses
        // each get their own row in arrival order, so request 6 onward sees 5 earlier rows and is locked: there is
        // no gap between "check" and "record" for a burst to slip through. A successful login clears the count
        // below. A locked attempt deletes its own row again, so attempts cannot extend the lock.
        $attemptId = self::recordAttempt($key, $clockNow);
        $locked = $attemptId !== null && self::isLocked($key, $clockNow, $attemptId);
        if ($locked) {
            self::dropAttempt($attemptId);
        }
        // Exactly one bcrypt verify on every path (known user, unknown user, locked username): a fixed
        // dummy hash of the same cost stands in when there is no real hash, so timing reveals nothing.
        $hash = $row !== null ? (string) $row['password_hash'] : self::DUMMY_HASH;
        $passwordOk = password_verify($password, $hash);
        if ($locked || $row === null || !$passwordOk || strcasecmp((string) $row['email'], $email) !== 0
            || (int) $row['active'] !== 1 || (int) $row['location_active'] !== 1) {
            return null;
        }
        self::clearFailures($key);

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

    /** A key for the failed-login counter: sha256 of the lower-cased username, so a typo or secret typed into the username box is never stored. */
    private static function attemptKey(string $username): string
    {
        return hash('sha256', mb_strtolower($username, 'UTF-8'));
    }

    /**
     * True when the 5 most recent EARLIER attempts for this key (rows with an id below $beforeId, so the
     * caller's own row never counts) all fall inside a 15 minute span and the newest is under 15 minutes old:
     * the lock ends 15 minutes after the 5th failure. Locked attempts delete their own row, so they cannot
     * extend the lock.
     */
    private static function isLocked(string $key, \DateTimeImmutable $now, int $beforeId): bool
    {
        try {
            $rows = Db::all(
                'SELECT attempted_at FROM login_attempts WHERE username_key = ? AND id < ? ORDER BY attempted_at DESC, id DESC LIMIT ' . self::LOCK_FAILURES,
                [$key, $beforeId]
            );
        } catch (\PDOException $e) {
            // The table is unreadable: keep logins working, shout in the log.
            error_log('HUB-ALERT login_attempts unavailable, failed-login limit is OFF: ' . $e->getMessage());
            return false;
        }
        if (count($rows) < self::LOCK_FAILURES) {
            return false;
        }
        $newest = (new \DateTimeImmutable((string) $rows[0]['attempted_at'], Clock::tz()))->getTimestamp();
        $oldest = (new \DateTimeImmutable((string) $rows[self::LOCK_FAILURES - 1]['attempted_at'], Clock::tz()))->getTimestamp();
        return ($newest - $oldest) < self::LOCK_SECONDS && $now->getTimestamp() < $newest + self::LOCK_SECONDS;
    }

    /** Records one attempt (counted as a failure until a success clears it). Returns its id, or null when the table is unavailable. */
    private static function recordAttempt(string $key, \DateTimeImmutable $now): ?int
    {
        try {
            $id = (int) Db::insert('INSERT INTO login_attempts (username_key, attempted_at) VALUES (?, ?)', [$key, $now->format('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            // The table is missing (deploy/sql/04-hardening.sql not run yet): keep logins working, shout in the log.
            error_log('HUB-ALERT login_attempts unavailable, failed-login limit is OFF: ' . $e->getMessage());
            return null;
        }
        // Housekeeping is separate from the security decision: if it fails, the attempt above still counts.
        try {
            // Rows older than 30 minutes can never matter again (a lock needs 5 failures inside 15 minutes).
            Db::exec('DELETE FROM login_attempts WHERE attempted_at < ?', [$now->modify('-30 minutes')->format('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            error_log('HUB-ALERT login_attempts cleanup failed: ' . $e->getMessage());
        }
        return $id;
    }

    /** Removes one attempt row (a locked attempt must not extend the lock). */
    private static function dropAttempt(int $id): void
    {
        try {
            Db::exec('DELETE FROM login_attempts WHERE id = ?', [$id]);
        } catch (\PDOException $e) {
            error_log('HUB-ALERT login_attempts unavailable, locked attempt not removed: ' . $e->getMessage());
        }
    }

    private static function clearFailures(string $key): void
    {
        try {
            Db::exec('DELETE FROM login_attempts WHERE username_key = ?', [$key]);
        } catch (\PDOException $e) {
            error_log('HUB-ALERT login_attempts unavailable, failures not cleared: ' . $e->getMessage());
        }
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
