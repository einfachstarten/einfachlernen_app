-- Migration: Add Scan Progress Tracking Tables
-- Purpose: Enable async Calendly scanning with live progress updates
-- Date: 2026-01-09

-- Scan Progress Tracking Table
CREATE TABLE IF NOT EXISTS scan_progress (
    id INT PRIMARY KEY AUTO_INCREMENT,
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
    INDEX idx_status (status),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scan Logs Table (for real-time log streaming)
CREATE TABLE IF NOT EXISTS scan_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scan_id VARCHAR(50) NOT NULL,
    message TEXT,
    created_at DATETIME,
    INDEX idx_scan_id (scan_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (scan_id) REFERENCES scan_progress(scan_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto-cleanup old scans (keep only last 30 days)
-- Can be set up as a cron job or event scheduler
-- Example event (commented out - enable if needed):
/*
CREATE EVENT IF NOT EXISTS cleanup_old_scans
ON SCHEDULE EVERY 1 DAY
DO
  DELETE FROM scan_progress
  WHERE started_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
*/
