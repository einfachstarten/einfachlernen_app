-- Async Calendly Scanner - Database Migration
-- Creates tables for progress tracking and logging

-- Drop tables if they exist (clean start)
DROP TABLE IF EXISTS scan_logs;
DROP TABLE IF EXISTS scan_progress;

-- Scan Progress Tracking
CREATE TABLE scan_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scan_id VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('running', 'completed', 'error') DEFAULT 'running',
    progress INT DEFAULT 0,
    current_step VARCHAR(255),
    events_scanned INT DEFAULT 0,
    emails_found INT DEFAULT 0,
    new_count INT DEFAULT 0,
    existing_count INT DEFAULT 0,
    error_message TEXT,
    started_at DATETIME,
    completed_at DATETIME,
    INDEX idx_scan_id (scan_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scan Logs
CREATE TABLE scan_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scan_id VARCHAR(50) NOT NULL,
    message TEXT,
    created_at DATETIME,
    INDEX idx_scan_id (scan_id),
    FOREIGN KEY (scan_id) REFERENCES scan_progress(scan_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
