<?php
declare(strict_types=1);

// Reads a password from stdin and prints a bcrypt hash, for creating real users by SQL on the host:
//   php bin/hash_password.php < password.txt
// INSERT INTO users (location_id, username, email, password_hash, active, created_at) VALUES (..., '<hash>', 1, NOW());

$password = stream_get_contents(STDIN);
$password = $password === false ? '' : rtrim($password, "\r\n");
if ($password === '') {
    fwrite(STDERR, "Usage: php bin/hash_password.php < file-containing-the-password\n");
    exit(2);
}
echo password_hash($password, PASSWORD_BCRYPT), PHP_EOL;
