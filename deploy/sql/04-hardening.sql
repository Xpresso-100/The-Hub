-- Hub hardening, wave 1. ADDITIVE ONLY: one new table, nothing existing is altered or dropped.
-- Run this BEFORE `ship hub` (the new login code reads it; if it is missing, logins still work but the
-- failed-login limit is OFF and the server error log shows HUB-ALERT lines).
-- Back up first. phpMyAdmin: run ONE statement at a time (multi-statement submissions fail with #1109).
-- Rollback: DROP TABLE login_attempts;  (nothing else depends on it)
-- POPIA: failed logins only, by username key and time. username_key is a sha256 of the lower-cased STORED
-- username when the account exists, else of the submitted one. It is an UNSALTED hash: it keeps a typed-in
-- secret out of plain sight in the table, but a short or guessable value could be reversed by a dictionary if
-- the database leaked. No IP address, no password, no user id.

-- Statement 1 of 1
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT NOT NULL AUTO_INCREMENT,
  username_key CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_login_attempts_key_time (username_key, attempted_at),
  KEY idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
