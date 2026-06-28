-- Migration 004: MySQL job queue for alert dispatch (Redis not available on Dreamhost)

CREATE TABLE IF NOT EXISTS jobs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue        VARCHAR(50) NOT NULL DEFAULT 'default',
    payload      JSON NOT NULL,
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    status       ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    failed_at    DATETIME NULL,
    error        TEXT NULL,
    INDEX idx_queue_status (queue, status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
