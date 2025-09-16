<?php
// admin/check-analytics.php - COMPREHENSIVE DIAGNOSTIC
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

function getPDO() {
    $config = require __DIR__ . '/config.php';
    try {
        return new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Analytics Diagnostic Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; }
        .success { color: #28a745; background: #d4edda; padding: 0.5rem; border-radius: 4px; margin: 0.5rem 0; }
        .error { color: #dc3545; background: #f8d7da; padding: 0.5rem; border-radius: 4px; margin: 0.5rem 0; }
        .warning { color: #856404; background: #fff3cd; padding: 0.5rem; border-radius: 4px; margin: 0.5rem 0; }
        .info { color: #0c5460; background: #d1ecf1; padding: 0.5rem; border-radius: 4px; margin: 0.5rem 0; }
        .section { border: 1px solid #ddd; padding: 1rem; margin: 1rem 0; border-radius: 8px; }
        pre { background: #f8f9fa; padding: 1rem; border-radius: 4px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { border: 1px solid #ddd; padding: 0.5rem; text-align: left; }
        th { background: #f8f9fa; }
    </style>
</head>
<body>

<h1>🔍 Analytics Diagnostic Report</h1>
<p><strong>Timestamp:</strong> <?= date('Y-m-d H:i:s') ?></p>

<?php
try {
    $pdo = getPDO();
    echo "<div class='success'>✅ Database connection successful</div>";
} catch (Exception $e) {
    echo "<div class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}
?>

<!-- 1. TABLE STRUCTURE CHECK -->
<div class="section">
    <h2>1. 📋 Table Structure Check</h2>
    
    <?php
    // Check if core tables exist
    $tables_to_check = ['customers', 'customer_activities', 'customer_sessions', 'bookings', 'services'];
    
    foreach ($tables_to_check as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "<div class='success'>✅ Table '$table' exists</div>";
                
                // Show table structure
                $stmt = $pdo->query("DESCRIBE $table");
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo "<details><summary>Show $table structure (" . count($columns) . " columns)</summary>";
                echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                foreach ($columns as $col) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
                    echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
                    echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
                    echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
                    echo "<td>" . htmlspecialchars($col['Default']) . "</td>";
                    echo "</tr>";
                }
                echo "</table></details>";
                
                // Show row count
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                $count = $stmt->fetch();
                echo "<div class='info'>📊 Rows in $table: " . $count['count'] . "</div>";
                
            } else {
                echo "<div class='error'>❌ Table '$table' does NOT exist</div>";
            }
        } catch (PDOException $e) {
            echo "<div class='error'>❌ Error checking table '$table': " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    ?>
</div>

<!-- 2. VIEWS CHECK -->
<div class="section">
    <h2>2. 👁️ Views Check</h2>
    
    <?php
    try {
        $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        $views = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($views)) {
            echo "<div class='info'>ℹ️ No views found in database</div>";
        } else {
            echo "<div class='info'>📊 Found " . count($views) . " views:</div>";
            foreach ($views as $view) {
                $view_name = $view['Tables_in_' . $pdo->query("SELECT DATABASE()")->fetchColumn()];
                echo "<div class='success'>✅ View: $view_name</div>";
                
                // Test if view is accessible
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$view_name` LIMIT 1");
                    $result = $stmt->fetch();
                    echo "<div class='success'>   ✅ View is accessible, test count: " . $result['count'] . "</div>";
                } catch (PDOException $e) {
                    echo "<div class='error'>   ❌ View access failed: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }
        
        // Check for specific views that migration tried to create
        $expected_views = ['service_performance_monthly', 'weekly_capacity'];
        foreach ($expected_views as $view_name) {
            try {
                $stmt = $pdo->query("SHOW CREATE VIEW `$view_name`");
                echo "<div class='success'>✅ Expected view '$view_name' exists</div>";
            } catch (PDOException $e) {
                echo "<div class='warning'>⚠️ Expected view '$view_name' missing: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
        
    } catch (PDOException $e) {
        echo "<div class='error'>❌ Error checking views: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>
</div>

<!-- 3. INDEXES CHECK -->
<div class="section">
    <h2>3. 🔍 Indexes Check</h2>
    
    <?php
    if (in_array('customer_activities', array_map(function($t) use ($pdo) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$t'");
        return $stmt->rowCount() > 0 ? $t : null;
    }, ['customer_activities']))) {
        
        try {
            $stmt = $pdo->query("SHOW INDEXES FROM customer_activities");
            $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<div class='info'>📊 customer_activities has " . count($indexes) . " indexes:</div>";
            echo "<table><tr><th>Key Name</th><th>Column</th><th>Unique</th><th>Seq</th></tr>";
            foreach ($indexes as $idx) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($idx['Key_name']) . "</td>";
                echo "<td>" . htmlspecialchars($idx['Column_name']) . "</td>";
                echo "<td>" . ($idx['Non_unique'] ? 'No' : 'Yes') . "</td>";
                echo "<td>" . htmlspecialchars($idx['Seq_in_index']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            
        } catch (PDOException $e) {
            echo "<div class='error'>❌ Error checking indexes: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    ?>
</div>

<!-- 4. FILE EXISTENCE CHECK -->
<div class="section">
    <h2>4. 📁 File Existence Check</h2>
    
    <?php
    $files_to_check = [
        'analytics.php',
        'ActivityLogger.php',
        'migrate.php',
        'config.php'
    ];
    
    foreach ($files_to_check as $file) {
        $path = __DIR__ . '/' . $file;
        if (file_exists($path)) {
            echo "<div class='success'>✅ File '$file' exists (" . number_format(filesize($path)) . " bytes)</div>";
            
            if ($file === 'analytics.php') {
                // Check if analytics.php has syntax errors
                $output = [];
                $return_var = 0;
                exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $return_var);
                if ($return_var === 0) {
                    echo "<div class='success'>   ✅ analytics.php syntax OK</div>";
                } else {
                    echo "<div class='error'>   ❌ analytics.php syntax error: " . implode("\n", $output) . "</div>";
                }
            }
        } else {
            echo "<div class='error'>❌ File '$file' missing</div>";
        }
    }
    ?>
</div>

<!-- 5. ACTIVITYLOGGER TEST -->
<div class="section">
    <h2>5. 📊 ActivityLogger Test</h2>
    
    <?php
    if (file_exists(__DIR__ . '/ActivityLogger.php')) {
        try {
            require_once 'ActivityLogger.php';
            $logger = new ActivityLogger($pdo);
            echo "<div class='success'>✅ ActivityLogger class loaded successfully</div>";
            
            // Test basic methods
            try {
                $stats = $logger->getActivityStats(30);
                echo "<div class='success'>✅ getActivityStats() works: " . json_encode($stats) . "</div>";
            } catch (Exception $e) {
                echo "<div class='error'>❌ getActivityStats() failed: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
            
            try {
                $customers = $logger->getTopActiveCustomers(30);
                echo "<div class='success'>✅ getTopActiveCustomers() works: " . count($customers) . " customers found</div>";
            } catch (Exception $e) {
                echo "<div class='error'>❌ getTopActiveCustomers() failed: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
            
        } catch (Exception $e) {
            echo "<div class='error'>❌ ActivityLogger loading failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div class='error'>❌ ActivityLogger.php file missing</div>";
    }
    ?>
</div>

<!-- 6. ANALYTICS FUNCTIONS TEST -->
<div class="section">
    <h2>6. 🧪 Analytics Functions Test</h2>
    
    <?php
    // Test the exact functions that analytics.php uses
    
    // Test getKPIMetrics function
    echo "<h3>Testing getKPIMetrics function:</h3>";
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT customer_id) as active_users,
                COUNT(CASE WHEN activity_type = 'login' THEN 1 END) as total_logins,
                COUNT(CASE WHEN activity_type = 'slots_found' THEN 1 END) as slot_searches,
                COUNT(CASE WHEN activity_type = 'booking_initiated' THEN 1 END) as booking_attempts,
                COUNT(CASE WHEN activity_type = 'booking_completed' THEN 1 END) as bookings_completed,
                COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_activities
            FROM customer_activities 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([30]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<div class='success'>✅ getKPIMetrics query works: " . json_encode($result) . "</div>";
    } catch (PDOException $e) {
        echo "<div class='error'>❌ getKPIMetrics query failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    // Test getActivityTrends function
    echo "<h3>Testing getActivityTrends function:</h3>";
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total_activities,
                COUNT(CASE WHEN activity_type IN ('login', 'logout', 'pin_request') THEN 1 END) as auth_activities,
                COUNT(CASE WHEN activity_type IN ('slots_api_called', 'booking_initiated', 'booking_completed') THEN 1 END) as booking_activities,
                COUNT(CASE WHEN activity_type IN ('dashboard_accessed', 'page_view') THEN 1 END) as navigation_activities
            FROM customer_activities 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
            LIMIT 5
        ");
        $stmt->execute([30]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<div class='success'>✅ getActivityTrends query works: " . count($result) . " rows returned</div>";
        if (!empty($result)) {
            echo "<pre>" . print_r(array_slice($result, 0, 3), true) . "</pre>";
        }
    } catch (PDOException $e) {
        echo "<div class='error'>❌ getActivityTrends query failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
    // Test Service Performance functions (these might fail)
    echo "<h3>Testing Service Performance functions:</h3>";
    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.service_name,
                COUNT(b.id) as booking_count,
                SUM(s.price) as revenue
            FROM services s
            LEFT JOIN bookings b ON s.id = b.service_id 
            GROUP BY s.id, s.service_name
            LIMIT 3
        ");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<div class='success'>✅ Service Performance query works: " . count($result) . " services found</div>";
        if (!empty($result)) {
            echo "<pre>" . print_r($result, true) . "</pre>";
        }
    } catch (PDOException $e) {
        echo "<div class='warning'>⚠️ Service Performance query failed (expected if tables missing): " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>
</div>

<!-- 7. ERROR LOG CHECK -->
<div class="section">
    <h2>7. 📝 Error Log Check</h2>
    
    <?php
    $error_log_paths = [
        ini_get('error_log'),
        '/var/log/apache2/error.log',
        '/var/log/nginx/error.log',
        __DIR__ . '/error.log',
        __DIR__ . '/../error.log'
    ];
    
    foreach ($error_log_paths as $log_path) {
        if ($log_path && file_exists($log_path) && is_readable($log_path)) {
            echo "<div class='info'>📄 Found error log: $log_path</div>";
            
            // Get last 10 lines related to analytics
            $lines = file($log_path);
            $relevant_lines = [];
            
            foreach (array_reverse(array_slice($lines, -100)) as $line) {
                if (stripos($line, 'analytics') !== false || 
                    stripos($line, 'customer_activities') !== false ||
                    stripos($line, 'ActivityLogger') !== false) {
                    $relevant_lines[] = trim($line);
                    if (count($relevant_lines) >= 5) break;
                }
            }
            
            if (!empty($relevant_lines)) {
                echo "<div class='warning'>⚠️ Recent analytics-related errors:</div>";
                echo "<pre>" . implode("\n", array_reverse($relevant_lines)) . "</pre>";
            } else {
                echo "<div class='success'>✅ No recent analytics-related errors in this log</div>";
            }
            break;
        }
    }
    ?>
</div>

<!-- 8. ANALYTICS.PHP DIRECT TEST -->
<div class="section">
    <h2>8. 🎯 Analytics.php Direct Test</h2>
    
    <?php
    echo "<div class='info'>ℹ️ Attempting to include analytics.php functions only...</div>";
    
    // Capture any output/errors from analytics.php
    ob_start();
    $error_occurred = false;
    
    try {
        // We'll try to execute just the function definitions from analytics.php
        // without running the full page
        $analytics_content = file_get_contents(__DIR__ . '/analytics.php');
        
        if ($analytics_content === false) {
            echo "<div class='error'>❌ Cannot read analytics.php file</div>";
        } else {
            echo "<div class='success'>✅ analytics.php file readable (" . number_format(strlen($analytics_content)) . " bytes)</div>";
            
            // Check for potential issues in the code
            if (strpos($analytics_content, 'service_performance_monthly') !== false) {
                echo "<div class='warning'>⚠️ analytics.php references 'service_performance_monthly' view</div>";
            }
            if (strpos($analytics_content, 'weekly_capacity') !== false) {
                echo "<div class='warning'>⚠️ analytics.php references 'weekly_capacity' view</div>";
            }
            if (strpos($analytics_content, 'getServicePerformance') !== false) {
                echo "<div class='warning'>⚠️ analytics.php calls 'getServicePerformance' function</div>";
            }
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error analyzing analytics.php: " . htmlspecialchars($e->getMessage()) . "</div>";
        $error_occurred = true;
    }
    
    $output = ob_get_clean();
    if (!empty($output)) {
        echo "<div class='info'>📤 Output from test: <pre>$output</pre></div>";
    }
    ?>
</div>

<!-- 9. SUMMARY & RECOMMENDATIONS -->
<div class="section">
    <h2>9. 📋 Summary & Recommendations</h2>
    
    <?php
    echo "<h3>🔍 Diagnosis Summary:</h3>";
    
    // Check what we found
    $has_customer_activities = false;
    $has_activity_logger = file_exists(__DIR__ . '/ActivityLogger.php');
    $has_bookings_services = false;
    $has_failed_views = false;
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'customer_activities'");
        $has_customer_activities = $stmt->rowCount() > 0;
        
        $stmt = $pdo->query("SHOW TABLES LIKE 'bookings'");
        $has_bookings = $stmt->rowCount() > 0;
        $stmt = $pdo->query("SHOW TABLES LIKE 'services'");
        $has_services = $stmt->rowCount() > 0;
        $has_bookings_services = $has_bookings && $has_services;
        
        try {
            $pdo->query("SELECT 1 FROM service_performance_monthly LIMIT 1");
        } catch (PDOException $e) {
            $has_failed_views = true;
        }
        
    } catch (Exception $e) {
        // Error checking
    }
    
    echo "<ul>";
    echo "<li>Customer Activities Table: " . ($has_customer_activities ? "✅ EXISTS" : "❌ MISSING") . "</li>";
    echo "<li>ActivityLogger Class: " . ($has_activity_logger ? "✅ EXISTS" : "❌ MISSING") . "</li>";
    echo "<li>Bookings/Services Tables: " . ($has_bookings_services ? "✅ EXISTS" : "❌ MISSING") . "</li>";
    echo "<li>Failed Views: " . ($has_failed_views ? "⚠️ DETECTED" : "✅ OK") . "</li>";
    echo "</ul>";
    
    echo "<h3>💡 Recommended Actions:</h3>";
    
    if ($has_customer_activities && $has_activity_logger && $has_failed_views) {
        echo "<div class='warning'>";
        echo "<strong>LIKELY ISSUE:</strong> Migration created broken views referencing missing tables.<br>";
        echo "<strong>SIMPLE FIX:</strong> Drop the failed views and disable Service Performance features in analytics.php<br>";
        echo "<strong>IMPACT:</strong> Basic analytics will work, Service Performance disabled until tables exist.";
        echo "</div>";
        
        echo "<h4>🔧 Quick Fix Commands:</h4>";
        echo "<pre>";
        echo "-- Run these SQL commands:\n";
        echo "DROP VIEW IF EXISTS service_performance_monthly;\n";
        echo "DROP VIEW IF EXISTS weekly_capacity;\n";
        echo "\n-- Then comment out getServicePerformance function calls in analytics.php";
        echo "</pre>";
    } 
    
    if (!$has_customer_activities) {
        echo "<div class='error'>";
        echo "<strong>CRITICAL:</strong> customer_activities table missing - this is the core issue.<br>";
        echo "<strong>FIX:</strong> Run migration again or create table manually.";
        echo "</div>";
    }
    
    if (!$has_activity_logger) {
        echo "<div class='error'>";
        echo "<strong>CRITICAL:</strong> ActivityLogger.php missing - restore from backup.";
        echo "</div>";
    }
    ?>
    
    <h3>🚀 Next Steps:</h3>
    <ol>
        <li><strong>If quick fix applies:</strong> Drop failed views, comment out Service Performance code</li>
        <li><strong>Test analytics.php:</strong> Visit /admin/analytics.php after fix</li>
        <li><strong>If still broken:</strong> Share this diagnostic output for deeper analysis</li>
        <li><strong>For full features:</strong> Create bookings/services tables later</li>
    </ol>
</div>

<p><a href="dashboard.php" style="background:#4a90b8;color:white;padding:0.5rem 1rem;text-decoration:none;border-radius:4px;">← Back to Dashboard</a></p>

</body>
</html>