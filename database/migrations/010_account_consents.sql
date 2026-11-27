CREATE TABLE account_consents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    terms_version VARCHAR(40) NOT NULL,
    privacy_version VARCHAR(40) NOT NULL,
    accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT account_consents_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT account_consents_user_versions_unique UNIQUE (user_id, terms_version, privacy_version),
    INDEX account_consents_user_accepted (user_id, accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
