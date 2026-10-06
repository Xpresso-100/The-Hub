<?php
declare(strict_types=1);

// php bin/seed.php --samples   SAMPLE ingredients + SAMPLE recipes (any env; skipped when recipes has rows)
// php bin/seed.php --fixtures  --samples plus the TEST locations and users of SPEC s5.1 (never in production)

require dirname(__DIR__) . '/src/bootstrap.php';

use Hub\Clock;
use Hub\Config;
use Hub\Db;

$args = array_slice($argv, 1);
$fixtures = in_array('--fixtures', $args, true);
$samples = $fixtures || in_array('--samples', $args, true);
if (!$samples) {
    fwrite(STDERR, "Usage: php bin/seed.php --samples | --fixtures\n");
    exit(2);
}
if ($fixtures && Config::isProduction()) {
    fwrite(STDERR, "Refused: --fixtures creates test users and is not allowed when APP_ENV=production.\n");
    exit(1);
}

if ((int) Db::value('SELECT COUNT(*) FROM recipes') > 0) {
    echo "samples: skipped (recipes already present)\n";
} else {
    $sql = file_get_contents(HUB_ROOT . '/db/seed_samples.sql');
    if ($sql === false) {
        fwrite(STDERR, "db/seed_samples.sql is missing\n");
        exit(1);
    }
    Db::transaction(static fn (): int => Db::execScript($sql));
    echo "samples: loaded\n";
}

if ($fixtures) {
    // Test-only logins documented in SPEC s5.1. Not real credentials. Location ids follow this order.
    $rows = [
        ['Xpresso HQ', 'hq', 'hq.admin', 'hq.admin@example.test', 'TestPass!Hq1'],
        ['The Hub', 'hub', 'hub.user', 'hub.user@example.test', 'TestPass!Hub1'],
        ['Hub Two (TEST)', 'hub', 'hub2.user', 'hub2.user@example.test', 'TestPass!Hub2'],
        ['Franchise Store (TEST)', 'franchise', 'franchise.user', 'franchise.user@example.test', 'TestPass!Fr1'],
    ];
    $created = Db::transaction(static function () use ($rows): int {
        $now = Clock::nowDb();
        $created = 0;
        foreach ($rows as [$locationName, $type, $username, $email, $password]) {
            $locationId = Db::value('SELECT id FROM locations WHERE name = ? AND type = ?', [$locationName, $type]);
            if ($locationId === null) {
                $locationId = Db::insert(
                    'INSERT INTO locations (name, type, active, created_at) VALUES (?, ?, 1, ?)',
                    [$locationName, $type, $now]
                );
            }
            if (Db::value('SELECT id FROM users WHERE username = ?', [$username]) === null) {
                Db::insert(
                    'INSERT INTO users (location_id, username, email, password_hash, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
                    [(int) $locationId, $username, $email, password_hash($password, PASSWORD_BCRYPT), $now]
                );
                $created++;
            }
        }
        return $created;
    });
    echo "fixtures: $created user(s) created\n";
}
