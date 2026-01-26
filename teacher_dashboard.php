<?php
// ==================== TEACHER DASHBOARD - FULLY FUNCTIONAL ====================
// Start session and load configuration
require_once 'config.php';

// Require instructor or admin role
requireInstructor();

// Get current user data
$userData = getUserData();
$userId = $_SESSION['user_id'];

// Get instructor's classes
try {
    // ==================== FIXED QUERY 1: Get created classes ====================
    $stmt = $pdo->prepare("
        SELECT c.*, 
               COUNT(DISTINCT ce.student_id) as student_count,
               COUNT(DISTINCT ls.id) as session_count
        FROM " . TABLE_CLASSES . " c
        LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
        LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id
        WHERE c.instructor_id = ? AND c.is_active = 1
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$userId]);
    $created_classes = $stmt->fetchAll();

    // ==================== FIXED QUERY 3: Get upcoming sessions ====================
    $stmt = $pdo->prepare("
        SELECT ls.*, c.class_name, c.class_code
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        WHERE c.instructor_id = ? 
        AND ls.start_time >= NOW()
        AND ls.start_time <= DATE_ADD(NOW(), INTERVAL 7 DAY)
        ORDER BY ls.start_time ASC
    ");
    $stmt->execute([$userId]);
    $upcoming_sessions = $stmt->fetchAll();

    // ==================== FIXED QUERY 4: Get class engagement summary ====================
    $stmt = $pdo->prepare("
        SELECT 
            c.id as class_id,
            c.class_name,
            c.class_code,
            COUNT(DISTINCT ls.id) as total_sessions,
            COUNT(DISTINCT sa.student_id) as total_attendance,
            COALESCE(AVG(ses.engagement_score), 0) as avg_engagement,
            COALESCE(AVG(ses.happy_percent), 0) as avg_happiness,
            COALESCE(AVG(ses.bored_percent), 0) as avg_boredom
        FROM " . TABLE_CLASSES . " c
        LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id 
            AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id
        WHERE c.instructor_id = ? AND c.is_active = 1
        GROUP BY c.id
        ORDER BY avg_engagement DESC
    ");
    $stmt->execute([$userId]);
    $engagement_summary = $stmt->fetchAll();

    // ==================== FIXED QUERY 5: Get recent activity ====================
    $stmt = $pdo->prepare("
        SELECT 
            ls.id as session_id,
            c.class_name,
            ls.session_name,
            ls.start_time,
            COUNT(DISTINCT sa.student_id) as attendance_count,
            COALESCE(AVG(ses.engagement_score), 0) as engagement_score
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id
        WHERE c.instructor_id = ? 
        AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY ls.id
        ORDER BY ls.start_time DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $recent_activity = $stmt->fetchAll();

    // ==================== FIXED QUERY 6: Get quick stats ====================
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT c.id) as total_classes,
            COUNT(DISTINCT ce.student_id) as total_students,
            COUNT(DISTINCT ls.id) as total_sessions_30d,
            COALESCE(AVG(ses.engagement_score), 0) as avg_engagement_30d
        FROM " . TABLE_CLASSES . " c
        LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
        LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id 
            AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id
        WHERE c.instructor_id = ? AND c.is_active = 1
    ");
    $stmt->execute([$userId]);
    $quick_stats = $stmt->fetch();

    // ==================== FIXED QUERY 7: Get enrolled students count ====================
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ce.student_id) as total_students_enrolled
        FROM " . TABLE_CLASSES . " c
        JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
        WHERE c.instructor_id = ? AND c.is_active = 1
    ");
    $stmt->execute([$userId]);
    $student_stats = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Dashboard Query Error: " . $e->getMessage());
    $error_message = "An error occurred while loading dashboard data. Please try again later.";
    // Initialize empty arrays to prevent errors
    $created_classes = [];
    $upcoming_sessions = [];
    $engagement_summary = [];
    $recent_activity = [];
    $quick_stats = ['total_classes' => 0, 'total_students' => 0, 'total_sessions_30d' => 0, 'avg_engagement_30d' => 0];
    $student_stats = ['total_students_enrolled' => 0];
}

// Set page title
$page_title = "Teacher Dashboard - Emotion AI System";

