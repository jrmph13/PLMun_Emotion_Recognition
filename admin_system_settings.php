<?php
require_once 'config.php';
requireAdmin();

// Helper function to get user initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Helper function to generate CSRF token
function csrfField() {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

// Helper function to validate CSRF token
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $message = 'Invalid CSRF token. Please try again.';
        $message_type = 'error';
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Update system settings - Only include required fields
            $settings = [
                // 1. Webcam Access Controls
                'enable_webcam_access' => isset($_POST['enable_webcam_access']) ? 1 : 0,
                'webcam_resolution' => isset($_POST['webcam_resolution']) ? $_POST['webcam_resolution'] : '720p',
                'require_consent' => isset($_POST['require_consent']) ? 1 : 0,
                
                // 2. Emotion Detection Settings
                'enable_emotion_detection' => isset($_POST['enable_emotion_detection']) ? 1 : 0,
                'detect_happy' => isset($_POST['detect_happy']) ? 1 : 0,
                'detect_sad' => isset($_POST['detect_sad']) ? 1 : 0,
                'detect_neutral' => isset($_POST['detect_neutral']) ? 1 : 0,
                'detect_angry' => isset($_POST['detect_angry']) ? 1 : 0,
                'detect_confused' => isset($_POST['detect_confused']) ? 1 : 0,
                'alert_threshold' => isset($_POST['alert_threshold']) ? intval($_POST['alert_threshold']) : 50,
                
                // 3. Dashboard Settings
                'show_engagement_chart' => isset($_POST['show_engagement_chart']) ? 1 : 0,
                'show_emotion_chart' => isset($_POST['show_emotion_chart']) ? 1 : 0,
                'show_attendance_chart' => isset($_POST['show_attendance_chart']) ? 1 : 0,
                'update_frequency' => isset($_POST['update_frequency']) ? intval($_POST['update_frequency']) : 30,
                
                // 4. Report Generation Settings
                'enable_auto_reports' => isset($_POST['enable_auto_reports']) ? 1 : 0,
                'report_format_pdf' => isset($_POST['report_format_pdf']) ? 1 : 0,
                'report_format_csv' => isset($_POST['report_format_csv']) ? 1 : 0,
                
                // 5. Privacy & Security Settings
                'no_video_storage' => isset($_POST['no_video_storage']) ? 1 : 0,
                'no_audio_storage' => isset($_POST['no_audio_storage']) ? 1 : 0,
                'local_processing' => isset($_POST['local_processing']) ? 1 : 0,
                'data_anonymization' => isset($_POST['data_anonymization']) ? 1 : 0,
            ];
            
            // Check if system_settings table exists with correct structure
            $tableExists = $pdo->query("SHOW TABLES LIKE 'system_settings'")->rowCount() > 0;
            
            if (!$tableExists) {
                // Create system_settings table if it doesn't exist
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS system_settings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        setting_key VARCHAR(100) UNIQUE NOT NULL,
                        setting_value TEXT,
                        setting_type VARCHAR(50) DEFAULT 'system',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_setting_key (setting_key),
                        INDEX idx_setting_type (setting_type)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            // Also create the history table for audit purposes
            $historyTableExists = $pdo->query("SHOW TABLES LIKE 'system_settings_history'")->rowCount() > 0;
            
            if (!$historyTableExists) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS system_settings_history (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        setting_key VARCHAR(100) NOT NULL,
                        old_value TEXT,
                        new_value TEXT,
                        changed_by INT NOT NULL,
                        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        reason VARCHAR(255),
                        INDEX idx_setting_key (setting_key),
                        INDEX idx_changed_at (changed_at),
                        INDEX idx_changed_by (changed_by)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            // Get current values for history logging
            $currentValues = [];
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key = ?");
            
            // Insert or update each setting
            foreach ($settings as $key => $value) {
                // Get old value for history
                $stmt->execute([$key]);
                $oldValue = $stmt->fetch(PDO::FETCH_ASSOC);
                $currentValues[$key] = $oldValue ? $oldValue['setting_value'] : null;
                
                // Insert or update setting
                $stmt2 = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, setting_type) 
                    VALUES (?, ?, 'system')
                    ON DUPLICATE KEY UPDATE 
                    setting_value = VALUES(setting_value),
                    updated_at = CURRENT_TIMESTAMP
                ");
                $stmt2->execute([$key, $value]);
                
                // Log to history if value changed
                if ($currentValues[$key] != $value) {
                    $stmt3 = $pdo->prepare("
                        INSERT INTO system_settings_history 
                        (setting_key, old_value, new_value, changed_by, reason) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt3->execute([
                        $key,
                        $currentValues[$key],
                        $value,
                        $_SESSION['user_id'],
                        'Updated via admin panel'
                    ]);
                }
            }
            
            $pdo->commit();
            $message = 'System settings updated successfully!';
            $message_type = 'success';
            
            // Clear CSRF token after successful submission
            unset($_SESSION['csrf_token']);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = 'Error updating settings: ' . $e->getMessage();
            $message_type = 'error';
            error_log("System settings error: " . $e->getMessage());
        }
    }
}

