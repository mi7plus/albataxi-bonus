CREATE TABLE IF NOT EXISTS risk_settings (
 id INT PRIMARY KEY,
 pair_threshold INT NOT NULL DEFAULT 3,
 frequency_seconds INT NOT NULL DEFAULT 30,
 fare_repeat_threshold INT NOT NULL DEFAULT 3,
 daily_award_bani INT NOT NULL DEFAULT 10000
) ENGINE=InnoDB;
INSERT IGNORE INTO risk_settings(id) VALUES(1);
