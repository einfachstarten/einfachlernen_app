<?php
class ActivityLogger {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Log customer activity
     * @param int $customer_id
     * @param string $activity_type
     * @param array $activity_data
     * @param string $session_id
     */
    public function logActivity($customer_id, $activity_type, $activity_data = [], $session_id = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO customer_activities 
                (customer_id, activity_type, activity_data, ip_address, user_agent, session_id) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $customer_id,
                $activity_type,
                json_encode($activity_data),
                $this->getClientIP(),
                $this->getUserAgent(),
                $session_id ?: session_id()
            ]);
            
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            // Log error but don't break user experience
            error_log("Activity tracking failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get customer activity history
     */
    public function getCustomerActivities($customer_id, $limit = 50, $activity_type = null) {
        $sql = "SELECT * FROM customer_activities WHERE customer_id = ?";
        $params = [$customer_id];
        
        if ($activity_type) {
            $sql .= " AND activity_type = ?";
            $params[] = $activity_type;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get activity statistics for admin
     */
    public function getActivityStats($days = 30) {
        echo "<p>Debug: Getting activity stats for $days days</p>";

        try {
            $stmt = $this->pdo->prepare("
            SELECT
                activity_type,
                COUNT(*) as count,
                COUNT(DISTINCT customer_id) as unique_customers,
                DATE(created_at) as activity_date
            FROM customer_activities
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY activity_type, DATE(created_at)
            ORDER BY activity_date DESC, count DESC
        ");

            $stmt->execute([$days]);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo "<p>Debug: Found " . count($result) . " activity records</p>";
            return $result;

        } catch (PDOException $e) {
            echo "<p style='color:red'>SQL Error in getActivityStats: " . htmlspecialchars($e->getMessage()) . "</p>";
            return [];
        }
    }
    
    /**
     * Get most active customers
     */
    public function getTopActiveCustomers($days = 30, $limit = 10) {
        echo "<p>Debug: Getting top active customers for $days days</p>";

        try {
            $stmt = $this->pdo->prepare("
            SELECT
                c.email,
                c.first_name,
                c.last_name,
                COUNT(ca.id) as activity_count,
                MAX(ca.created_at) as last_activity
            FROM customers c
            LEFT JOIN customer_activities ca ON c.id = ca.customer_id
            WHERE ca.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY c.id, c.email, c.first_name, c.last_name
            ORDER BY activity_count DESC
            LIMIT ?
        ");

            $stmt->execute([$days, $limit]);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo "<p>Debug: Found " . count($result) . " active customers</p>";
            return $result;

        } catch (PDOException $e) {
            echo "<p style='color:red'>SQL Error in getTopActiveCustomers: " . htmlspecialchars($e->getMessage()) . "</p>";
            return [];
        }
    }
    
    /**
     * Clean up old activities (privacy compliance)
     */
    public function cleanupOldActivities($days = 365) {
        $stmt = $this->pdo->prepare("
            DELETE FROM customer_activities 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        
        return $stmt->execute([$days]);
    }
    
    private function getClientIP() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    private function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
}
?>
