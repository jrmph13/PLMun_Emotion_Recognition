<?php
// ==================== STUDENT DASHBOARD ====================
require_once 'config.php';

// Require student role (no redirect loops)
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

// Get student's enrolled classes
$studentClasses = getUserClasses($userId, 'student');

// Get today's live sessions
$today = date('Y-m-d');

// Initialize variables
$todaySessions = [];
$upcomingSessions = [];
$recentAnnouncements = [];
$attendanceSummary = [
    'attended' => 0,
    'total' => 0,
    'rate' => 0
];
$engagementStats = [
    'attended_sessions' => 0,
    'total_sessions' => 0,
    'avg_engagement' => 0,
    'avg_happiness' => 0,
    'active_classes' => 0
];
$sessionSummary = [];

try {
    if ($studentId) {
        // Get student's enrolled class IDs
        $classIds = [];
        if (!empty($studentClasses)) {
            $classIds = array_column($studentClasses, 'id');
        }
        
        // Today's active sessions
        if (!empty($classIds)) {
            $placeholders = str_repeat('?,', count($classIds) - 1) . '?';
            
            // Today's active sessions
            $stmt = $pdo->prepare("
                SELECT ls.*, c.class_name, c.class_code, u.full_name as instructor_name,
                       IF(sa.id IS NOT NULL, 1, 0) as has_joined
                FROM " . TABLE_LIVE_SESSIONS . " ls
                JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
                JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
                LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id AND sa.student_id = ?
                WHERE ls.class_id IN ($placeholders)
                AND ls.status = 'active'
                AND ls.start_time <= NOW()
                AND ls.end_time >= NOW()
                ORDER BY ls.start_time ASC
            ");
            
            $params = array_merge([$studentId], $classIds);
            $stmt->execute($params);
            $todaySessions = $stmt->fetchAll();
            
            // Upcoming sessions (next 7 days)
            $stmt = $pdo->prepare("
                SELECT ls.*, c.class_name, c.class_code, u.full_name as instructor_name
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
            
            // Get recent announcements
            $stmt = $pdo->prepare("
                SELECT a.*, c.class_name, u.full_name as instructor_name
                FROM " . TABLE_ANNOUNCEMENTS . " a
                JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
                JOIN " . TABLE_USERS . " u ON a.teacher_id = u.id
                WHERE a.class_id IN ($placeholders)
                ORDER BY a.created_at DESC
                LIMIT 3
            ");
            
            $stmt->execute($classIds);
            $recentAnnouncements = $stmt->fetchAll();
        }
        
        // Get student's engagement statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT sa.session_id) as attended_sessions,
                COUNT(DISTINCT ls.id) as total_sessions,
                COALESCE(AVG(ses.engagement_score), 0) as avg_engagement,
                COALESCE(AVG(ses.happy_percent), 0) as avg_happiness
            FROM " . TABLE_SESSION_ATTENDANCE . " sa
            LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON sa.session_id = ls.id
            LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON sa.session_id = ses.session_id AND sa.student_id = ses.student_id
            WHERE sa.student_id = ?
            AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        $stmt->execute([$studentId]);
        $engagementStats = $stmt->fetch() ?: $engagementStats;
        
        // Get attendance summary (last 30 days)
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT CASE WHEN sa.id IS NOT NULL THEN sa.session_id END) as attended_sessions,
                COUNT(DISTINCT ls.id) as total_sessions,
                CASE 
                    WHEN COUNT(DISTINCT ls.id) > 0 
                    THEN ROUND((COUNT(DISTINCT CASE WHEN sa.id IS NOT NULL THEN sa.session_id END) * 100.0) / COUNT(DISTINCT ls.id), 1)
                    ELSE 0
                END as attendance_rate
            FROM " . TABLE_LIVE_SESSIONS . " ls
            LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id AND sa.student_id = ?
            WHERE ls.class_id IN (SELECT class_id FROM " . TABLE_CLASS_ENROLLMENTS . " WHERE student_id = ?)
            AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND ls.status = 'ended'
        ");
        
        $stmt->execute([$studentId, $studentId]);
        $attendanceData = $stmt->fetch();
        if ($attendanceData) {
            $attendanceSummary = [
                'attended' => $attendanceData['attended_sessions'],
                'total' => $attendanceData['total_sessions'],
                'rate' => $attendanceData['attendance_rate']
            ];
        }
        
        // Get session summary for emotion graph
        $stmt = $pdo->prepare("
            SELECT 
                DATE(ls.start_time) as session_date,
                ls.session_name,
                c.class_name,
                ses.engagement_score,
                ses.happy_percent,
                ses.sad_percent,
                ses.angry_percent,
                ses.confused_percent,
                ses.neutral_percent,
                ses.bored_percent,
                ses.average_emotion,
                COUNT(DISTINCT ed.emotion) as emotion_count
            FROM " . TABLE_SESSION_ATTENDANCE . " sa
            JOIN " . TABLE_LIVE_SESSIONS . " ls ON sa.session_id = ls.id
            JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
            LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON sa.session_id = ses.session_id AND sa.student_id = ses.student_id
            LEFT JOIN " . TABLE_EMOTION_DATA . " ed ON sa.session_id = ed.session_id AND sa.student_id = ed.student_id
            WHERE sa.student_id = ?
            AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY ls.id, DATE(ls.start_time), ls.session_name, c.class_name, 
                     ses.engagement_score, ses.happy_percent, ses.sad_percent, 
                     ses.angry_percent, ses.confused_percent, ses.neutral_percent, 
                     ses.bored_percent, ses.average_emotion
            ORDER BY ls.start_time DESC
            LIMIT 5
        ");
        
        $stmt->execute([$studentId]);
        $sessionSummary = $stmt->fetchAll();
        
        // Get current session emotion data (if in active session)
        $currentEmotion = null;
        if (!empty($todaySessions)) {
            $currentSessionId = $todaySessions[0]['id'];
            $stmt = $pdo->prepare("
                SELECT emotion, confidence, created_at
                FROM " . TABLE_EMOTION_DATA . "
                WHERE student_id = ?
                AND session_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$studentId, $currentSessionId]);
            $currentEmotion = $stmt->fetch();
        }
        
    }
    
} catch (PDOException $e) {
    error_log("Dashboard Query Error: " . $e->getMessage());
    $error_message = "An error occurred while loading dashboard data.";
}

// Check consent status
$consentStatus = checkConsentStatus($userId);

// Set page title
$page_title = "Student Dashboard - " . $site_name;

// Log dashboard access
logAuditTrail(
    $userId,
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    "Accessed student dashboard",
    null,
    null,
    ['page' => 'student_dashboard']
);

// Function to get emotion color
function getEmotionColor($emotion) {
    $colors = [
        'happy' => '#10b981',
        'neutral' => '#6b7280',
        'sad' => '#3b82f6',
        'angry' => '#ef4444',
        'confused' => '#f59e0b',
        'bored' => '#8b5cf6'
    ];
    return $colors[strtolower($emotion)] ?? '#6b7280';
}

// Function to get emotion icon
function getEmotionIcon($emotion) {
    $icons = [
        'happy' => 'fas fa-smile-beam',
        'neutral' => 'fas fa-meh',
        'sad' => 'fas fa-frown',
        'angry' => 'fas fa-angry',
        'confused' => 'fas fa-question-circle',
        'bored' => 'fas fa-tired'
    ];
    return $icons[strtolower($emotion)] ?? 'fas fa-meh';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
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
        
        /* Sidebar Styles - Keep as is */
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
            object-fit: cover;
            border-radius: 50%;
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
        
        /* Dashboard Grid - Updated for 4 cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        @media (min-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-top: 4px solid;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color), transparent);
        }
        
        .stat-card[data-color="purple"] {
            --card-color: #8b5cf6;
            border-color: #8b5cf6;
        }
        
        .stat-card[data-color="blue"] {
            --card-color: #3b82f6;
            border-color: #3b82f6;
        }
        
        .stat-card[data-color="green"] {
            --card-color: #10b981;
            border-color: #10b981;
        }
        
        .stat-card[data-color="orange"] {
            --card-color: #f59e0b;
            border-color: #f59e0b;
        }
        
        .stat-card[data-color="red"] {
            --card-color: #ef4444;
            border-color: #ef4444;
        }
        
        .stat-card[data-color="teal"] {
            --card-color: #0d9488;
            border-color: #0d9488;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(0,0,0,0.12);
        }
        
        .stat-card h3 {
            color: #6b7280;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 8px;
            line-height: 1;
        }
        
        .stat-subtext {
            font-size: 12px;
            color: #9ca3af;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .stat-subtext i {
            font-size: 10px;
        }
        
        .card-icon {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 32px;
            opacity: 0.2;
            color: var(--card-color);
        }
        
        /* Main Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .main-section {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .card h2 {
            color: #1f2937;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            font-weight: 700;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card h3 {
            color: #1f2937;
            margin-bottom: 15px;
            font-weight: 600;
            font-size: 18px;
        }
        
        .sidebar-section {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        
        /* Enrolled Classes Section */
        .classes-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .class-card {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 18px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .class-card:hover {
            border-color: #8b5cf6;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.1);
        }
        
        .class-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            margin-bottom: 12px;
        }
        
        .class-name {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 6px;
            line-height: 1.3;
        }
        
        .class-code {
            font-family: monospace;
            font-size: 12px;
            color: #8b5cf6;
            font-weight: 600;
            margin-bottom: 8px;
            background: #f3f4f6;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        
        .class-instructor {
            font-size: 13px;
            color: #6b7280;
            margin-top: 8px;
        }
        
        .class-status {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 12px;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        /* Upcoming Sessions Section */
        .sessions-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 15px;
        }
        
        .session-item {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            transition: all 0.3s;
        }
        
        .session-item:hover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .session-name {
            font-size: 15px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .session-class {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 6px;
        }
        
        .session-time {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #8b5cf6;
            font-weight: 600;
        }
        
        .session-instructor {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 8px;
        }
        
        /* Attendance Summary */
        .attendance-stats {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 15px;
        }
        
        .attendance-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }
        
        .attendance-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            flex-shrink: 0;
        }
        
        .attendance-info {
            flex: 1;
        }
        
        .attendance-label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .attendance-value {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
        }
        
        .attendance-progress {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .attendance-progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease;
        }
        
        /* Engagement Level */
        .engagement-level {
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e5e7eb;
            margin-top: 15px;
        }
        
        .engagement-score {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .engagement-label {
            font-size: 16px;
            color: #1f2937;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .engagement-status {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .status-high {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-medium {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-low {
            background: #fee2e2;
            color: #7f1d1d;
        }
        
        .engagement-bar {
            height: 10px;
            background: #e5e7eb;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 15px;
        }
        
        .engagement-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 1s ease;
        }
        
        .engagement-scale {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #6b7280;
        }
        
        /* Live Sessions Alert */
        .live-session-alert {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 2px solid #10b981;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .live-session-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .live-session-header h3 {
            color: #065f46;
            margin: 0;
            font-size: 18px;
        }
        
        .live-session-status {
            background: #10b981;
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #e5e7eb;
            margin-bottom: 15px;
        }
        
        .empty-state h3 {
            margin-bottom: 8px;
            font-size: 18px;
            color: #1f2937;
        }
        
        /* Alert Styles */
        .alert-box {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .alert-box.warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-color: #f59e0b;
            color: #92400e;
        }
        
        .alert-box.info {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-color: #3b82f6;
            color: #1e40af;
        }
        
        .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .alert-content {
            flex: 1;
        }
        
        .alert-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .alert-message {
            font-size: 14px;
        }
        
        /* Button Styles */
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
        
        /* Responsive */
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
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
            
            .dashboard-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .classes-container {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .stat-value {
                font-size: 28px;
            }
            
            .card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="image/logo1.png" alt="Emotion AI Logo">
        </div>
        <h1>PLMUN Emotion Monitoring</h1>
        <p>Student Panel</p>
    </div>
    
    <div class="sidebar-menu">
        <div class="menu-title">Main Navigation</div>
        <a href="student_dashboard.php" class="menu-item active">
            <i class="menu-icon fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        
        <div class="menu-title">Classes</div>
        <a href="student_my_classes.php" class="menu-item">
            <i class="menu-icon fas fa-chalkboard-teacher"></i>
            <span>My Classes</span>
            <?php if (!empty($studentClasses)): ?>
                <span class="menu-badge"><?php echo count($studentClasses); ?></span>
            <?php endif; ?>
        </a>
        
        <a href="student_my_engagement.php" class="menu-item">
            <i class="menu-icon fas fa-chart-line"></i>
            <span>My Engagement</span>
            <?php if ($engagementStats['avg_engagement'] > 0): ?>
                <span class="menu-badge"><?php echo round($engagementStats['avg_engagement'], 1); ?>%</span>
            <?php endif; ?>
        </a>
        
        <!-- Add Attendance Menu Item -->
        
        <!-- Add Announcement Menu Item -->
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
                <h2>Student Dashboard</h2>
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
                        <a href="student_profile.php" class="user-menu-item">
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
            <?php if (isset($error_message)): ?>
                <div class="alert-box warning">
                    <i class="alert-icon fas fa-exclamation-circle"></i>
                    <div class="alert-content">
                        <div class="alert-title">Error</div>
                        <div class="alert-message"><?php echo $error_message; ?></div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Alerts/Notifications -->
            <?php if ($engagementStats['avg_engagement'] < 50 && $engagementStats['avg_engagement'] > 0): ?>
                <div class="alert-box warning">
                    <i class="alert-icon fas fa-lightbulb"></i>
                    <div class="alert-content">
                        <div class="alert-title">Engagement Alert</div>
                        <div class="alert-message">Your average engagement is low. Consider taking breaks and staying active during sessions.</div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!$consentStatus): ?>
                <div class="alert-box info">
                    <i class="alert-icon fas fa-exclamation-triangle"></i>
                    <div class="alert-content">
                        <div class="alert-title">Consent Required</div>
                        <div class="alert-message">Please update your privacy consent to use emotion monitoring features.</div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Stats Grid -->
            <div class="dashboard-grid">
                <div class="stat-card" data-color="purple">
                    <div class="card-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-value"><?php echo count($studentClasses); ?></div>
                    <h3>Enrolled Classes</h3>
                    <div class="stat-subtext">
                        <i class="fas fa-graduation-cap"></i>
                        Active courses
                    </div>
                </div>
                
                <div class="stat-card" data-color="blue">
                    <div class="card-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $engagementStats['attended_sessions']; ?></div>
                    <h3>Sessions Attended</h3>
                    <div class="stat-subtext">
                        <i class="fas fa-calendar-check"></i>
                        Last 30 days
                    </div>
                </div>
                
                <div class="stat-card" data-color="green">
                    <div class="card-icon">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stat-value"><?php echo $attendanceSummary['rate']; ?>%</div>
                    <h3>Attendance Rate</h3>
                    <div class="stat-subtext">
                        <i class="fas fa-chart-line"></i>
                        <?php echo $attendanceSummary['attended']; ?> of <?php echo $attendanceSummary['total']; ?> sessions
                    </div>
                </div>
                
                <div class="stat-card" data-color="orange">
                    <div class="card-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value"><?php echo round($engagementStats['avg_engagement'], 1); ?>%</div>
                    <h3>Avg Engagement</h3>
                    <div class="stat-subtext">
                        <i class="fas fa-brain"></i>
                        Overall focus level
                    </div>
                </div>
            </div>
            
            <!-- Main Content Grid -->
            <div class="content-grid">
                <div class="main-section">
                    <!-- Enrolled Classes Section -->
                    <div class="card">
                        <h2><i class="fas fa-chalkboard-teacher"></i> Enrolled Classes</h2>
                        
                        <?php if (!empty($studentClasses)): ?>
                            <div class="classes-container">
                                <?php foreach ($studentClasses as $class): ?>
                                    <div class="class-card">
                                        <div class="class-icon">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                        </div>
                                        
                                        <div class="class-name"><?php echo htmlspecialchars($class['class_name']); ?></div>
                                        <div class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></div>
                                        
                                        <?php if ($class['description']): ?>
                                            <p style="color: #6b7280; font-size: 12px; margin-bottom: 10px; line-height: 1.4;">
                                                <?php echo htmlspecialchars(substr($class['description'], 0, 100)); ?>
                                                <?php if (strlen($class['description']) > 100): ?>...<?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <div class="class-instructor">
                                            <i class="fas fa-user-tie"></i>
                                            <?php echo htmlspecialchars($class['instructor_name'] ?? 'Instructor'); ?>
                                        </div>
                                        
                                        <div class="class-status <?php echo $class['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $class['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <h3>No Enrolled Classes</h3>
                                <p>You are not enrolled in any classes yet.</p>
                                <a href="student_my_classes.php" class="btn btn-primary" style="margin-top: 15px;">
                                    <i class="fas fa-search"></i>
                                    Browse Classes
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Upcoming Sessions Section -->
                    <div class="card">
                        <h2><i class="fas fa-calendar-alt"></i> Upcoming Sessions</h2>
                        
                        <?php if (!empty($upcomingSessions)): ?>
                            <div class="sessions-list">
                                <?php foreach ($upcomingSessions as $session): ?>
                                    <div class="session-item">
                                        <div class="session-header">
                                            <div class="session-name"><?php echo htmlspecialchars($session['session_name'] ?: 'Class Session'); ?></div>
                                            <div class="session-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('h:i A', strtotime($session['start_time'])); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="session-class">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                            <?php echo htmlspecialchars($session['class_name']); ?>
                                            (<?php echo htmlspecialchars($session['class_code']); ?>)
                                        </div>
                                        
                                        <div class="session-instructor">
                                            <i class="fas fa-user-tie"></i>
                                            <?php echo htmlspecialchars($session['instructor_name']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div style="margin-top: 20px;">
                                <a href="student_my_classes.php" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border: 1px solid #e5e7eb; border-radius: 6px; color: #4b5563; text-decoration: none; font-size: 13px; font-weight: 500;">
                                    <i class="fas fa-calendar-alt"></i>
                                    View Full Calendar
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <h3>No Upcoming Sessions</h3>
                                <p>You have no scheduled sessions for the next 7 days.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="sidebar-section">
                    <!-- Attendance Summary -->
                    <div class="card">
                        <h3><i class="fas fa-clipboard-check"></i> Attendance Summary</h3>
                        
                        <div class="attendance-stats">
                            <div class="attendance-item">
                                <div class="attendance-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="attendance-info">
                                    <div class="attendance-label">Sessions Attended</div>
                                    <div class="attendance-value"><?php echo $attendanceSummary['attended']; ?></div>
                                </div>
                            </div>
                            
                            <div class="attendance-item">
                                <div class="attendance-icon" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="attendance-info">
                                    <div class="attendance-label">Total Sessions</div>
                                    <div class="attendance-value"><?php echo $attendanceSummary['total']; ?></div>
                                </div>
                            </div>
                            
                            <div class="attendance-progress">
                                <div class="attendance-progress-fill" style="width: <?php echo min($attendanceSummary['rate'], 100); ?>%; background: linear-gradient(90deg, #10b981 0%, #059669 100%);"></div>
                            </div>
                            
                            <div style="text-align: center;">
                                <div style="font-size: 24px; font-weight: 800; color: <?php echo $attendanceSummary['rate'] >= 80 ? '#10b981' : ($attendanceSummary['rate'] >= 60 ? '#f59e0b' : '#ef4444'); ?>;">
                                    <?php echo $attendanceSummary['rate']; ?>%
                                </div>
                                <div style="font-size: 13px; color: #6b7280; margin-top: 5px;">
                                    Attendance Rate
                                </div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <a href="student_attendance.php" class="btn btn-primary" style="width: 100%; text-align: center; justify-content: center;">
                                <i class="fas fa-chart-bar"></i>
                                View Detailed Report
                            </a>
                        </div>
                    </div>
                    
                    <!-- Overall Engagement Level -->
                    <div class="card">
                        <h3><i class="fas fa-brain"></i> Overall Engagement</h3>
                        
                        <div class="engagement-level">
                            <?php 
                            $engagement = $engagementStats['avg_engagement'];
                            $statusClass = 'status-low';
                            $statusText = 'Needs Improvement';
                            
                            if ($engagement >= 80) {
                                $statusClass = 'status-high';
                                $statusText = 'Excellent';
                            } elseif ($engagement >= 60) {
                                $statusClass = 'status-medium';
                                $statusText = 'Good';
                            } elseif ($engagement >= 40) {
                                $statusClass = 'status-medium';
                                $statusText = 'Average';
                            }
                            ?>
                            
                            <div class="engagement-score"><?php echo round($engagement, 1); ?>%</div>
                            <div class="engagement-label">Average Engagement Score</div>
                            
                            <div class="engagement-status <?php echo $statusClass; ?>">
                                <?php echo $statusText; ?>
                            </div>
                            
                            <div class="engagement-bar">
                                <div class="engagement-fill" style="width: <?php echo $engagement; ?>%; background: linear-gradient(90deg, #8b5cf6 0%, #3b82f6 100%);"></div>
                            </div>
                            
                            <div class="engagement-scale">
                                <span>Low</span>
                                <span>Moderate</span>
                                <span>High</span>
                            </div>
                            
                            <div style="margin-top: 15px; font-size: 13px; color: #6b7280;">
                                Based on your last 30 days of sessions
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <a href="student_my_engagement.php" class="btn btn-outline" style="width: 100%; text-align: center; justify-content: center; border: 1px solid #e5e7eb; color: #4b5563;">
                                <i class="fas fa-chart-line"></i>
                                View Engagement Analytics
                            </a>
                        </div>
                    </div>
                    
                    <!-- Live Session Alert -->
                    <?php if (!empty($todaySessions) && $consentStatus): ?>
                        <div class="live-session-alert">
                            <div class="live-session-header">
                                <h3><i class="fas fa-video"></i> Live Session Available</h3>
                                <div class="live-session-status">Active Now</div>
                            </div>
                            
                            <p style="color: #065f46; margin-bottom: 15px; font-size: 14px;">
                                <strong><?php echo htmlspecialchars($todaySessions[0]['class_name']); ?></strong><br>
                                <?php echo htmlspecialchars($todaySessions[0]['session_name'] ?: 'Class Session'); ?>
                            </p>
                            
                            <a href="join_session.php?session_id=<?php echo $todaySessions[0]['id']; ?>" class="btn btn-success" style="width: 100%; text-align: center; justify-content: center;">
                                <i class="fas fa-play"></i>
                                Join Session Now
                            </a>
                        </div>
                    <?php elseif (!$consentStatus): ?>
                        <div class="alert-box info">
                            <i class="alert-icon fas fa-shield-alt"></i>
                            <div class="alert-content">
                                <div class="alert-title">Consent Required</div>
                                <div class="alert-message">Update privacy settings to join live sessions</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
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
            alert('You have <?php echo count($recentAnnouncements); ?> recent announcements.');
        });
        
        // Animate progress bars
        document.querySelectorAll('.attendance-progress-fill, .engagement-fill').forEach(progress => {
            const width = progress.style.width;
            progress.style.width = '0';
            setTimeout(() => {
                progress.style.width = width;
            }, 500);
        });
        
        // Add hover effects to class cards
        document.querySelectorAll('.class-card, .session-item').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
        
        // Auto-refresh dashboard every 2 minutes
        setTimeout(() => {
            location.reload();
        }, 2 * 60 * 1000);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl+M to toggle sidebar
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sidebar.classList.toggle('active');
            }
            
            // Ctrl+R to refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                location.reload();
            }
        });
    </script>
</body>
</html>