// Log dashboard access for audit trail
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && isset($_SESSION['username'])) {
    logAuditTrail(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['username'],
        'view',
        'Accessed teacher dashboard',
        null,
        null,
        ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ==================== UPDATED TO MATCH ADMIN DASHBOARD ==================== */
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
            overflow-x: hidden; /* Prevent horizontal scroll on body */
        }
        
        /* Sidebar Styles - MATCHING ADMIN DASHBOARD */
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
        
        .sidebar:hover {
            width: 280px;
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
        
        /* Main Content Styles - MATCHING ADMIN DASHBOARD */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            overflow-x: hidden; /* Prevent horizontal scroll */
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
            animation: fadeIn 0.5s ease;
            max-width: 100%; /* Prevent overflow */
            overflow-x: hidden; /* Hide horizontal overflow */
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Dashboard Grid - MATCHING ADMIN DASHBOARD */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
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
        
        .stat-card[data-color="red"] {
            --card-color: #ef4444;
            border-color: #ef4444;
        }
        
        .stat-card[data-color="orange"] {
            --card-color: #f59e0b;
            border-color: #f59e0b;
        }
        
        .stat-card[data-color="pink"] {
            --card-color: #ec4899;
            border-color: #ec4899;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .stat-card h3 {
            color: #6b7280;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 10px;
            line-height: 1;
        }
        
        .stat-change {
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }
        
        .stat-change.positive {
            color: #10b981;
        }
        
        .stat-change.neutral {
            color: #6b7280;
        }
        
        .card-icon {
            position: absolute;
            top: 25px;
            right: 25px;
            font-size: 40px;
            opacity: 0.2;
            color: var(--card-color);
        }
        
        .card-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            max-width: 100%;
        }
        
        .main-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            overflow: hidden; /* Prevent content overflow */
        }
        
        .main-card h2 {
            color: #1f2937;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            font-weight: 700;
            font-size: 22px;
        }
        
        .sidebar-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            overflow: hidden; /* Prevent content overflow */
        }
        
        .sidebar-card h3 {
            color: #1f2937;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            font-weight: 600;
        }
        
        /* Button Styles - MATCHING ADMIN DASHBOARD */
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
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            background: #f8fafc;
            color: #4b5563;
            text-decoration: none;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            transition: all 0.3s;
            font-weight: 500;
            white-space: nowrap; /* Prevent text wrapping */
        }
        
        .action-btn:hover {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            border-color: transparent;
            transform: translateX(5px);
            box-shadow: 0 5px 20px rgba(139, 92, 246, 0.3);
        }
        
        .action-icon {
            margin-right: 12px;
            font-size: 18px;
        }
        
        /* Table Styles - FIXED FOR HORIZONTAL SCROLL */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 18px 20px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        
        .data-table td {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            color: #4b5563;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Make table columns responsive - REMOVED ACTIONS COLUMN */
        .data-table td:nth-child(1), /* Class Name */
        .data-table th:nth-child(1) {
            min-width: 250px;
        }
        
        .data-table td:nth-child(2), /* Class Code */
        .data-table th:nth-child(2) {
            min-width: 120px;
        }
        
        .data-table td:nth-child(3), /* Students */
        .data-table td:nth-child(4), /* Sessions */
        .data-table th:nth-child(3),
        .data-table th:nth-child(4) {
            min-width: 100px;
        }
        
        /* Created Classes Scroll Container */
        .classes-scroll-container {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
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
        
        /* Upcoming Sessions Scroll Container */
        .upcoming-sessions-container {
            max-height: 250px;
            overflow-y: auto;
            margin-top: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }
        
        .upcoming-sessions-content {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 20px;
        }
        
        /* Recent Activity Scroll Container */
        .recent-activity-container {
            max-height: 250px;
            overflow-y: auto;
            margin-top: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }
        
        .recent-activity-content {
            padding: 10px;
        }
        
        /* Activity Item */
        .activity-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.3s;
        }
        
        .activity-item:hover {
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
            font-size: 18px;
        }
        
        .activity-content h4 {
            font-size: 14px;
            margin-bottom: 5px;
            color: #1f2937;
            font-weight: 600;
        }
        
        .activity-content p {
            font-size: 12px;
            color: #6b7280;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #e5e7eb;
            margin-bottom: 15px;
        }
        
        .empty-state h4 {
            color: #6b7280;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #9ca3af;
            font-size: 14px;
        }
        
        /* Error Message */
        .error-message {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 1px solid #ef4444;
            color: #7f1d1d;
            padding: 15px 20px;
            border-radius: 10px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .error-message i {
            font-size: 24px;
            color: #ef4444;
        }
        
        /* Progress Bar */
        .progress-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 15px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 4px;
            transition: width 1s ease;
        }
        
        /* Engagement Score Classes */
        .score-excellent {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .score-good {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .score-fair {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #6b7280;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .score-poor {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #7f1d1d;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        /* Chart Container */
        .chart-container {
            height: 250px;
            position: relative;
            margin-top: 20px;
        }
        
        /* Custom Scrollbar Styles */
        .classes-scroll-container::-webkit-scrollbar,
        .upcoming-sessions-container::-webkit-scrollbar,
        .recent-activity-container::-webkit-scrollbar,
        .table-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .classes-scroll-container::-webkit-scrollbar-track,
        .upcoming-sessions-container::-webkit-scrollbar-track,
        .recent-activity-container::-webkit-scrollbar-track,
        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .classes-scroll-container::-webkit-scrollbar-thumb,
        .upcoming-sessions-container::-webkit-scrollbar-thumb,
        .recent-activity-container::-webkit-scrollbar-thumb,
        .table-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .classes-scroll-container::-webkit-scrollbar-thumb:hover,
        .upcoming-sessions-container::-webkit-scrollbar-thumb:hover,
        .recent-activity-container::-webkit-scrollbar-thumb:hover,
        .table-container::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .card-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                overflow-x: auto; /* Keep scroll for small screens */
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
            .dashboard-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
            
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
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 28px;
            }
            
            .card-icon {
                font-size: 30px;
                top: 20px;
                right: 20px;
            }
            
            .main-card,
            .sidebar-card {
                padding: 20px;
            }
            
            /* Adjust table for mobile */
            .data-table th,
            .data-table td {
                padding: 12px 15px;
                font-size: 13px;
            }
            
            /* Reduce min-width for mobile */
            .data-table td:nth-child(1),
            .data-table th:nth-child(1) {
                min-width: 180px;
            }
            
            .data-table td:nth-child(2),
            .data-table th:nth-child(2) {
                min-width: 100px;
            }
            
            /* Adjust scroll container heights for mobile */
            .classes-scroll-container {
                max-height: 350px;
            }
            
            .upcoming-sessions-container {
                max-height: 200px;
            }
            
            .recent-activity-container {
                max-height: 200px;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-value {
                font-size: 24px;
            }
            
            .content-wrapper {
                padding: 15px;
            }
            
            .topbar {
                padding: 0 15px;
            }
            
            .main-card,
            .sidebar-card {
                padding: 15px;
            }
            
            /* Further adjust scroll container heights for very small screens */
            .classes-scroll-container {
                max-height: 300px;
            }
            
            .upcoming-sessions-container {
                max-height: 180px;
            }
            
            .recent-activity-container {
                max-height: 180px;
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

            <h1>Emotion AI</h1>
            <p>Instructor Panel</p>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-title">Main Navigation</div>
            <a href="teacher_dashboard.php" class="menu-item active">
                <i class="menu-icon fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="menu-title">Teaching</div>
            <a href="teacher_my_classes.php" class="menu-item">
                <i class="menu-icon fas fa-chalkboard-teacher"></i>
                <span>My Classes</span>
            </a>
            <a href="teacher_live_classes.php" class="menu-item">
                <i class="menu-icon fas fa-video"></i>
                <span>Live Classes</span>
            </a>

            <a href="teacher_attendance.php" class="menu-item">
            <i class="menu-icon fas fa-clipboard-check"></i>
            <span>Attendance</span>
            </a>

            <div class="menu-title">Analytics</div>
            <a href="teacher_reports.php" class="menu-item">
                <i class="menu-icon fas fa-chart-bar"></i>
                <span>Reports</span>
            </a>

            <a href="teacher_announcement.php" class="menu-item">
                <i class="menu-icon fas fa-bullhorn"></i>
                <span>Announcement</span>
            </a>
            
            <div class="menu-title">Account</div>
            <a href="teacher_profile.php" class="menu-item">
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
                <h2>Teacher Dashboard</h2>
            </div>
            <div class="topbar-right">
                <div class="user-menu">
                    <button class="user-menu-btn" id="userMenuBtn">
                        <div class="user-avatar-small">
                            <?php echo getInitials($userData['full_name'] ?? 'IN'); ?>
                        </div>
                        <span><?php echo htmlspecialchars($userData['full_name'] ?? 'Instructor'); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <a href="teacher_profile.php" class="user-menu-item">
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
            <!-- Error Message Display -->
            <?php if (isset($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Error:</strong> <?php echo $error_message; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Stats Grid -->
            <div class="dashboard-grid">
                <div class="stat-card" data-color="purple">
                    <div class="card-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-value"><?php echo $quick_stats['total_classes'] ?? 0; ?></div>
                    <h3>Active Classes</h3>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> Last 30 days
                    </div>
                </div>
                
                <div class="stat-card" data-color="blue">
                    <div class="card-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-value"><?php echo $student_stats['total_students_enrolled'] ?? $quick_stats['total_students'] ?? 0; ?></div>
                    <h3>Total Students</h3>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> Enrolled
                    </div>
                </div>
                
                <div class="stat-card" data-color="green">
                    <div class="card-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value"><?php echo round($quick_stats['avg_engagement_30d'] ?? 0, 1); ?>%</div>
                    <h3>Avg Engagement</h3>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> 30-day average
                    </div>
                </div>
                
                <div class="stat-card" data-color="orange">
                    <div class="card-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $quick_stats['total_sessions_30d'] ?? 0; ?></div>
                    <h3>Sessions (30d)</h3>
                    <div class="stat-change neutral">
                        <i class="fas fa-minus"></i> Conducted
                    </div>
                </div>
            </div>
            
            <!-- Main Content Grid -->
            <div class="card-grid">
                <div class="main-card">
                    <h2>Created Classes</h2>
                    <?php if (empty($created_classes)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <h4>No Classes Created</h4>
                            <p>Create your first class to get started</p>
                        </div>
                    <?php else: ?>
                        <?php if (count($created_classes) >= 5): ?>
                        <div class="classes-scroll-container">
                        <?php endif; ?>
                            <table class="data-table" <?php echo (count($created_classes) < 5) ? 'style="width:100%;border:none;"' : ''; ?>>
                                <thead>
                                    <tr>
                                        <th>Class Name</th>
                                        <th>Class Code</th>
                                        <th>Students</th>
                                        <th>Sessions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($created_classes as $class): ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; font-size: 16px;">
                                                        <?php echo strtoupper(substr($class['class_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">
                                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                                        </div>
                                                        <div style="font-size: 12px; color: #6b7280;">
                                                            Created: <?php echo date('M d, Y', strtotime($class['created_at'])); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="font-family: monospace; font-weight: 600; color: #8b5cf6;">
                                                <?php echo htmlspecialchars($class['class_code']); ?>
                                            </td>
                                            <td>
                                                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f0f9ff; border-radius: 20px; font-weight: 600; font-size: 14px;">
                                                    <i class="fas fa-user-graduate"></i>
                                                    <?php echo $class['student_count'] ?? 0; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f8fafc; border-radius: 20px; font-weight: 600; font-size: 14px;">
                                                    <i class="fas fa-chart-bar"></i>
                                                    <?php echo $class['session_count'] ?? 0; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php if (count($created_classes) >= 5): ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- Class Engagement Summary -->
                    <?php if (!empty($engagement_summary)): ?>
                        <div style="margin-top: 40px;">
                            <div style="margin-bottom: 20px;">
                                <h2>Class Engagement Summary</h2>
                            </div>
                            
                            <div class="chart-container">
                                <canvas id="engagementChart"></canvas>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="sidebar-card">
                    <!-- Upcoming Sessions -->
                    <h3>Upcoming Sessions</h3>
                    <?php if (empty($upcoming_sessions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-alt"></i>
                            <h4>No Upcoming Sessions</h4>
                            <p>Schedule sessions for the next week</p>
                        </div>
                    <?php else: ?>
                        <?php if (count($upcoming_sessions) >= 5): ?>
                        <div class="upcoming-sessions-container">
                            <div class="upcoming-sessions-content">
                        <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 20px;">
                        <?php endif; ?>
                                <?php foreach ($upcoming_sessions as $session): ?>
                                    <div style="padding: 15px; background: #f8fafc; border-radius: 10px; border: 1px solid #e5e7eb;">
                                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                            <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 16px;">
                                                <i class="far fa-clock"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600; color: #1f2937; font-size: 14px;">
                                                    <?php echo htmlspecialchars($session['class_name']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #92400e; font-weight: 500;">
                                                    <?php echo date('D, M j', strtotime($session['start_time'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="font-size: 12px; color: #6b7280;">
                                            <i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($session['start_time'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                        <?php if (count($upcoming_sessions) >= 5): ?>
                            </div>
                        </div>
                        <?php else: ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- Recent Activity -->
                    <div style="margin-top: 30px;">
                        <h3>Recent Activity</h3>
                        <?php if (empty($recent_activity)): ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <h4>No Recent Activity</h4>
                                <p>Your recent sessions will appear here</p>
                            </div>
                        <?php else: ?>
                            <?php if (count($recent_activity) >= 5): ?>
                            <div class="recent-activity-container">
                                <div class="recent-activity-content">
                            <?php else: ?>
                            <div class="recent-activity" style="margin-top: 20px;">
                            <?php endif; ?>
                                    <?php foreach ($recent_activity as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <i class="fas fa-video"></i>
                                            </div>
                                            <div class="activity-content">
                                                <h4><?php echo htmlspecialchars($activity['class_name']); ?></h4>
                                                <p><?php echo relativeTime($activity['start_time']); ?> • <?php echo $activity['attendance_count'] ?? 0; ?> students</p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                            <?php if (count($recent_activity) >= 5): ?>
                                </div>
                            </div>
                            <?php else: ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div style="margin-top: 30px;">
                        <h3>Quick Actions</h3>
                        <div class="quick-actions">
                            <a href="teacher_my_classes.php" class="action-btn">
                                <i class="action-icon fas fa-plus-circle"></i>
                                <span>Create New Class</span>
                            </a>
                            <a href="teacher_live_classes.php" class="action-btn">
                                <i class="action-icon fas fa-video"></i>
                                <span>Live Classes</span>
                            </a>
                            <a href="teacher_attendance.php" class="action-btn">
                                <i class="action-icon fas fa-clipboard-check"></i>
                                <span>Attendance</span>
                            </a>
                            <a href="teacher_reports.php" class="action-btn">
                                <i class="action-icon fas fa-chart-bar"></i>
                                <span>View Reports</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
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
        
        // Engagement Chart
        <?php if (!empty($engagement_summary) && count($engagement_summary) > 0): ?>
        const engagementCtx = document.getElementById('engagementChart');
        if (engagementCtx) {
            const engagementChart = new Chart(engagementCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($engagement_summary, 'class_name')); ?>,
                    datasets: [
                        {
                            label: 'Engagement Score',
                            data: <?php echo json_encode(array_column($engagement_summary, 'avg_engagement')); ?>,
                            backgroundColor: '#8b5cf6',
                            borderColor: '#7c3aed',
                            borderWidth: 1,
                            borderRadius: 6
                        },
                        {
                            label: 'Happiness %',
                            data: <?php echo json_encode(array_column($engagement_summary, 'avg_happiness')); ?>,
                            backgroundColor: '#10b981',
                            borderColor: '#059669',
                            borderWidth: 1,
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    size: 11
                                },
                                padding: 20
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${context.parsed.y.toFixed(1)}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 10
                                },
                                maxRotation: 45,
                                minRotation: 0
                            }
                        },
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                },
                                font: {
                                    size: 10
                                }
                            },
                            grid: {
                                drawBorder: false
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Add hover effects to stat cards
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                const color = this.getAttribute('data-color');
                this.style.boxShadow = `0 8px 30px rgba(${getColorRGB(color)}, 0.2)`;
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.boxShadow = '0 4px 25px rgba(0,0,0,0.08)';
            });
        });
        
        // Helper function to get RGB from color name
        function getColorRGB(color) {
            const colors = {
                'purple': '139, 92, 246',
                'blue': '59, 130, 246',
                'green': '16, 185, 129',
                'red': '239, 68, 68',
                'orange': '245, 158, 11',
                'pink': '236, 72, 153'
            };
            return colors[color] || '139, 92, 246';
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + M to toggle sidebar
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sidebar.classList.toggle('active');
            }
            
            // Ctrl + C for create class
            if (e.ctrlKey && e.key === 'c') {
                e.preventDefault();
                window.location.href = 'teacher_my_classes.php';
            }
        });
    </script>
</body>
</html>