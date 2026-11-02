CREATE TABLE IF NOT EXISTS qr_templates (
 scope VARCHAR(16) NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 orientation VARCHAR(16) NOT NULL,
 approved_json TEXT NULL,
 proposal_json TEXT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'inherited',
 revision INT UNSIGNED NOT NULL DEFAULT 0,
 proposed_by BIGINT UNSIGNED NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 review_note VARCHAR(1000) NOT NULL DEFAULT '',
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(scope,owner_id,orientation)
) ENGINE=InnoDB;
INSERT IGNORE INTO qr_templates(scope,owner_id,orientation,proposal_json,status,revision,updated_at)
SELECT 'driver',membership_id,orientation,design_json,'pending',1,updated_at FROM qr_designs;
