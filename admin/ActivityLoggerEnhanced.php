<?php
/**
 * Enhanced Activity Logger with advanced tracking capabilities
 * Tracks device, browser, OS, referrer, and more for comprehensive analytics
 */
class ActivityLoggerEnhanced {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Log customer activity with enhanced metadata
     * @param int $customer_id
     * @param string $activity_type
     * @param array $activity_data Additional data to track
     * @param string $session_id Optional session ID
     */
    public function logActivity($customer_id, $activity_type, $activity_data = [], $session_id = null) {
        try {
            // Parse user agent for device info
            $user_agent = $this->getUserAgent();
            $device_info = $this->parseUserAgent($user_agent);

            // Enhance activity data with device info
            $enhanced_data = array_merge($activity_data, [
                'device_type' => $device_info['device'],
                'browser' => $device_info['browser'],
                'os' => $device_info['os'],
                'referrer' => $this->getReferrer(),
                'page_url' => $this->getCurrentUrl(),
                'timestamp_ms' => round(microtime(true) * 1000) // For performance tracking
            ]);

            $stmt = $this->pdo->prepare("
                INSERT INTO customer_activities
                (customer_id, activity_type, activity_data, ip_address, user_agent, session_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $customer_id,
                $activity_type,
                json_encode($enhanced_data),
                $this->getClientIP(),
                $user_agent,
                $session_id ?: session_id()
            ]);

            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            error_log("Enhanced activity tracking failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Track session duration when user logs out or times out
     */
    public function logSessionEnd($customer_id, $session_start_time, $reason = 'logout') {
        $session_duration = time() - $session_start_time;
        $duration_minutes = round($session_duration / 60, 1);

        return $this->logActivity($customer_id, 'session_end', [
            'reason' => $reason,
            'duration_seconds' => $session_duration,
            'duration_minutes' => $duration_minutes
        ]);
    }

    /**
     * Track page load performance
     */
    public function logPagePerformance($customer_id, $page_name, $load_time_ms, $performance_data = []) {
        return $this->logActivity($customer_id, 'page_performance', array_merge([
            'page' => $page_name,
            'load_time_ms' => $load_time_ms,
            'rating' => $this->getPerformanceRating($load_time_ms)
        ], $performance_data));
    }

    /**
     * Track errors that occur in the app
     */
    public function logError($customer_id, $error_type, $error_message, $context = []) {
        return $this->logActivity($customer_id, 'error_occurred', array_merge([
            'error_type' => $error_type,
            'error_message' => $error_message,
            'severity' => $context['severity'] ?? 'warning'
        ], $context));
    }

    /**
     * Track feature usage for beta features
     */
    public function logFeatureUsage($customer_id, $feature_name, $action, $metadata = []) {
        return $this->logActivity($customer_id, 'feature_usage', array_merge([
            'feature' => $feature_name,
            'action' => $action
        ], $metadata));
    }

    /**
     * Track email engagement (opens, clicks)
     */
    public function logEmailEngagement($customer_id, $email_type, $action, $email_id = null) {
        return $this->logActivity($customer_id, 'email_engagement', [
            'email_type' => $email_type,
            'action' => $action, // 'sent', 'opened', 'clicked'
            'email_id' => $email_id
        ]);
    }

    /**
     * Track search queries and results
     */
    public function logSearch($customer_id, $search_type, $query, $results_count, $filters = []) {
        return $this->logActivity($customer_id, 'search_performed', [
            'search_type' => $search_type,
            'query' => $query,
            'results_count' => $results_count,
            'filters' => $filters
        ]);
    }

    /**
     * Get customer activity history
     */
    public function getCustomerActivities($customer_id, $limit = 50, $activity_type = null) {
        $limit = (int)$limit;
        if ($limit < 1 || $limit > 1000) {
            $limit = 50;
        }

        $sql = "SELECT * FROM customer_activities WHERE customer_id = ?";
        $params = [$customer_id];

        if ($activity_type) {
            $sql .= " AND activity_type = ?";
            $params[] = $activity_type;
        }

        $sql .= " ORDER BY created_at DESC LIMIT {$limit}";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getCustomerActivities error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get activity statistics for admin
     */
    public function getActivityStats($days = 30) {
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
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getActivityStats error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get most active customers
     */
    public function getTopActiveCustomers($days = 30, $limit = 10) {
        $limit = (int)$limit;
        if ($limit < 1 || $limit > 100) {
            $limit = 10;
        }

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
                LIMIT {$limit}
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getTopActiveCustomers error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get average session duration
     */
    public function getAverageSessionDuration($days = 30) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    AVG(JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.duration_minutes'))) as avg_duration_minutes,
                    COUNT(*) as session_count
                FROM customer_activities
                WHERE activity_type = 'session_end'
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND JSON_EXTRACT(activity_data, '$.duration_minutes') IS NOT NULL
            ");
            $stmt->execute([$days]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return ['avg_duration_minutes' => 0, 'session_count' => 0];
        }
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics($days = 30) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.page')) as page,
                    AVG(JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.load_time_ms'))) as avg_load_time,
                    COUNT(*) as page_loads
                FROM customer_activities
                WHERE activity_type = 'page_performance'
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY page
                ORDER BY page_loads DESC
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get error statistics
     */
    public function getErrorStats($days = 30) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.error_type')) as error_type,
                    COUNT(*) as error_count,
                    COUNT(DISTINCT customer_id) as affected_users
                FROM customer_activities
                WHERE activity_type = 'error_occurred'
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY error_type
                ORDER BY error_count DESC
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get feature adoption metrics
     */
    public function getFeatureAdoption($days = 30) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.feature')) as feature,
                    COUNT(DISTINCT customer_id) as unique_users,
                    COUNT(*) as total_uses
                FROM customer_activities
                WHERE activity_type = 'feature_usage'
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY feature
                ORDER BY unique_users DESC
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get email engagement metrics
     */
    public function getEmailEngagementMetrics($days = 30) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.email_type')) as email_type,
                    JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.action')) as action,
                    COUNT(*) as count
                FROM customer_activities
                WHERE activity_type = 'email_engagement'
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY email_type, action
            ");
            $stmt->execute([$days]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate engagement rates
            $metrics = [];
            foreach ($data as $row) {
                $type = $row['email_type'];
                if (!isset($metrics[$type])) {
                    $metrics[$type] = ['sent' => 0, 'opened' => 0, 'clicked' => 0];
                }
                $metrics[$type][$row['action']] = $row['count'];
            }

            // Calculate rates
            foreach ($metrics as &$metric) {
                $metric['open_rate'] = $metric['sent'] > 0 ? round(($metric['opened'] / $metric['sent']) * 100, 1) : 0;
                $metric['click_rate'] = $metric['opened'] > 0 ? round(($metric['clicked'] / $metric['opened']) * 100, 1) : 0;
            }

            return $metrics;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Clean up old activities (privacy compliance)
     */
    public function cleanupOldActivities($days = 365) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM customer_activities
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            return $stmt->execute([$days]);
        } catch (PDOException $e) {
            error_log("cleanupOldActivities error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Parse User Agent to extract device, browser, and OS information
     */
    private function parseUserAgent($user_agent) {
        $device = 'Desktop';
        $browser = 'Unknown';
        $os = 'Unknown';

        // Detect Device
        if (preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $user_agent)) {
            if (preg_match('/iPad/i', $user_agent)) {
                $device = 'Tablet';
            } else {
                $device = 'Mobile';
            }
        } elseif (preg_match('/Tablet/i', $user_agent)) {
            $device = 'Tablet';
        }

        // Detect Browser
        if (preg_match('/Firefox\/([\d.]+)/i', $user_agent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Edg\/([\d.]+)/i', $user_agent)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\/([\d.]+)/i', $user_agent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\/([\d.]+)/i', $user_agent) && !preg_match('/Chrome/i', $user_agent)) {
            $browser = 'Safari';
        } elseif (preg_match('/MSIE|Trident/i', $user_agent)) {
            $browser = 'IE';
        } elseif (preg_match('/Opera|OPR/i', $user_agent)) {
            $browser = 'Opera';
        }

        // Detect OS
        if (preg_match('/Windows NT 10/i', $user_agent)) {
            $os = 'Windows 10';
        } elseif (preg_match('/Windows NT 11/i', $user_agent)) {
            $os = 'Windows 11';
        } elseif (preg_match('/Windows/i', $user_agent)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X ([\d_]+)/i', $user_agent, $matches)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $user_agent)) {
            $os = 'Linux';
        } elseif (preg_match('/Android ([\d.]+)/i', $user_agent, $matches)) {
            $os = 'Android ' . $matches[1];
        } elseif (preg_match('/iOS|iPhone OS ([\d_]+)|iPad/i', $user_agent)) {
            $os = 'iOS';
        }

        return [
            'device' => $device,
            'browser' => $browser,
            'os' => $os
        ];
    }

    /**
     * Get performance rating based on load time
     */
    private function getPerformanceRating($load_time_ms) {
        if ($load_time_ms < 1000) return 'excellent';
        if ($load_time_ms < 2000) return 'good';
        if ($load_time_ms < 3000) return 'fair';
        return 'poor';
    }

    /**
     * Get client IP address (with proxy support)
     */
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

    /**
     * Get User Agent
     */
    private function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }

    /**
     * Get Referrer URL
     */
    private function getReferrer() {
        return $_SERVER['HTTP_REFERER'] ?? 'direct';
    }

    /**
     * Get current URL
     */
    private function getCurrentUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $protocol . '://' . $host . $uri;
    }
}
?>
