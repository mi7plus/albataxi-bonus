CREATE TABLE IF NOT EXISTS accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(16) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 verified TINYINT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1, is_admin TINYINT NOT NULL DEFAULT 0,
 session_version INT NOT NULL DEFAULT 1, terms_at DATETIME NOT NULL, marketing TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sessions (
 token_hash CHAR(64) PRIMARY KEY, account_id BIGINT UNSIGNED NULL, csrf CHAR(64) NOT NULL, version INT NOT NULL DEFAULT 1,
 context_json TEXT NOT NULL, expires_at DATETIME NOT NULL, FOREIGN KEY(account_id) REFERENCES accounts(id), INDEX session_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS wallets (
 passenger_id BIGINT UNSIGNED PRIMARY KEY, balance BIGINT NOT NULL DEFAULT 0 CHECK(balance >= 0), FOREIGN KEY(passenger_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS participants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, kind VARCHAR(16) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending', funding_terms TEXT NOT NULL, review_url VARCHAR(2048) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
 INDEX participant_status(status,id), INDEX participant_name(name)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS memberships (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_id BIGINT UNSIGNED NOT NULL, participant_id BIGINT UNSIGNED NOT NULL,
 role VARCHAR(16) NOT NULL, active TINYINT NOT NULL DEFAULT 0, admin_suspended TINYINT NOT NULL DEFAULT 0, accepted_at DATETIME NULL, ended_at DATETIME NULL,
 current_driver BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN role='driver' AND ended_at IS NULL THEN account_id ELSE NULL END) STORED,
 UNIQUE(current_driver), UNIQUE(account_id,participant_id,role), UNIQUE(id,participant_id),
 FOREIGN KEY(account_id) REFERENCES accounts(id), FOREIGN KEY(participant_id) REFERENCES participants(id), INDEX participant_members(participant_id,role,active)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS vehicles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, membership_id BIGINT UNSIGNED NOT NULL UNIQUE, participant_id BIGINT UNSIGNED NOT NULL,
 plate VARCHAR(20) NOT NULL, eligible TINYINT NOT NULL DEFAULT 0, FOREIGN KEY(membership_id,participant_id) REFERENCES memberships(id,participant_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS rules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, participant_id BIGINT UNSIGNED NOT NULL,
 earn_bps INT NOT NULL CHECK(earn_bps BETWEEN 0 AND 10000), cap_bps INT NOT NULL CHECK(cap_bps BETWEEN 0 AND 10000),
 contribution_bps INT NOT NULL CHECK(contribution_bps BETWEEN 0 AND 10000), daily_limit INT NOT NULL CHECK(daily_limit>0), max_fare INT NOT NULL CHECK(max_fare>0),
 actor_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(participant_id) REFERENCES participants(id),
 FOREIGN KEY(actor_id) REFERENCES accounts(id), INDEX participant_rules(participant_id,id), UNIQUE(id,participant_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS programme (id INT PRIMARY KEY, earning_paused TINYINT NOT NULL DEFAULT 0, redemption_paused TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB;
INSERT IGNORE INTO programme(id) VALUES(1);
CREATE TABLE IF NOT EXISTS qr_pages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, membership_id BIGINT UNSIGNED NOT NULL UNIQUE, token VARCHAR(64) NOT NULL UNIQUE, display_token VARCHAR(64) NOT NULL UNIQUE,
 FOREIGN KEY(membership_id) REFERENCES memberships(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS passenger_requests (
 id CHAR(32) PRIMARY KEY, passenger_id BIGINT UNSIGNED NOT NULL, driver_id BIGINT UNSIGNED NOT NULL, code VARCHAR(12) NOT NULL UNIQUE,
 status VARCHAR(16) NOT NULL DEFAULT 'ready', created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
 FOREIGN KEY(passenger_id) REFERENCES accounts(id), FOREIGN KEY(driver_id) REFERENCES memberships(id), INDEX driver_queue(driver_id,status,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS rides (
 id CHAR(32) PRIMARY KEY, request_id CHAR(32) NOT NULL UNIQUE, passenger_id BIGINT UNSIGNED NOT NULL, driver_id BIGINT UNSIGNED NOT NULL,
 participant_id BIGINT UNSIGNED NOT NULL, vehicle_plate VARCHAR(20) NOT NULL, rule_id BIGINT UNSIGNED NOT NULL, fare INT NOT NULL CHECK(fare>0),
 redeemed INT NOT NULL DEFAULT 0 CHECK(redeemed>=0), earned INT NOT NULL DEFAULT 0, contribution INT NOT NULL DEFAULT 0,
 status VARCHAR(24) NOT NULL DEFAULT 'draft', approval VARCHAR(16) NOT NULL DEFAULT 'pending', approval_balance BIGINT NULL,
 expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, posted_at DATETIME NULL, CHECK(redeemed<=fare),
 FOREIGN KEY(request_id) REFERENCES passenger_requests(id), FOREIGN KEY(passenger_id) REFERENCES accounts(id),
 FOREIGN KEY(driver_id,participant_id) REFERENCES memberships(id,participant_id), FOREIGN KEY(rule_id,participant_id) REFERENCES rules(id,participant_id),
 INDEX participant_date(participant_id,posted_at), INDEX driver_status(driver_id,status), INDEX passenger_date(passenger_id,posted_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, passenger_id BIGINT UNSIGNED NOT NULL, ride_id CHAR(32) NOT NULL, kind VARCHAR(24) NOT NULL,
 amount BIGINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE(ride_id,kind), FOREIGN KEY(passenger_id) REFERENCES accounts(id),
 FOREIGN KEY(ride_id) REFERENCES rides(id), INDEX passenger_ledger(passenger_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fund_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, participant_id BIGINT UNSIGNED NOT NULL, ride_id CHAR(32) NULL, kind VARCHAR(32) NOT NULL, amount BIGINT NOT NULL,
 reference VARCHAR(255) NOT NULL DEFAULT '', settlement_id BIGINT UNSIGNED NULL, actor_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(participant_id) REFERENCES participants(id), FOREIGN KEY(ride_id) REFERENCES rides(id), FOREIGN KEY(actor_id) REFERENCES accounts(id),
 INDEX fund_participant_date(participant_id,created_at), INDEX fund_settlement(settlement_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS operations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(32) NOT NULL, operation_key VARCHAR(100) NOT NULL,
 fingerprint CHAR(64) NOT NULL, result TEXT NOT NULL, UNIQUE(actor_id,kind,operation_key), FOREIGN KEY(actor_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS corrections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ride_id CHAR(32) NOT NULL UNIQUE, reason TEXT NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(24) NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY(ride_id) REFERENCES rides(id), FOREIGN KEY(actor_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS scan_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, driver_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(32) NOT NULL, payload TEXT NOT NULL,
 created_at DATETIME NOT NULL, FOREIGN KEY(driver_id) REFERENCES memberships(id), INDEX driver_events(driver_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id BIGINT UNSIGNED NULL, action VARCHAR(64) NOT NULL, target VARCHAR(100) NOT NULL, detail TEXT NOT NULL,
 created_at DATETIME NOT NULL, FOREIGN KEY(actor_id) REFERENCES accounts(id), INDEX audit_date(created_at,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS otps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(16) NOT NULL, purpose VARCHAR(16) NOT NULL, digest VARCHAR(255) NOT NULL,
 expires_at DATETIME NOT NULL, attempts INT NOT NULL DEFAULT 0, used TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, INDEX otp_lookup(phone,purpose,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS dev_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(16) NOT NULL, message TEXT NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS throttles (throttle_key CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, started_at DATETIME NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS invitations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token VARCHAR(64) NOT NULL UNIQUE, participant_id BIGINT UNSIGNED NOT NULL, phone VARCHAR(16) NOT NULL,
 role VARCHAR(16) NOT NULL, plate VARCHAR(20) NOT NULL DEFAULT '', status VARCHAR(16) NOT NULL DEFAULT 'pending', expires_at DATETIME NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(participant_id) REFERENCES participants(id), FOREIGN KEY(actor_id) REFERENCES accounts(id), INDEX invitation_lookup(participant_id,phone,status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS imports (
 id CHAR(32) PRIMARY KEY, actor_id BIGINT UNSIGNED NOT NULL, participant_id BIGINT UNSIGNED NOT NULL, rows_json MEDIUMTEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'preview', created_at DATETIME NOT NULL, FOREIGN KEY(actor_id) REFERENCES accounts(id), FOREIGN KEY(participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS presets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, owner_id BIGINT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL, filters TEXT NOT NULL, FOREIGN KEY(owner_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS settlements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, participant_id BIGINT UNSIGNED NOT NULL, period_end DATE NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'draft',
 snapshot TEXT NOT NULL, paid BIGINT NOT NULL DEFAULT 0, reference VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
 FOREIGN KEY(participant_id) REFERENCES participants(id), INDEX settlement_participant(participant_id,period_end)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS support_cases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, owner_id BIGINT UNSIGNED NOT NULL, ride_id CHAR(32) NULL, message TEXT NOT NULL,
 resolution TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL,
 FOREIGN KEY(owner_id) REFERENCES accounts(id), FOREIGN KEY(ride_id) REFERENCES rides(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS risk_flags (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ride_id CHAR(32) NOT NULL, reason VARCHAR(255) NOT NULL, resolved TINYINT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL, FOREIGN KEY(ride_id) REFERENCES rides(id)
) ENGINE=InnoDB;
