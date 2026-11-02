CREATE TABLE IF NOT EXISTS acceptance_checks (
 check_key VARCHAR(64) PRIMARY KEY,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 notes TEXT NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 checked_at DATETIME NOT NULL,
 FOREIGN KEY(actor_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