// Get current settings
$current_settings = [
    // 1. Webcam Access Controls
    'enable_webcam_access' => 1,
    'webcam_resolution' => '720p',
    'require_consent' => 1,
    
    // 2. Emotion Detection Settings
    'enable_emotion_detection' => 1,
    'detect_happy' => 1,
    'detect_sad' => 1,
    'detect_neutral' => 1,
    'detect_angry' => 1,
    'detect_confused' => 1,
    'alert_threshold' => 50,
    
    // 3. Dashboard Settings
    'show_engagement_chart' => 1,
    'show_emotion_chart' => 1,
    'show_attendance_chart' => 1,
    'update_frequency' => 30,
    
    // 4. Report Generation Settings
    'enable_auto_reports' => 1,
    'report_format_pdf' => 1,
    'report_format_csv' => 0,
    
    // 5. Privacy & Security Settings
    'no_video_storage' => 1,
    'no_audio_storage' => 1,
    'local_processing' => 1,
    'data_anonymization' => 1
];

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $db_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if ($db_settings) {
        foreach ($current_settings as $key => $value) {
            if (isset($db_settings[$key])) {
                // Convert string values to appropriate types
                if (in_array($key, ['enable_webcam_access', 'require_consent', 'enable_emotion_detection',
                    'detect_happy', 'detect_sad', 'detect_neutral', 'detect_angry', 'detect_confused',
                    'show_engagement_chart', 'show_emotion_chart', 'show_attendance_chart',
                    'enable_auto_reports', 'report_format_pdf', 'report_format_csv',
                    'no_video_storage', 'no_audio_storage', 'local_processing', 'data_anonymization'])) {
                    $current_settings[$key] = (int)$db_settings[$key];
                } elseif (in_array($key, ['alert_threshold', 'update_frequency'])) {
                    $current_settings[$key] = (int)$db_settings[$key];
                } else {
                    $current_settings[$key] = $db_settings[$key];
                }
            }
        }
    }
} catch (PDOException $e) {
    // Table might not exist yet, that's okay
    error_log("Settings fetch error: " . $e->getMessage());
}

// Get system statistics
$system_stats = [
    'total_users' => 0,
    'active_sessions' => 0,
    'total_classes' => 0,
    'total_emotion_records' => 0
];

