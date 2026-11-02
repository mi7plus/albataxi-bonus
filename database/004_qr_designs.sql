CREATE TABLE IF NOT EXISTS qr_designs (
 membership_id BIGINT UNSIGNED NOT NULL,
 orientation VARCHAR(16) NOT NULL,
 design_json TEXT NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(membership_id,orientation),
 FOREIGN KEY(membership_id) REFERENCES memberships(id)
) ENGINE=InnoDB;
