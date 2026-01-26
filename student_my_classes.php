<?php
// ==================== STUDENT MY CLASSES ====================
require_once 'config.php';

// Require student role
requireStudent();

// Get current user data
$userData = getUserData();
$userId = $_SESSION['user_id'];
$studentId = getStudentId();

if (!$userData) {
    // If user data not found, something is wrong - logout
    header('Location: logout.php');
    exit();
}

// Initialize variables
$message = '';
$message_type = '';

// Process form submission for joining class
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $message = "Security token invalid. Please try again.";
        $message_type = 'error';
    } else {
        // Process join class request
        if (isset($_POST['join_class'])) {
            $classCode = sanitizeInput($_POST['class_code'] ?? '');
            
            if (empty($classCode)) {
                $message = "Please enter a class code.";
                $message_type = 'error';
            } else {
                try {
                    // Check if class exists and is active
                    $stmt = $pdo->prepare("
                        SELECT c.*, u.full_name as instructor_name 
                        FROM " . TABLE_CLASSES . " c
                        JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
                        WHERE c.class_code = ? AND c.is_active = 1
                    ");
                    $stmt->execute([$classCode]);
                    $class = $stmt->fetch();
                    
                    if (!$class) {
                        $message = "Invalid class code or class is not active.";
                        $message_type = 'error';
                    } else {
                        // Check if student is already enrolled
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) as is_enrolled 
                            FROM " . TABLE_CLASS_ENROLLMENTS . " 
                            WHERE class_id = ? AND student_id = ?
                        ");
                        $stmt->execute([$class['id'], $studentId]);
                        $enrollment = $stmt->fetch();
                        
                        if ($enrollment['is_enrolled'] > 0) {
                            $message = "You are already enrolled in this class.";
                            $message_type = 'warning';
                        } else {
                            // Check if class has reached max students
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) as student_count 
                                FROM " . TABLE_CLASS_ENROLLMENTS . " 
                                WHERE class_id = ?
                            ");
                            $stmt->execute([$class['id']]);
                            $count = $stmt->fetch();
                            
                            if ($count['student_count'] >= $class['max_students']) {
                                $message = "This class has reached its maximum student capacity.";
                                $message_type = 'error';
                            } else {
                                // Enroll student in class
                                $stmt = $pdo->prepare("
                                    INSERT INTO " . TABLE_CLASS_ENROLLMENTS . " (class_id, student_id)
                                    VALUES (?, ?)
                                ");
                                $stmt->execute([$class['id'], $studentId]);
                                
                                // Log audit trail
                                logAuditTrail(
                                    $userId,
                                    $_SESSION['role'],
                                    $_SESSION['username'],
                                    'create',
                                    "Joined class: {$class['class_name']} ({$class['class_code']})",
                                    'class_enrollments',
                                    $pdo->lastInsertId(),
                                    ['class_id' => $class['id'], 'class_code' => $class['class_code']]
                                );
                                
                                $message = "Successfully joined class: {$class['class_name']}";
                                $message_type = 'success';
                            }
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Class Join Error: " . $e->getMessage());
                    $message = "An error occurred while joining the class. Please try again.";
                    $message_type = 'error';
                }
            }
        }
    }
}

// Get student's enrolled classes
$enrolledClasses = [];
$inactiveClasses = [];
$totalClasses = 0;

if ($studentId) {
    try {
        // Get active enrolled classes
        $stmt = $pdo->prepare("
            SELECT c.*, u.full_name as instructor_name,
                   (SELECT COUNT(*) FROM " . TABLE_CLASS_ENROLLMENTS . " WHERE class_id = c.id) as student_count,
                   ce.enrolled_at
            FROM " . TABLE_CLASSES . " c
            JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
            JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
            WHERE ce.student_id = ? AND c.is_active = 1
            ORDER BY c.class_name
        ");
        $stmt->execute([$studentId]);
        $enrolledClasses = $stmt->fetchAll();
        
        // Get inactive enrolled classes
        $stmt = $pdo->prepare("
            SELECT c.*, u.full_name as instructor_name,
                   (SELECT COUNT(*) FROM " . TABLE_CLASS_ENROLLMENTS . " WHERE class_id = c.id) as student_count,
                   ce.enrolled_at
            FROM " . TABLE_CLASSES . " c
            JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
            JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
            WHERE ce.student_id = ? AND c.is_active = 0
            ORDER BY c.class_name
        ");
        $stmt->execute([$studentId]);
        $inactiveClasses = $stmt->fetchAll();
        
        $totalClasses = count($enrolledClasses) + count($inactiveClasses);
        
    } catch (PDOException $e) {
        error_log("Classes Query Error: " . $e->getMessage());
        $message = "An error occurred while loading your classes.";
        $message_type = 'error';
    }
}

// Get active live sessions for enrolled classes
$activeSessions = [];
$upcomingSessions = [];
$allSessions = []; // Store all sessions for each class
if (!empty($enrolledClasses)) {
    $classIds = array_column($enrolledClasses, 'id');
    $placeholders = str_repeat('?,', count($classIds) - 1) . '?';
    
    try {
        // Get active sessions (currently running)
        $stmt = $pdo->prepare("
            SELECT ls.*, c.class_name, c.class_code, c.id as class_id, u.full_name as instructor_name,
                   (SELECT COUNT(*) FROM " . TABLE_LIVE_SESSION_PARTICIPANTS . " WHERE session_id = ls.id) as participant_count
            FROM " . TABLE_LIVE_SESSIONS . " ls
            JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
            JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
            WHERE ls.class_id IN ($placeholders)
            AND ls.status = 'active'
            ORDER BY ls.start_time DESC
        ");
        $stmt->execute($classIds);
        $activeSessions = $stmt->fetchAll();
        
        // Get upcoming sessions (next 7 days)
        $stmt = $pdo->prepare("
            SELECT ls.*, c.class_name, c.class_code, c.id as class_id, u.full_name as instructor_name,
                   TIMESTAMPDIFF(HOUR, NOW(), ls.start_time) as hours_until_start
            FROM " . TABLE_LIVE_SESSIONS . " ls
            JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
            JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
            WHERE ls.class_id IN ($placeholders)
            AND ls.status = 'scheduled'
            AND ls.start_time > NOW()
            AND ls.start_time <= DATE_ADD(NOW(), INTERVAL 7 DAY)
            ORDER BY ls.start_time ASC
            LIMIT 5
        ");
        $stmt->execute($classIds);
        $upcomingSessions = $stmt->fetchAll();
        
        // Get all sessions for each class (for the Sessions button)
        $stmt = $pdo->prepare("
            SELECT ls.*, c.class_name, c.class_code, c.id as class_id, u.full_name as instructor_name
            FROM " . TABLE_LIVE_SESSIONS . " ls
            JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
            JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
            WHERE ls.class_id IN ($placeholders)
            AND (ls.status = 'active' OR ls.status = 'scheduled')
            ORDER BY ls.start_time ASC
        ");
        $stmt->execute($classIds);
        $allSessionsResult = $stmt->fetchAll();
        
        // Organize sessions by class_id
        foreach ($allSessionsResult as $session) {
            $allSessions[$session['class_id']][] = $session;
        }
    } catch (PDOException $e) {
        error_log("Sessions Query Error: " . $e->getMessage());
    }
}

// Get student engagement statistics for sidebar badges
$engagementStats = [];
if ($studentId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT sa.session_id) as attended_sessions,
                AVG(ses.engagement_score) as avg_engagement
            FROM " . TABLE_SESSION_ATTENDANCE . " sa
            JOIN " . TABLE_LIVE_SESSIONS . " ls ON sa.session_id = ls.id
            LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON sa.session_id = ses.session_id AND sa.student_id = ses.student_id
            WHERE sa.student_id = ?
            AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$studentId]);
        $engagementStats = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Engagement Stats Query Error: " . $e->getMessage());
    }
}

// Get recent announcements for sidebar badge
$recentAnnouncements = [];
if ($studentId && !empty($enrolledClasses)) {
    try {
        $classIds = array_column($enrolledClasses, 'id');
        $placeholders = str_repeat('?,', count($classIds) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT a.*, c.class_name, u.full_name as instructor_name
            FROM " . TABLE_ANNOUNCEMENTS . " a
            JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
            JOIN " . TABLE_USERS . " u ON a.created_by = u.id
            WHERE a.class_id IN ($placeholders)
            AND a.is_active = 1
            ORDER BY a.created_at DESC
            LIMIT 5
        ");
        
        $stmt->execute($classIds);
        $recentAnnouncements = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Announcements Query Error: " . $e->getMessage());
    }
}

// Check consent status for the sidebar badge
function checkConsentStatus($userId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN consent_type = 'camera' AND consent_given = 1 THEN 1 ELSE 0 END) as camera_consent,
                SUM(CASE WHEN consent_type = 'microphone' AND consent_given = 1 THEN 1 ELSE 0 END) as mic_consent,
                SUM(CASE WHEN consent_type = 'emotion_detection' AND consent_given = 1 THEN 1 ELSE 0 END) as emotion_consent
            FROM " . TABLE_USER_CONSENT . "
            WHERE user_id = ?
            GROUP BY user_id
        ");
        $stmt->execute([$userId]);
        $consent = $stmt->fetch();
        
        if ($consent) {
            $needs_attention = ($consent['camera_consent'] != 1 || $consent['mic_consent'] != 1 || $consent['emotion_consent'] != 1);
            return [
                'camera' => $consent['camera_consent'] == 1,
                'microphone' => $consent['mic_consent'] == 1,
                'emotion_detection' => $consent['emotion_consent'] == 1,
                'needs_attention' => $needs_attention
            ];
        }
    } catch (PDOException $e) {
        error_log("Consent Check Error: " . $e->getMessage());
    }
    
    return ['needs_attention' => true];
}

$consentStatus = checkConsentStatus($userId);

// Set page title
$page_title = "My Classes - " . $site_name;

// Log page access
logAuditTrail(
    $userId,
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    "Accessed my classes page",
    null,
    null,
    ['page' => 'student_my_classes']
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reuse styles from student_dashboard.php */
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
        
        .menu-badge {
            margin-left: auto;
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .menu-badge-warning {
            margin-left: auto;
            background: #f59e0b;
            color: white;
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 600;
        }

                /* Logo Styles */
        .sidebar-logo {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 15px;
        }
        
       .sidebar-logo img {
    width: 70px;
    height: 70px;
    object-fit: cover; /* Changed from 'contain' to 'cover' for better circle fill */
    border-radius: 50%; /* Changed from '10px' to '50%' for perfect circle */
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
    background: white;
    padding: 5px;
}
        
        .sidebar-logo img:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.3);
        }
        
        /* Main Content Styles */
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
        
        .notification-btn {
            position: relative;
            background: none;
            border: none;
            font-size: 20px;
            color: #6b7280;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.3s;
        }
        
        .notification-btn:hover {
            background: #f3f4f6;
            color: #8b5cf6;
        }
        
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            font-size: 10px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
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
        
        /* Message Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-color: #10b981;
            color: #065f46;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #fee2e2 0%, #fca5a5 100%);
            border-color: #ef4444;
            color: #7f1d1d;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-color: #f59e0b;
            color: #92400e;
        }
        
        .alert-info {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-color: #3b82f6;
            color: #1e40af;
        }
        
        /* Active Sessions Alert */
        .active-sessions-alert {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        /* Join Class Form */
        .join-class-form {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .join-class-form h3 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 700;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: #f8fafc;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #8b5cf6;
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .form-hint {
            margin-top: 6px;
            font-size: 14px;
            color: #6b7280;
        }
        
        /* Class Cards Grid */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .class-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border: 2px solid #e5e7eb;
        }
        
        .class-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            border-color: #8b5cf6;
        }
        
        .class-card.inactive {
            opacity: 0.8;
            border-color: #d1d5db;
            background: #f9fafb;
        }
        
        .class-card-header {
            padding: 25px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            position: relative;
        }
        
        .class-card-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
            color: white;
        }
        
        .class-card-code {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .class-card-status {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active {
            background: rgba(16, 185, 129, 0.9);
            color: white;
        }
        
        .status-inactive {
            background: rgba(107, 114, 128, 0.9);
            color: white;
        }
        
        .class-card-body {
            padding: 25px;
        }
        
        .class-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            color: #4b5563;
        }
        
        .class-info-item i {
            width: 20px;
            color: #8b5cf6;
        }
        
        .class-card-footer {
            padding: 20px 25px;
            border-top: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }
        
        .class-actions {
            display: flex;
            gap: 10px;
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(139, 92, 246, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(16, 185, 129, 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(245, 158, 11, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(239, 68, 68, 0.4);
        }
        
        .btn-disabled {
            background: #e5e7eb;
            color: #9ca3af;
            cursor: not-allowed;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #e5e7eb;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            font-size: 20px;
            color: #1f2937;
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
            
            .sidebar:hover .sidebar-header h1,
            .sidebar:hover .user-info,
            .sidebar:hover .menu-title,
            .sidebar:hover .menu-item span:not(.menu-icon) {
                display: block;
            }
            
            .sidebar:hover .menu-item {
                justify-content: flex-start;
                padding: 14px 25px;
            }
            
            .sidebar:hover .menu-icon {
                margin-right: 15px;
            }
        }
        
        @media (max-width: 768px) {
            .content-wrapper {
                padding: 20px;
            }
            
            .topbar {
                padding: 0 20px;
                height: 70px;
            }
            
            .topbar-left h2 {
                font-size: 20px;
            }
            
            .classes-grid {
                grid-template-columns: 1fr;
            }
            
            .class-card-footer {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .class-actions {
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .btn {
                padding: 8px 16px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
              <!-- Logo Section -->
            <div class="sidebar-logo">
                <img src="image/logo1.png" alt="Emotion AI Logo">
            </div>
            
            <h1>PLMUN Emotion Monitoring</h1>
            <p>Student Panel</p>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-title">Main Navigation</div>
            <a href="student_dashboard.php" class="menu-item">
                <i class="menu-icon fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="menu-title">Classes</div>
            <a href="student_my_classes.php" class="menu-item active">
                <i class="menu-icon fas fa-chalkboard-teacher"></i>
                <span>My Classes</span>
                <?php if ($totalClasses > 0): ?>
                    <span class="menu-badge"><?php echo $totalClasses; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="student_my_engagement.php" class="menu-item">
                <i class="menu-icon fas fa-chart-line"></i>
                <span>My Engagement</span>
                <?php if ($engagementStats['avg_engagement'] ?? 0 > 0): ?>
                    <span class="menu-badge"><?php echo round($engagementStats['avg_engagement'], 1); ?>%</span>
                <?php endif; ?>
            </a>
            
            <a href="student_announcement.php" class="menu-item">
                <i class="menu-icon fas fa-bullhorn"></i>
                <span>Announcement</span>
                <?php if (!empty($recentAnnouncements)): ?>
                    <span class="menu-badge"><?php echo count($recentAnnouncements); ?></span>
                <?php endif; ?>
            </a>
            
            <div class="menu-title">Account Settings</div>
            
            <a href="student_profile.php" class="menu-item">
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
                <h2>My Classes</h2>
            </div>
            <div class="topbar-right">
                <button class="notification-btn" id="notificationBtn">
                    <i class="fas fa-bell"></i>
                    <?php if (!empty($recentAnnouncements)): ?>
                        <span class="notification-badge"><?php echo count($recentAnnouncements); ?></span>
                    <?php endif; ?>
                </button>
                <div class="user-menu">
                    <button class="user-menu-btn" id="userMenuBtn">
                        <div class="user-avatar-small">
                            <?php echo getInitials($userData['full_name']); ?>
                        </div>
                        <span><?php echo htmlspecialchars($userData['full_name']); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <a href="profile.php" class="user-menu-item">
                            <i class="fas fa-user-circle"></i>
                            <span>My Profile</span>
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
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'info-circle')); ?>"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Active Sessions Alert -->
            <?php if (!empty($activeSessions)): ?>
                <div class="active-sessions-alert">
                    <i class="fas fa-video"></i>
                    <div style="flex: 1;">
                        <strong>Active Live Sessions Available!</strong>
                        <div style="font-size: 14px; margin-top: 5px;">
                            There <?php echo count($activeSessions) == 1 ? 'is' : 'are'; ?> <?php echo count($activeSessions); ?> active live session<?php echo count($activeSessions) == 1 ? '' : 's'; ?> for your classes.
                        </div>
                    </div>
                    <a href="student_start_session.php?class_id=<?php echo $activeSessions[0]['class_id']; ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-play-circle"></i>
                        View Sessions
                    </a>
                </div>
            <?php endif; ?>
            
            <!-- Join Class Section -->
            <div class="join-class-form">
                <h3>Join a Class</h3>
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    <div class="form-group">
                        <label class="form-label" for="class_code">Class Code</label>
                        <input type="text" 
                               id="class_code" 
                               name="class_code" 
                               class="form-input" 
                               placeholder="Enter class code provided by your instructor"
                               required
                               maxlength="20">
                        <div class="form-hint">
                            <i class="fas fa-info-circle"></i> 
                            Ask your instructor for the class code
                        </div>
                    </div>
                    <button type="submit" name="join_class" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt"></i>
                        Join Class
                    </button>
                </form>
            </div>
            
            <!-- Active Classes Section -->
            <div style="margin-bottom: 40px;">
                <h2 style="color: #1f2937; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #f3f4f6; font-weight: 700; font-size: 22px;">
                    <i class="fas fa-chalkboard-teacher"></i>
                    My Active Classes (<?php echo count($enrolledClasses); ?>)
                </h2>
                
                <?php if (!empty($enrolledClasses)): ?>
                    <div class="classes-grid">
                        <?php foreach ($enrolledClasses as $class): 
                            // Check if this class has active sessions
                            $classActiveSessions = array_filter($activeSessions, function($session) use ($class) {
                                return $session['class_id'] == $class['id'];
                            });
                            $hasActiveSession = !empty($classActiveSessions);
                            
                            // Get all sessions for this class
                            $classAllSessions = $allSessions[$class['id']] ?? [];
                        ?>
                            <div class="class-card">
                                <div class="class-card-header">
                                    <div class="class-card-title"><?php echo htmlspecialchars($class['class_name']); ?></div>
                                    <div class="class-card-code"><?php echo htmlspecialchars($class['class_code']); ?></div>
                                    <?php if ($hasActiveSession): ?>
                                        <div class="class-card-status status-active" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                            Live Now
                                        </div>
                                    <?php elseif (!empty($classAllSessions)): ?>
                                        <div class="class-card-status" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                                            Sessions Available
                                        </div>
                                    <?php else: ?>
                                        <div class="class-card-status status-active">Active</div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="class-card-body">
                                    <div class="class-info-item">
                                        <i class="fas fa-user-tie"></i>
                                        <span><strong>Instructor:</strong> <?php echo htmlspecialchars($class['instructor_name']); ?></span>
                                    </div>
                                    
                                    <div class="class-info-item">
                                        <i class="fas fa-users"></i>
                                        <span><strong>Students:</strong> <?php echo $class['student_count']; ?>/<?php echo $class['max_students']; ?></span>
                                    </div>
                                    
                                    <?php if ($class['description']): ?>
                                        <div class="class-info-item">
                                            <i class="fas fa-align-left"></i>
                                            <span><strong>Description:</strong> <?php echo nl2br(htmlspecialchars(substr($class['description'], 0, 100))); ?>
                                            <?php if (strlen($class['description']) > 100): ?>...<?php endif; ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($class['schedule']): ?>
                                        <div class="class-info-item">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span><strong>Schedule:</strong> <?php echo formatDate($class['schedule'], 'D, M j, Y h:i A'); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="class-info-item">
                                        <i class="fas fa-calendar-plus"></i>
                                        <span><strong>Enrolled:</strong> <?php echo relativeTime($class['enrolled_at']); ?></span>
                                    </div>
                                    
                                    <?php if ($hasActiveSession): ?>
                                        <div class="class-info-item">
                                            <i class="fas fa-video" style="color: #10b981;"></i>
                                            <span><strong>Live Session:</strong> <?php echo count($classActiveSessions); ?> active</span>
                                        </div>
                                    <?php elseif (!empty($classAllSessions)): ?>
                                        <div class="class-info-item">
                                            <i class="fas fa-calendar-check" style="color: #3b82f6;"></i>
                                            <span><strong>Total Sessions:</strong> <?php echo count($classAllSessions); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="class-card-footer">
                                    <div style="font-size: 12px; color: #6b7280;">
                                        <i class="fas fa-fingerprint"></i>
                                        <span>
                                            Emotion Tracking: <?php echo $class['emotion_tracking'] ? 'Enabled' : 'Disabled'; ?> | 
                                            Auto Attendance: <?php echo $class['auto_attendance'] ? 'Enabled' : 'Disabled'; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="class-actions">
                                        <?php if ($hasActiveSession): 
                                            // Get the first active session
                                            $activeSession = reset($classActiveSessions);
                                        ?>
                                            <a href="student_start_session.php?session_id=<?php echo $activeSession['id']; ?>&class_id=<?php echo $class['id']; ?>" class="btn btn-success">
                                                <i class="fas fa-play-circle"></i>
                                                Join Live
                                            </a>
                                        <?php elseif (!empty($classAllSessions)): 
                                            // Get the most recent session (active or upcoming)
                                            $recentSession = $classAllSessions[0];
                                        ?>
                                            <a href="student_start_session.php?session_id=<?php echo $recentSession['id']; ?>&class_id=<?php echo $class['id']; ?>" class="btn btn-primary">
                                                <i class="fas fa-video"></i>
                                                View Sessions
                                            </a>
                                        <?php else: ?>
                                            <a href="#" class="btn btn-disabled" disabled>
                                                <i class="fas fa-video-slash"></i>
                                                No Sessions
                                            </a>
                                        <?php endif; ?>
                                        <a href="class_details.php?class_id=<?php echo $class['id']; ?>" class="btn btn-warning">
                                            <i class="fas fa-info-circle"></i>
                                            Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <h3>No Active Classes</h3>
                        <p>You are not enrolled in any active classes. Join a class using the form above.</p>
                        <div style="margin-top: 20px;">
                            <a href="student_dashboard.php" class="btn btn-primary">
                                <i class="fas fa-tachometer-alt"></i>
                                Back to Dashboard
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Inactive Classes Section -->
            <?php if (!empty($inactiveClasses)): ?>
                <div style="margin-bottom: 40px;">
                    <h2 style="color: #1f2937; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #f3f4f6; font-weight: 700; font-size: 22px;">
                        <i class="fas fa-archive"></i>
                        Inactive Classes (<?php echo count($inactiveClasses); ?>)
                    </h2>
                    
                    <div class="classes-grid">
                        <?php foreach ($inactiveClasses as $class): ?>
                            <div class="class-card inactive">
                                <div class="class-card-header" style="background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);">
                                    <div class="class-card-title"><?php echo htmlspecialchars($class['class_name']); ?></div>
                                    <div class="class-card-code"><?php echo htmlspecialchars($class['class_code']); ?></div>
                                    <div class="class-card-status status-inactive">Inactive</div>
                                </div>
                                
                                <div class="class-card-body">
                                    <div class="class-info-item">
                                        <i class="fas fa-user-tie"></i>
                                        <span><strong>Instructor:</strong> <?php echo htmlspecialchars($class['instructor_name']); ?></span>
                                    </div>
                                    
                                    <div class="class-info-item">
                                        <i class="fas fa-info-circle"></i>
                                        <span><strong>Status:</strong> This class is currently inactive. Contact your instructor for more information.</span>
                                    </div>
                                </div>
                                
                                <div class="class-card-footer">
                                    <div style="font-size: 12px; color: #6b7280;">
                                        <i class="fas fa-calendar-plus"></i>
                                        <span>Enrolled: <?php echo relativeTime($class['enrolled_at']); ?></span>
                                    </div>
                                    
                                    <div class="class-actions">
                                        <button class="btn btn-disabled" disabled>
                                            <i class="fas fa-eye-slash"></i>
                                            View Only
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Sidebar toggle for mobile
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
        
        // Close dropdown when clicking outside
        document.addEventListener('click', () => {
            userMenuDropdown.classList.remove('show');
        });
        
        // Notification button
        const notificationBtn = document.getElementById('notificationBtn');
        notificationBtn.addEventListener('click', () => {
            alert('You have <?php echo count($recentAnnouncements); ?> unread announcements.');
        });
        
        // Class code input validation
        const classCodeInput = document.getElementById('class_code');
        if (classCodeInput) {
            classCodeInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            });
        }
        
        // Add animation to class cards on hover
        document.querySelectorAll('.class-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
        
        // Auto-hide success messages after 5 seconds
        const successAlert = document.querySelector('.alert-success');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.opacity = '0';
                setTimeout(() => {
                    successAlert.style.display = 'none';
                }, 300);
            }, 5000);
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + M to toggle sidebar
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sidebar.classList.toggle('active');
            }
            
            // Ctrl + J to focus on class code input
            if (e.ctrlKey && e.key === 'j') {
                e.preventDefault();
                if (classCodeInput) {
                    classCodeInput.focus();
                }
            }
            
            // Ctrl + C to clear class code input
            if (e.ctrlKey && e.key === 'c' && classCodeInput && document.activeElement === classCodeInput) {
                classCodeInput.value = '';
            }
        });
        
        // Display keyboard shortcut hint
        console.log('Keyboard shortcuts available:\nCtrl+M: Toggle sidebar\nCtrl+J: Focus class code input\nCtrl+C: Clear class code (when focused)');
    </script>
</body>
</html>