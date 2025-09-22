<?php
function ensureProductionMessagingTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_admin BOOLEAN DEFAULT TRUE,
        to_customer_email VARCHAR(100) NOT NULL,
        message_text TEXT NOT NULL,
        message_type ENUM('info', 'success', 'warning', 'question') DEFAULT 'info',
        expects_response TINYINT(1) DEFAULT 0,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer_email (to_customer_email),
        INDEX idx_unread (is_read, to_customer_email),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_message_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL UNIQUE,
        response ENUM('yes','no') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (message_id) REFERENCES customer_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}