try {
    // Check if users table exists
    $usersTableExists = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
    if ($usersTableExists) {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
        $system_stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    }
    
    // Check if live_sessions table exists
    $sessionsTableExists = $pdo->query("SHOW TABLES LIKE 'live_sessions'")->rowCount() > 0;
    if ($sessionsTableExists) {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM live_sessions WHERE status = 'active'");
        $system_stats['active_sessions'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    }
    
    // Check if classes table exists
    $classesTableExists = $pdo->query("SHOW TABLES LIKE 'classes'")->rowCount() > 0;
    if ($classesTableExists) {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM classes WHERE is_active = 1");
        $system_stats['total_classes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    }
    
    // Check if emotion_data table exists
    $emotionTableExists = $pdo->query("SHOW TABLES LIKE 'emotion_data'")->rowCount() > 0;
    if ($emotionTableExists) {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM emotion_data");
        $system_stats['total_emotion_records'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    }
} catch (PDOException $e) {
    // Some tables might not exist yet
    error_log("Stats fetch error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Emotion System</title>
    <style>
        /* Add this missing CSS for system stats */

        /* Rest of your existing CSS remains the same */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: #f5f5f5;
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 4px 0 15px rgba(0,0,0,0.2);
            z-index: 1000;
            transition: all 0.3s ease;
        }
        
        .sidebar-header {
            padding: 25px 20px;
            background: rgba(15, 23, 42, 0.9);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        
        .sidebar-header h1 {
            font-size: 24px;
            margin-bottom: 5px;
            color: white;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }
        
        .sidebar-header p {
            font-size: 12px;
            opacity: 0.8;
            letter-spacing: 1px;
        }
        
        .user-profile {
            padding: 25px 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: rgba(30, 41, 59, 0.5);
            margin: 10px;
            border-radius: 10px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            margin-right: 15px;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }
        
        .user-info h3 {
            font-size: 16px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .user-info p {
            font-size: 12px;
            opacity: 0.8;
            background: rgba(139, 92, 246, 0.1);
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .menu-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            padding: 0 25px 12px;
            color: rgba(255,255,255,0.5);
            font-weight: 600;
            margin-top: 15px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 14px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
            margin: 3px 10px;
            border-radius: 0 8px 8px 0;
            position: relative;
        }
        
        .menu-item:hover {
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.1) 0%, transparent 100%);
            color: white;
            border-left-color: #8b5cf6;
            transform: translateX(5px);
        }
        
        .menu-item.active {
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.2) 0%, transparent 100%);
            color: white;
            border-left-color: #8b5cf6;
            font-weight: 500;
        }
        
        .menu-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #8b5cf6;
            border-radius: 0 2px 2px 0;
        }
        
        .menu-icon {
            margin-right: 15px;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }
        
        .sidebar-logo img {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 50%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            background: white;
            padding: 5px;
        }

        .sidebar-logo {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 15px;
        }

        .sidebar-logo img:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.3);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        .topbar {
            background: white;
            padding: 0 30px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .menu-toggle {
            background: none;
            border: none;
            font-size: 20px;
            color: #4b5563;
            cursor: pointer;
            display: none;
        }
        
        .topbar-left h2 {
            color: #1f2937;
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .user-menu {
            position: relative;
        }
        
        .user-menu-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: none;
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .user-menu-btn:hover {
            background: #f3f4f6;
        }
        
        .user-avatar-small {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }
        
        .user-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            min-width: 200px;
            display: none;
            z-index: 1000;
            overflow: hidden;
        }
        
        .user-menu-dropdown.show {
            display: block;
        }
        
        .user-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: #4b5563;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .user-menu-item:hover {
            background: #f3f4f6;
            color: #8b5cf6;
        }
        
        .user-menu-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 5px 0;
        }
        
        .content-wrapper {
            padding: 30px;
        }
        
        /* Settings Header */
        .settings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .header-title h1 {
            color: #1f2937;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .header-title p {
            color: #6b7280;
            font-size: 14px;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        /* Message Styles */
        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideDown 0.3s ease;
        }
        
        .message.success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border: 1px solid #10b981;
            color: #065f46;
        }
        
        .message.error {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 1px solid #ef4444;
            color: #7f1d1d;
        }
        
        .message.warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #f59e0b;
            color: #92400e;
        }
        
        .message i {
            font-size: 20px;
        }
        
        /* Settings Tabs */
        .settings-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 0;
            overflow-x: auto;
        }
        
        .tab-btn {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: #6b7280;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.3s;
        }
        
        .tab-btn:hover {
            color: #4b5563;
        }
        
        .tab-btn.active {
            color: #8b5cf6;
            border-bottom-color: #8b5cf6;
            background: rgba(139, 92, 246, 0.05);
        }
        
        /* Settings Sections */
        .settings-sections {
            display: none;
        }
        
        .settings-sections.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        .settings-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .section-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
        }
        
        .section-title h3 {
            color: #1f2937;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .section-title p {
            color: #6b7280;
            font-size: 14px;
        }
        
        /* Settings Grid */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }
        
        .setting-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .setting-label {
            font-size: 14px;
            color: #374151;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .setting-label i {
            color: #8b5cf6;
            font-size: 16px;
        }
        
        .setting-description {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.5;
            margin-bottom: 5px;
        }
        
        .setting-input {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            color: #4b5563;
            background: white;
            transition: all 0.3s;
        }
        
        .setting-input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .setting-select {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            color: #4b5563;
            background: white;
            transition: all 0.3s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 20px;
            padding-right: 40px;
        }
        
        .setting-select:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        /* Switch Toggle */
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #e5e7eb;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        input:checked + .slider {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
        }
        
        input:checked + .slider:before {
            transform: translateX(30px);
        }
        
        /* Button Styles */
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(139, 92, 246, 0.4);
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            padding-top: 30px;
            border-top: 2px solid #f3f4f6;
            margin-top: 30px;
        }
        
        /* Range Input */
        .range-input {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .range-input input[type="range"] {
            flex: 1;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            outline: none;
            -webkit-appearance: none;
        }
        
        .range-input input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: #8b5cf6;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .range-value {
            min-width: 40px;
            text-align: center;
            font-weight: 600;
            color: #4b5563;
        }
        
        /* Emotion Checkboxes */
        .emotion-checkboxes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .emotion-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background: #f8fafc;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .emotion-checkbox:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
        }
        
        .emotion-checkbox input[type="checkbox"] {
            margin: 0;
        }
        
        .emotion-label {
            font-size: 13px;
            font-weight: 500;
            color: #4b5563;
        }
        
        /* Chart Options */
        .chart-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .chart-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            cursor: pointer;
        }
        
        /* Animations */
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 80px;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .sidebar-header h1,
            .user-info,
            .menu-title,
            .menu-item span:not(.menu-icon) {
                display: none;
            }
            
            .menu-item {
                justify-content: center;
                padding: 15px;
                margin: 5px;
                border-radius: 10px;
            }
            
            .menu-icon {
                margin-right: 0;
                font-size: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .content-wrapper { padding: 20px; }
            .topbar { padding: 0 20px; height: 70px; }
            .settings-header { flex-direction: column; gap: 20px; }
            .settings-grid { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="image/logo1.png" alt="Emotion AI Logo">
            </div>
            <h1>PLMUN Emotion Monitoring</h1>
            <p>Administration Panel</p>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-title">Main Navigation</div>
            <a href="admin_dashboard.php" class="menu-item">
                <i class="menu-icon fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="menu-title">User Management</div>
            <a href="admin_user_management.php" class="menu-item">
                <i class="menu-icon fas fa-users-cog"></i>
                <span>User Management</span>
            </a>
            
            <div class="menu-title">Analytics & Reports</div>
            <a href="admin_analytics_reports.php" class="menu-item">
                <i class="menu-icon fas fa-chart-line"></i>
                <span>Analytics & Reports</span>
            </a>
            
            <div class="menu-title">System Settings</div>
            <a href="admin_system_settings.php" class="menu-item active">
                <i class="menu-icon fas fa-cogs"></i>
                <span>System Settings</span>
            </a>
            <a href="admin_audit_logs.php" class="menu-item">
                <i class="menu-icon fas fa-clipboard-list"></i>
                <span>Audit Logs</span>
            </a>
            
            <div class="menu-title">Account</div>
            <a href="admin_profile.php" class="menu-item">
                <i class="menu-icon fas fa-user-circle"></i>
                <span>Profile</span>
            </a>
            <a href="logout.php" class="menu-item">
                <i class="menu-icon fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>System Settings</h2>
            </div>
            <div class="topbar-right">
                <div class="user-menu">
                    <button class="user-menu-btn" id="userMenuBtn">
                        <div class="user-avatar-small">
                            <?php echo getInitials($_SESSION['full_name'] ?? 'AD'); ?>
                        </div>
                        <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Administrator'); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <a href="admin_profile.php" class="user-menu-item">
                            <i class="fas fa-user-circle"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="admin_system_settings.php" class="user-menu-item">
                            <i class="fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                        <div class="user-menu-divider"></div>
                        <a href="logout.php" class="user-menu-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Content -->
        <div class="content-wrapper">
            <!-- Settings Header -->
            <div class="settings-header">
                <div class="header-title">
                    <h1>System Configuration</h1>
                    <p>Configure system-wide settings and preferences</p>
                </div>
                <div class="header-actions">
                    <button type="submit" form="settingsForm" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <span>Save Changes</span>
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                        <i class="fas fa-redo"></i>
                        <span>Reset to Defaults</span>
                    </button>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- System Statistics -->
            
            <!-- Settings Tabs -->
            <div class="settings-tabs" id="settingsTabs">
                <button type="button" class="tab-btn active" onclick="showTab('webcam', this)">
                    <i class="fas fa-camera"></i>
                    <span>Webcam Access</span>
                </button>
                <button type="button" class="tab-btn" onclick="showTab('emotion', this)">
                    <i class="fas fa-brain"></i>
                    <span>Emotion Detection</span>
                </button>
                <button type="button" class="tab-btn" onclick="showTab('dashboard', this)">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </button>
                <button type="button" class="tab-btn" onclick="showTab('reports', this)">
                    <i class="fas fa-file-export"></i>
                    <span>Reports</span>
                </button>
                <button type="button" class="tab-btn" onclick="showTab('privacy', this)">
                    <i class="fas fa-shield-alt"></i>
                    <span>Privacy & Security</span>
                </button>
            </div>
            
            <!-- Settings Form -->
            <form method="POST" action="" id="settingsForm">
                <?php echo csrfField(); ?>
                
                <!-- 1. Webcam Access Controls -->
                <div class="settings-sections active" id="webcamTab">
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-camera"></i>
                            </div>
                            <div class="section-title">
                                <h3>Webcam Access Controls</h3>
                                <p>Configure webcam access settings for students</p>
                            </div>
                        </div>
                        
                        <div class="settings-grid">
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-video"></i>
                                    <span>Enable Webcam Access</span>
                                </label>
                                <div class="setting-description">
                                    Allow students to use their webcams for emotion detection
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="enable_webcam_access" 
                                           <?php echo $current_settings['enable_webcam_access'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-expand-alt"></i>
                                    <span>Video Resolution</span>
                                </label>
                                <div class="setting-description">
                                    Set the webcam video quality for emotion detection
                                </div>
                                <select name="webcam_resolution" class="setting-select">
                                    <option value="360p" <?php echo $current_settings['webcam_resolution'] == '360p' ? 'selected' : ''; ?>>360p (Lowest)</option>
                                    <option value="480p" <?php echo $current_settings['webcam_resolution'] == '480p' ? 'selected' : ''; ?>>480p (Low)</option>
                                    <option value="720p" <?php echo $current_settings['webcam_resolution'] == '720p' ? 'selected' : ''; ?>>720p (Recommended)</option>
                                    <option value="1080p" <?php echo $current_settings['webcam_resolution'] == '1080p' ? 'selected' : ''; ?>>1080p (High)</option>
                                </select>
                            </div>
                            
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-user-check"></i>
                                    <span>Require Consent</span>
                                </label>
                                <div class="setting-description">
                                    Require student consent before accessing webcam
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="require_consent" 
                                           <?php echo $current_settings['require_consent'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 2. Emotion Detection Settings -->
                <div class="settings-sections" id="emotionTab">
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-brain"></i>
                            </div>
                            <div class="section-title">
                                <h3>Emotion Detection Settings</h3>
                                <p>Configure which emotions to detect and alert thresholds</p>
                            </div>
                        </div>
                        
                        <div class="settings-grid">
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-toggle-on"></i>
                                    <span>Enable Emotion Detection</span>
                                </label>
                                <div class="setting-description">
                                    Turn emotion detection on or off system-wide
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="enable_emotion_detection" 
                                           <?php echo $current_settings['enable_emotion_detection'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-smile"></i>
                                    <span>Detect Emotions</span>
                                </label>
                                <div class="setting-description">
                                    Select which emotions to detect during sessions
                                </div>
                                <div class="emotion-checkboxes">
                                    <label class="emotion-checkbox">
                                        <input type="checkbox" name="detect_happy" 
                                               <?php echo $current_settings['detect_happy'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">Happy</span>
                                    </label>
                                    <label class="emotion-checkbox">
                                        <input type="checkbox" name="detect_sad" 
                                               <?php echo $current_settings['detect_sad'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">Sad</span>
                                    </label>
                                    <label class="emotion-checkbox">
                                        <input type="checkbox" name="detect_neutral" 
                                               <?php echo $current_settings['detect_neutral'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">Neutral</span>
                                    </label>
                                    <label class="emotion-checkbox">
                                        <input type="checkbox" name="detect_angry" 
                                               <?php echo $current_settings['detect_angry'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">Angry</span>
                                    </label>
                                    <label class="emotion-checkbox">
                                        <input type="checkbox" name="detect_confused" 
                                               <?php echo $current_settings['detect_confused'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">Confused</span>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-bell"></i>
                                    <span>Alert Threshold (%)</span>
                                </label>
                                <div class="setting-description">
                                    Notify instructor if percentage of confused students exceeds this threshold
                                </div>
                                <div class="range-input">
                                    <input type="range" name="alert_threshold" min="10" max="90" step="5"
                                           value="<?php echo htmlspecialchars($current_settings['alert_threshold']); ?>" 
                                           oninput="updateRangeValue(this, 'alertThresholdValue')">
                                    <span class="range-value" id="alertThresholdValue"><?php echo $current_settings['alert_threshold']; ?>%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 3. Dashboard Settings -->
                <div class="settings-sections" id="dashboardTab">
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-tachometer-alt"></i>
                            </div>
                            <div class="section-title">
                                <h3>Dashboard Settings</h3>
                                <p>Configure dashboard display options and update frequency</p>
                            </div>
                        </div>
                        
                        <div class="settings-grid">
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-chart-line"></i>
                                    <span>Show Charts</span>
                                </label>
                                <div class="setting-description">
                                    Select which charts to display on the dashboard
                                </div>
                                <div class="chart-options">
                                    <label class="chart-option">
                                        <input type="checkbox" name="show_engagement_chart" 
                                               <?php echo $current_settings['show_engagement_chart'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">Engagement Chart</span>
                                    </label>
                                    <label class="chart-option">
                                        <input type="checkbox" name="show_emotion_chart" 
                                               <?php echo $current_settings['show_emotion_chart'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">Emotion Chart</span>
                                    </label>
                                    <label class="chart-option">
                                        <input type="checkbox" name="show_attendance_chart" 
                                               <?php echo $current_settings['show_attendance_chart'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">Attendance Chart</span>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-sync-alt"></i>
                                    <span>Update Frequency (seconds)</span>
                                </label>
                                <div class="setting-description">
                                    How frequently to update dashboard data in real-time
                                </div>
                                <select name="update_frequency" class="setting-select">
                                    <option value="10" <?php echo $current_settings['update_frequency'] == 10 ? 'selected' : ''; ?>>10 seconds</option>
                                    <option value="30" <?php echo $current_settings['update_frequency'] == 30 ? 'selected' : ''; ?>>30 seconds</option>
                                    <option value="60" <?php echo $current_settings['update_frequency'] == 60 ? 'selected' : ''; ?>>1 minute</option>
                                    <option value="120" <?php echo $current_settings['update_frequency'] == 120 ? 'selected' : ''; ?>>2 minutes</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 4. Report Generation Settings -->
                <div class="settings-sections" id="reportsTab">
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-file-export"></i>
                            </div>
                            <div class="section-title">
                                <h3>Report Generation Settings</h3>
                                <p>Configure automatic report generation and export formats</p>
                            </div>
                        </div>
                        
                        <div class="settings-grid">
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-robot"></i>
                                    <span>Enable Automatic Reports</span>
                                </label>
                                <div class="setting-description">
                                    Automatically generate session reports after each class
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="enable_auto_reports" 
                                           <?php echo $current_settings['enable_auto_reports'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-file-download"></i>
                                    <span>Export Formats</span>
                                </label>
                                <div class="setting-description">
                                    Select available export formats for reports
                                </div>
                                <div class="chart-options">
                                    <label class="chart-option">
                                        <input type="checkbox" name="report_format_pdf" 
                                               <?php echo $current_settings['report_format_pdf'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">PDF</span>
                                    </label>
                                    <label class="chart-option">
                                        <input type="checkbox" name="report_format_csv" 
                                               <?php echo $current_settings['report_format_csv'] ? 'checked' : ''; ?>>
                                        <span class="emotion-label">CSV</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 5. Privacy & Security Settings -->
                <div class="settings-sections" id="privacyTab">
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div class="section-title">
                                <h3>Privacy & Security Settings</h3>
                                <p>Configure data privacy and security settings</p>
                            </div>
                        </div>
                        
                        <div class="settings-grid">
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-video-slash"></i>
                                    <span>No Video Storage</span>
                                </label>
                                <div class="setting-description">
                                    Do not store any video recordings, process only in real-time
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="no_video_storage" 
                                           <?php echo $current_settings['no_video_storage'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-microphone-slash"></i>
                                    <span>No Audio Storage</span>
                                </label>
                                <div class="setting-description">
                                    Do not store any audio recordings
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="no_audio_storage" 
                                           <?php echo $current_settings['no_audio_storage'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-server"></i>
                                    <span>Local Processing</span>
                                </label>
                                <div class="setting-description">
                                    Ensure all emotion detection processing happens locally
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="local_processing" 
                                           <?php echo $current_settings['local_processing'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="setting-group">
                                <label class="setting-label">
                                    <i class="fas fa-user-secret"></i>
                                    <span>Data Anonymization</span>
                                </label>
                                <div class="setting-description">
                                    Anonymize student data in reports and analytics
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="data_anonymization" 
                                           <?php echo $current_settings['data_anonymization'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <span>Save All Settings</span>
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                        <i class="fas fa-redo"></i>
                        <span>Reset to Defaults</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Sidebar toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });
        
        // User menu dropdown
        const userMenuBtn = document.getElementById('userMenuBtn');
        const userMenuDropdown = document.getElementById('userMenuDropdown');
        
        userMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            userMenuDropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', () => {
            userMenuDropdown.classList.remove('show');
        });
        
        // Tab switching
        function showTab(tabName, button) {
            // Hide all tabs
            document.querySelectorAll('.settings-sections').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + 'Tab').classList.add('active');
            
            // Add active class to clicked button
            button.classList.add('active');
        }
        
        // Range value updater
        function updateRangeValue(input, valueId) {
            const valueSpan = document.getElementById(valueId);
            valueSpan.textContent = input.value + '%';
        }
        
        // Reset form to defaults
        function resetForm() {
            if (confirm('Are you sure you want to reset all settings to default values? This cannot be undone.')) {
                const defaults = {
                    // 1. Webcam Access Controls
                    'enable_webcam_access': true,
                    'webcam_resolution': '720p',
                    'require_consent': true,
                    
                    // 2. Emotion Detection Settings
                    'enable_emotion_detection': true,
                    'detect_happy': true,
                    'detect_sad': true,
                    'detect_neutral': true,
                    'detect_angry': true,
                    'detect_confused': true,
                    'alert_threshold': 50,
                    
                    // 3. Dashboard Settings
                    'show_engagement_chart': true,
                    'show_emotion_chart': true,
                    'show_attendance_chart': true,
                    'update_frequency': 30,
                    
                    // 4. Report Generation Settings
                    'enable_auto_reports': true,
                    'report_format_pdf': true,
                    'report_format_csv': false,
                    
                    // 5. Privacy & Security Settings
                    'no_video_storage': true,
                    'no_audio_storage': true,
                    'local_processing': true,
                    'data_anonymization': true
                };
                
                // Update form values
                Object.keys(defaults).forEach(key => {
                    const element = document.querySelector(`[name="${key}"]`);
                    if (element) {
                        if (element.type === 'checkbox') {
                            element.checked = defaults[key];
                        } else if (element.type === 'range') {
                            element.value = defaults[key];
                            if (key === 'alert_threshold') {
                                updateRangeValue(element, 'alertThresholdValue');
                            }
                        } else if (element.tagName === 'SELECT') {
                            element.value = defaults[key];
                        }
                    }
                });
                
                alert('Form has been reset to default values.');
            }
        }
        
        // Initialize range value
        document.addEventListener('DOMContentLoaded', function() {
            const alertThresholdInput = document.querySelector('input[name="alert_threshold"]');
            if (alertThresholdInput) {
                updateRangeValue(alertThresholdInput, 'alertThresholdValue');
            }
        });
        
        // Prevent form submission on enter key
        document.getElementById('settingsForm').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>