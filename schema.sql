CREATE DATABASE IF NOT EXISTS pantau_kost
	CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

USE pantau_kost;

CREATE TABLE IF NOT EXISTS transactions (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	transaction_type ENUM('income', 'expense') NOT NULL,
	description VARCHAR(160) NOT NULL,
	amount DECIMAL(15, 2) NOT NULL,
	transaction_date DATE NOT NULL,
	proof_file VARCHAR(80) DEFAULT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	INDEX idx_transactions_date (transaction_date, id),
	INDEX idx_transactions_type_date (transaction_type, transaction_date)
) ENGINE=InnoDB;
