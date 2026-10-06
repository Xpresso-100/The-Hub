<?php
declare(strict_types=1);

// php bin/migrate.php - creates every table for the configured DB_DRIVER. Idempotent (SPEC s2.3).

require dirname(__DIR__) . '/src/bootstrap.php';

use Hub\Db;

$driver = Db::driver();
$file = HUB_ROOT . '/db/schema.' . $driver . '.sql';
$sql = file_get_contents($file);
if ($sql === false) {
    fwrite(STDERR, "Schema file missing: db/schema.$driver.sql\n");
    exit(1);
}
$count = Db::execScript($sql);
echo "migrate: $count statements applied ($driver)\n";
