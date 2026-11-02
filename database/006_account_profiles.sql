CREATE TABLE IF NOT EXISTS account_profiles (
 account_id BIGINT UNSIGNED PRIMARY KEY,
 full_name VARCHAR(160) NULL,
 date_of_birth DATE NULL,
 FOREIGN KEY(account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
