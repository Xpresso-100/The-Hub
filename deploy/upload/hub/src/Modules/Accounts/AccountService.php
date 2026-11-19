<?php
declare(strict_types=1);

namespace Hub\Modules\Accounts;

use Hub\Clock;
use Hub\Db;
use Hub\Http\HttpError;

/**
 * Hub logins (build spec 2026-11-16 s8). Only active hq and hub locations may hold an account: a franchise
 * location is refused here, on the server. The plain password is hashed with bcrypt (as bin/hash_password.php)
 * and is never returned, logged or stored. Personal data held: staff username and email only (POPIA).
 */
final class AccountService
{
    /** Location types that may hold a Hub account. */
    public const ACCOUNT_TYPES = ['hq', 'hub'];

    private const USER_SELECT =
        'SELECT u.id, u.username, u.email, u.active, u.created_at, u.location_id,
                l.name AS location_name, l.type AS location_type
           FROM users u JOIN locations l ON l.id = u.location_id';

    /** Active hq and hub locations: hq first, then by name. */
    public static function accountLocations(): array
    {
        $rows = Db::all(
            "SELECT id, name, type FROM locations WHERE active = 1 AND type IN ('hq', 'hub') ORDER BY type, name, id"
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
        ], $rows);
    }

    /** Every user, ordered by id. */
    public static function listUsers(): array
    {
        return array_map([self::class, 'shape'], Db::all(self::USER_SELECT . ' ORDER BY u.id'));
    }

    /**
     * Validates and inserts a user. 422 with per-field errors; 409 when the username is taken.
     * @return array the new user (shape())
     */
    public static function create(array $body, int $creatorId): array
    {
        $errors = [];

        $username = is_string($body['username'] ?? null) ? trim($body['username']) : null;
        if ($username === null || preg_match('/^[A-Za-z0-9._-]{3,60}$/D', $username) !== 1) {
            $errors['username'] = 'required, 3-60 characters: letters, digits, dot, underscore or hyphen';
        }

        $email = is_string($body['email'] ?? null) ? trim($body['email']) : null;
        if ($email === null || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'required, a valid email address of at most 190 characters';
        }

        // Bytes, not characters: bcrypt ignores everything past 72 bytes, so longer is refused.
        $secret = $body['password'] ?? null;
        if (!is_string($secret) || strlen($secret) < 12 || strlen($secret) > 72
            || trim($secret) === '' || str_contains($secret, "\0")) {
            $errors['password'] = 'required, 12 to 72 bytes';
        }

        $locationId = $body['location_id'] ?? null;
        if (!is_int($locationId)) {
            $errors['location_id'] = 'required, the id of an active hq or hub location';
        } else {
            $location = Db::one('SELECT id, type, active FROM locations WHERE id = ?', [$locationId]);
            if ($location !== null && (string) $location['type'] === 'franchise') {
                $errors['location_id'] = 'franchise locations cannot have Hub accounts';
            } elseif ($location === null || (int) $location['active'] !== 1
                || !in_array((string) $location['type'], self::ACCOUNT_TYPES, true)) {
                $errors['location_id'] = 'must be an active hq or hub location';
            }
        }

        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        if (self::usernameTaken((string) $username)) {
            throw HttpError::conflict('That username is already taken.');
        }

        $hash = password_hash((string) $secret, PASSWORD_BCRYPT);
        $secret = null;
        try {
            $id = Db::insert(
                'INSERT INTO users (location_id, username, email, password_hash, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
                [$locationId, $username, $email, $hash, Clock::nowDb()]
            );
        } catch (\PDOException $e) {
            // Two requests racing for one username: the UNIQUE key decides, the loser gets 409.
            if (self::usernameTaken((string) $username)) {
                throw HttpError::conflict('That username is already taken.');
            }
            throw $e;
        }

        error_log('HUB-AUDIT account_created user_id=' . $id . ' by=' . $creatorId);
        return self::find($id);
    }

    /** Deactivates (and ends every session of) or reactivates a user. 404 unknown; 409 self-deactivate. */
    public static function setActive(int $id, bool $active, int $actorId): array
    {
        if (Db::value('SELECT id FROM users WHERE id = ?', [$id]) === null) {
            throw HttpError::notFound('No such user.');
        }
        if (!$active && $id === $actorId) {
            throw HttpError::conflict('You cannot deactivate your own account.');
        }
        Db::transaction(static function () use ($id, $active): bool {
            Db::exec('UPDATE users SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
            if (!$active) {
                Db::exec('DELETE FROM auth_tokens WHERE user_id = ?', [$id]);
            }
            return true;
        });
        return self::find($id);
    }

    /** One user as the API returns it. Never includes the password hash. */
    public static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'email' => (string) $row['email'],
            'location' => [
                'id' => (int) $row['location_id'],
                'name' => (string) $row['location_name'],
                'type' => (string) $row['location_type'],
            ],
            'active' => (int) $row['active'] === 1,
            'created_at' => Clock::iso($row['created_at'] === null ? null : (string) $row['created_at']),
        ];
    }

    private static function find(int $id): array
    {
        $row = Db::one(self::USER_SELECT . ' WHERE u.id = ?', [$id]);
        if ($row === null) {
            throw HttpError::notFound('No such user.');
        }
        return self::shape($row);
    }

    /** Case-insensitive, so "Staff.One" and "staff.one" cannot both exist (MySQL compares them as equal at login). */
    private static function usernameTaken(string $username): bool
    {
        return (int) Db::value('SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?)', [$username]) > 0;
    }
}
