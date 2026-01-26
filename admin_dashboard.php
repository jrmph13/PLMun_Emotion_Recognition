<?php
include 'config.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Get admin info
$admin_full_name = $_SESSION['full_name'];
$admin_initials = getInitials($admin_full_name);

// Get system statistics with error handling
try {
    // Get total instructors - Count users with role='instructor'
    $sql = "SELECT COUNT(*) as total_instructors FROM users WHERE role = 'instructor'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_instructors = $result ? (int)$result['total_instructors'] : 0;

    // Get total students - Count users with role='student'
    $sql = "SELECT COUNT(*) as total_students FROM users WHERE role = 'student'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_students = $result ? (int)$result['total_students'] : 0;

    // Get total classes (only active ones)
    $sql = "SELECT COUNT(*) as total_classes FROM classes WHERE is_active = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_classes = $result ? (int)$result['total_classes'] : 0;

    // Get active sessions - Using your live_sessions table
    $sql = "SELECT COUNT(*) as active_sessions FROM live_sessions WHERE status = 'active'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $active_sessions = $result ? (int)$result['active_sessions'] : 0;

    // Get total users (instructors + students)
    $total_users = $total_instructors + $total_students;

    // Get active sessions details
    $sql = "SELECT ls.session_name, c.class_name, u.full_name as instructor_name, 
                   ls.start_time, COUNT(DISTINCT lsp.user_id) as active_students
            FROM live_sessions ls 
            JOIN classes c ON ls.class_id = c.id 
            JOIN users u ON c.instructor_id = u.id 
            LEFT JOIN live_session_participants lsp ON ls.id = lsp.session_id AND lsp.user_role = 'student'
            WHERE ls.status = 'active' 
            GROUP BY ls.id 
            ORDER BY ls.start_time DESC 
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $active_sessions_details = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get system performance metrics for today
    $sql = "SELECT 
            COUNT(DISTINCT ls.id) as total_sessions_today,
            AVG(ses.engagement_score) as avg_engagement,
            COUNT(DISTINCT lsp.user_id) as active_students_today,
            COUNT(DISTINCT c.instructor_id) as active_instructors_today
            FROM live_sessions ls 
            LEFT JOIN session_engagement_summary ses ON ls.id = ses.session_id 
            LEFT JOIN live_session_participants lsp ON ls.id = lsp.session_id AND lsp.user_role = 'student'
            LEFT JOIN classes c ON ls.class_id = c.id 
            WHERE DATE(ls.start_time) = CURDATE()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $system_metrics = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get system uptime (successful sessions)
    $sql = "SELECT 
            COUNT(*) as total_sessions_this_month,
            SUM(CASE WHEN end_time IS NOT NULL THEN 1 ELSE 0 END) as completed_sessions
            FROM live_sessions 
            WHERE MONTH(start_time) = MONTH(CURRENT_DATE()) 
            AND YEAR(start_time) = YEAR(CURRENT_DATE())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $uptime_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($uptime_data && $uptime_data['total_sessions_this_month'] > 0) {
        $system_uptime = round(($uptime_data['completed_sessions'] / $uptime_data['total_sessions_this_month']) * 100, 1);
    } else {
        $system_uptime = 100;
    }

    // Get instructor performance
    $sql = "SELECT u.full_name, 
                   COUNT(DISTINCT c.id) as class_count,
                   COUNT(DISTINCT ls.id) as session_count,
                   AVG(ses.engagement_score) as avg_engagement
            FROM users u 
            LEFT JOIN classes c ON u.id = c.instructor_id AND c.is_active = 1
            LEFT JOIN live_sessions ls ON c.id = ls.class_id 
            LEFT JOIN session_engagement_summary ses ON ls.id = ses.session_id 
            WHERE u.role = 'instructor' 
            GROUP BY u.id 
            ORDER BY avg_engagement DESC 
            LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $instructor_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent system activity
    $sql = "SELECT ls.session_name, c.class_name, u.full_name as instructor_name, 
                   ls.start_time, ls.end_time
            FROM live_sessions ls 
            JOIN classes c ON ls.class_id = c.id 
            JOIN users u ON c.instructor_id = u.id 
            ORDER BY ls.start_time DESC 
            LIMIT 15";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Set default values if database query fails
    $total_instructors = 0;
    $total_students = 0;
    $total_classes = 0;
    $active_sessions = 0;
    $total_users = 0;
    $active_sessions_details = [];
    $system_metrics = ['total_sessions_today' => 0, 'avg_engagement' => 0, 'active_students_today' => 0, 'active_instructors_today' => 0];
    $system_uptime = 100;
    $instructor_performance = [];
    $recent_activity = [];
    
    // Log the error
    error_log("Database error in admin_dashboard.php: " . $e->getMessage());
}

// Calculate growth rates (you can adjust these formulas based on actual data)
$monthly_instructor_growth = $total_instructors > 0 ? round($total_instructors * 0.1) : 0;
$weekly_student_growth = $total_students > 0 ? round($total_students * 0.05) : 0;
$daily_class_growth = $total_classes > 0 ? round($total_classes * 0.03) : 0;

// Calculate user growth rate
if ($total_users > 0) {
    $instructor_growth = $total_instructors * 0.1;
    $student_growth = $total_students * 0.05;
    $total_growth = $instructor_growth + $student_growth;
    $user_growth_rate = round(($total_growth / $total_users) * 100, 1);
} else {
    $user_growth_rate = 0;
}

// Set default values for engagement if null
$avg_engagement = isset($system_metrics['avg_engagement']) && $system_metrics['avg_engagement'] ? 
                  round($system_metrics['avg_engagement']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Emotion System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        html, body {
            height: 100%;
            overflow-x: hidden;
        }
        
        body {
            background: #f5f5f5;
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar Styles - MATCHING admin_user_management.php */
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
        
        /* Logo Styles - MATCHING admin_user_management.php */
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
            color: rgba(255,255,255,0.7);
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
            color: white;
        }
        
        .user-info p {
            font-size: 12px;
            opacity: 0.8;
            background: rgba(139, 92, 246, 0.1);
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
            color: white;
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
        
        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            display: flex;
            flex-direction: column;
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
            left: 280px;
            right: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            flex-shrink: 0;
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
            padding: 110px 30px 30px 30px;
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }
        
        /* Dashboard Grid - MATCHING STAT CARDS COLOR SCHEME */
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
            height: 180px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
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
            margin-bottom: 10px;
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
        }
        
        @media (max-width: 1200px) {
            .card-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .main-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            max-height: 600px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .main-card h2 {
            color: #1f2937;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            font-weight: 700;
            font-size: 22px;
            flex-shrink: 0;
        }
        
        .sidebar-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 300px;
        }
        
        .sidebar-card h3 {
            color: #1f2937;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            font-weight: 600;
            flex-shrink: 0;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
            min-height: 250px;
            max-height: 300px;
            overflow: hidden;
            position: relative;
        }
        
        .quick-actions.scrollable {
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .quick-actions.scrollable::-webkit-scrollbar {
            width: 6px;
        }
        
        .quick-actions.scrollable::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .quick-actions.scrollable::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
        }
        
        .quick-actions.scrollable::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #1d4ed8 100%);
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
            flex-shrink: 0;
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
        
        /* Active Sessions Container */
        .active-sessions-container {
            flex: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        
        .active-sessions-content {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            flex: 1;
            overflow: hidden;
            padding: 5px 5px 10px 0;
        }
        
        .active-sessions-content.scrollable {
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .active-sessions-content.scrollable::-webkit-scrollbar {
            width: 8px;
        }
        
        .active-sessions-content.scrollable::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .active-sessions-content.scrollable::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
        }
        
        .active-sessions-content.scrollable::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #1d4ed8 100%);
        }
        
        /* Class Cards */
        .class-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border: 2px solid transparent;
            height: 200px;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .class-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            border-color: #8b5cf6;
        }
        
        .class-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 15px;
            flex-shrink: 0;
        }
        
        .class-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 8px;
            color: white;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            height: 40px;
        }
        
        .class-instructor {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .class-body {
            padding: 15px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .class-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-label {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .stat-value-small {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
        }
        
        /* Recent Activity */
        .recent-activity-container {
            flex: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        
        .recent-activity {
            flex: 1;
            overflow: hidden;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
        }
        
        .recent-activity.scrollable {
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .recent-activity.scrollable::-webkit-scrollbar {
            width: 6px;
        }
        
        .recent-activity.scrollable::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .recent-activity.scrollable::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
        }
        
        .recent-activity.scrollable::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #1d4ed8 100%);
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 12px 10px;
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.3s;
            flex-shrink: 0;
            min-height: 60px;
        }
        
        .activity-item:hover {
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: white;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .activity-content h4 {
            font-size: 13px;
            margin-bottom: 4px;
            color: #1f2937;
            font-weight: 600;
            line-height: 1.3;
        }
        
        .activity-content p {
            font-size: 11px;
            color: #6b7280;
            line-height: 1.3;
        }
        
        /* Chart Styles */
        .chart-container {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            height: 350px;
        }
        
        .chart-title {
            color: #1f2937;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #e5e7eb;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 18px;
            color: #4b5563;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            font-size: 14px;
            color: #9ca3af;
        }
        
        /* Top Instructors */
        .instructor-list {
            flex: 1;
            overflow: hidden;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
            margin-top: 15px;
            min-height: 150px;
        }
        
        .instructor-list.scrollable {
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .instructor-list.scrollable::-webkit-scrollbar {
            width: 6px;
        }
        
        .instructor-list.scrollable::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .instructor-list.scrollable::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
        }
        
        .instructor-list.scrollable::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #1d4ed8 100%);
        }
        
        .instructor-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
        }
        
        .instructor-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .instructor-info {
            flex: 1;
        }
        
        .instructor-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
            font-size: 14px;
        }
        
        .instructor-meta {
            font-size: 11px;
            color: #6b7280;
        }
        
        .instructor-stats {
            text-align: right;
            flex-shrink: 0;
        }
        
        .instructor-engagement {
            font-weight: 700;
            color: #8b5cf6;
            font-size: 16px;
        }
        
        .engagement-label {
            font-size: 11px;
            color: #10b981;
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
        
        /* Responsive */
        @media (max-width: 1200px) {
            .card-grid {
                grid-template-columns: 1fr;
            }
            
            .active-sessions-content {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
            
            .sidebar-card {
                min-height: 280px;
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
            .sidebar-header p,
            .menu-title,
            .menu-item span:not(.menu-icon) {
                display: none;
            }
            
            .sidebar-logo {
                margin: 10px auto;
            }
            
            .sidebar-logo img {
                width: 40px;
                height: 40px;
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
            .sidebar:hover .sidebar-header p,
            .sidebar:hover .sidebar-logo img {
                display: block;
            }
            
            .sidebar:hover .sidebar-logo {
                display: flex;
            }
            
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
            
            .dashboard-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
            
            .active-sessions-content {
                grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            }
            
            .class-card {
                height: 190px;
            }
            
            .sidebar-card {
                min-height: 260px;
            }
        }
        
        @media (max-width: 768px) {
            .topbar {
                left: 0;
            }
            
            .content-wrapper {
                padding: 100px 20px 20px 20px;
            }
            
            .topbar {
                height: 70px;
                padding: 0 20px;
            }
            
            .topbar-left h2 {
                font-size: 20px;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .active-sessions-content {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .main-card,
            .sidebar-card {
                padding: 20px;
                max-height: 550px;
            }
            
            .class-card {
                height: 180px;
            }
            
            .sidebar-card {
                min-height: 240px;
            }
        }
        
        @media (max-width: 480px) {
            .stat-value {
                font-size: 28px;
            }
            
            .chart-container {
                height: 300px;
            }
            
            .class-card {
                height: 170px;
            }
            
            .main-card,
            .sidebar-card {
                padding: 15px;
                max-height: 500px;
            }
            
            .sidebar-card {
                min-height: 220px;
            }
            
            .quick-actions {
                min-height: 200px;
                max-height: 250px;
            }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            50% { transform: scale(1.05); box-shadow: 0 8px 25px rgba(139, 92, 246, 0.2); }
            100% { transform: scale(1); box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <!-- Logo Section - MATCHING admin_user_management.php -->
            <div class="sidebar-logo">
                <img src="image/logo1.png" alt="Emotion AI Logo">
            </div>
            
            <h1>PLMUN Emotion Monitoring</h1>
            <p>Administration Panel</p>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-title">Main Navigation</div>
            <a href="admin_dashboard.php" class="menu-item active">
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
            <a href="admin_system_settings.php" class="menu-item">
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
                <h2>Dashboard Overview</h2>
            </div>
            <div class="topbar-right">
                <div class="user-menu">
                    <button class="user-menu-btn" id="userMenuBtn">
                        <div class="user-avatar-small">
                            <?php echo $admin_initials; ?>
                        </div>
                        <span><?php echo htmlspecialchars($admin_full_name); ?></span>
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
            <!-- Stats Grid -->
            <div class="dashboard-grid">
                <div class="stat-card" data-color="purple">
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                    <h3>Total Users</h3>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> <?php echo $user_growth_rate; ?>% growth rate
                    </div>
                </div>
                
                <div class="stat-card" data-color="blue">
                    <div class="card-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-value"><?php echo $active_sessions; ?></div>
                    <h3>Active Sessions</h3>
                    <div class="stat-change positive">
                        <i class="fas fa-play-circle"></i> Live now
                    </div>
                </div>
                
                <div class="stat-card" data-color="green">
                    <div class="card-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_classes; ?></div>
                    <h3>Active Classes</h3>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> +<?php echo $daily_class_growth; ?> today
                    </div>
                </div>
                
                <div class="stat-card" data-color="red">
                    <div class="card-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="stat-value"><?php echo $system_uptime; ?>%</div>
                    <h3>System Uptime</h3>
                    <div class="stat-change positive">
                        <i class="fas fa-check-circle"></i> Optimal
                    </div>
                </div>
                
                <div class="stat-card" data-color="orange">
                    <div class="card-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_instructors; ?></div>
                    <h3>Instructors</h3>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> +<?php echo $monthly_instructor_growth; ?> this month
                    </div>
                </div>
                
                <div class="stat-card" data-color="pink">
                    <div class="card-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-value"><?php echo $total_students; ?></div>
                    <h3>Students</h3>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> +<?php echo $weekly_student_growth; ?> this week
                    </div>
                </div>
            </div>
            
            <!-- Main Content Grid -->
            <div class="card-grid">
                <!-- Left Column -->
                <div>
                    <!-- Active Sessions -->
                    <?php if (count($active_sessions_details) > 0): ?>
                        <div class="main-card">
                            <h2>Active Sessions (<?php echo count($active_sessions_details); ?>)</h2>
                            <div class="active-sessions-container">
                                <div class="active-sessions-content <?php echo count($active_sessions_details) >= 3 ? 'scrollable' : ''; ?>">
                                    <?php foreach ($active_sessions_details as $session): ?>
                                        <?php 
                                        $start_time = strtotime($session['start_time']);
                                        $current_time = time();
                                        $duration = $current_time - $start_time;
                                        $hours = floor($duration / 3600);
                                        $minutes = floor(($duration % 3600) / 60);
                                        ?>
                                        
                                        <div class="class-card">
                                            <div class="class-header">
                                                <div class="class-title"><?php echo htmlspecialchars($session['session_name']); ?></div>
                                                <div class="class-instructor">
                                                    <i class="fas fa-user-tie"></i>
                                                    <?php echo htmlspecialchars($session['instructor_name']); ?>
                                                </div>
                                            </div>
                                            <div class="class-body">
                                                <div class="class-stats">
                                                    <div class="stat-item">
                                                        <div class="stat-label">Class</div>
                                                        <div class="stat-value-small"><?php echo htmlspecialchars($session['class_name']); ?></div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-label">Students</div>
                                                        <div class="stat-value-small"><?php echo $session['active_students']; ?></div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-label">Started</div>
                                                        <div class="stat-value-small"><?php echo date('H:i', $start_time); ?></div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-label">Duration</div>
                                                        <div class="stat-value-small">
                                                            <?php echo ($hours > 0 ? $hours . 'h ' : '') . $minutes . 'm'; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); padding: 8px; border-radius: 6px; margin-top: 8px; border: 1px solid #bae6fd;">
                                                    <span style="display: flex; align-items: center; gap: 6px; color: #0369a1; font-size: 11px; font-weight: 600;">
                                                        <span style="width: 6px; height: 6px; background: #10b981; border-radius: 50%;"></span>
                                                        Active - Emotion Detection Running
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- User Distribution Chart -->
                    <div class="main-card" style="margin-top: 30px;">
                        <h2>User Distribution</h2>
                        <div class="chart-container">
                            <div class="chart-title">Instructors vs Students</div>
                            <canvas id="usersChart"></canvas>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="main-card" style="margin-top: 30px;">
                        <h2>Recent Activity</h2>
                        <div class="recent-activity-container">
                            <div class="recent-activity <?php echo count($recent_activity) >= 5 ? 'scrollable' : ''; ?>">
                                <?php if (count($recent_activity) > 0): ?>
                                    <?php foreach ($recent_activity as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <i class="fas fa-video"></i>
                                            </div>
                                            <div class="activity-content">
                                                <h4><?php echo htmlspecialchars($activity['session_name']); ?></h4>
                                                <p>
                                                    <?php echo htmlspecialchars($activity['instructor_name']); ?> - 
                                                    <?php echo date('H:i', strtotime($activity['start_time'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state" style="padding: 20px;">
                                        <i class="fas fa-info-circle"></i>
                                        <p>No recent activity</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div>
                    <!-- Quick Actions -->
                    <div class="sidebar-card">
                        <h3>Quick Actions</h3>
                        <div class="quick-actions">
                            <a href="admin_user_management.php" class="action-btn">
                                <i class="action-icon fas fa-users-cog"></i>
                                <span>Manage Users</span>
                            </a>
                            <a href="admin_analytics_reports.php" class="action-btn">
                                <i class="action-icon fas fa-chart-line"></i>
                                <span>View Analytics</span>
                            </a>
                            <a href="admin_system_settings.php" class="action-btn">
                                <i class="action-icon fas fa-cogs"></i>
                                <span>System Settings</span>
                            </a>
                            <a href="admin_audit_logs.php" class="action-btn">
                                <i class="action-icon fas fa-clipboard-list"></i>
                                <span>Audit Logs</span>
                            </a>
                            <a href="admin_profile.php" class="action-btn">
                                <i class="action-icon fas fa-user-circle"></i>
                                <span>My Profile</span>
                            </a>
                        </div>
                    </div>

                    <!-- Top Instructors -->
                    <?php if (count($instructor_performance) > 0): ?>
                        <div class="sidebar-card">
                            <h3>Top Instructors</h3>
                            <div class="instructor-list">
                                <?php foreach ($instructor_performance as $instructor): ?>
                                    <?php if ($instructor['full_name']): ?>
                                        <div class="instructor-item">
                                            <div class="instructor-info">
                                                <div class="instructor-name"><?php echo htmlspecialchars($instructor['full_name']); ?></div>
                                                <div class="instructor-meta">
                                                    <?php echo $instructor['class_count'] ?? 0; ?> classes • 
                                                    <?php echo $instructor['session_count'] ?? 0; ?> sessions
                                                </div>
                                            </div>
                                            <div class="instructor-stats">
                                                <div class="instructor-engagement"><?php echo round($instructor['avg_engagement'] ?? 0, 1); ?>%</div>
                                                <div class="engagement-label">Engagement</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
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
        
        // Initialize Users Chart
        const usersCtx = document.getElementById('usersChart').getContext('2d');
        const usersChart = new Chart(usersCtx, {
            type: 'doughnut',
            data: {
                labels: ['Instructors (<?php echo $total_instructors; ?>)', 'Students (<?php echo $total_students; ?>)'],
                datasets: [{
                    data: [<?php echo $total_instructors; ?>, <?php echo $total_students; ?>],
                    backgroundColor: [
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(59, 130, 246, 0.8)'
                    ],
                    borderColor: [
                        'rgba(139, 92, 246, 1)',
                        'rgba(59, 130, 246, 1)'
                    ],
                    borderWidth: 2,
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 12,
                                family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = <?php echo $total_users; ?>;
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value} users (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        
        // Simulate live updates for active sessions
        function updateLiveCount() {
            const activeSessionsCard = document.querySelector('.stat-card[data-color="blue"] .stat-value');
            
            // In a real app, this would be an API call
            // For demo, simulate small random changes
            const current = parseInt(activeSessionsCard.textContent);
            const variation = Math.floor(Math.random() * 3) - 1; // -1, 0, or 1
            const newCount = Math.max(0, current + variation);
            
            activeSessionsCard.textContent = newCount;
        }
        
        // Update every 30 seconds
        setInterval(updateLiveCount, 30000);
        
        // Add hover effects to cards
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
        
        // Update progress bar animation
        const progressFill = document.querySelector('.progress-fill');
        setTimeout(() => {
            progressFill.style.width = '<?php echo min(100, round(($total_classes * 2) + ($active_sessions * 5) + ($total_users * 0.5))); ?>%';
        }, 500);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + M to toggle sidebar
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sidebar.classList.toggle('active');
            }
            
            // Ctrl + U for user management
            if (e.ctrlKey && e.key === 'u') {
                e.preventDefault();
                window.location.href = 'admin_user_management.php';
            }
            
            // Ctrl + A for analytics
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                window.location.href = 'admin_analytics_reports.php';
            }
            
            // Ctrl + S for system settings
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                window.location.href = 'admin_system_settings.php';
            }
            
            // Ctrl + L for audit logs
            if (e.ctrlKey && e.key === 'l') {
                e.preventDefault();
                window.location.href = 'admin_audit_logs.php';
            }
            
            // Ctrl + P for profile
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.location.href = 'admin_profile.php';
            }
            
            // Escape to close dropdowns
            if (e.key === 'Escape') {
                userMenuDropdown.classList.remove('show');
            }
        });
        
        // Initialize the page
        console.log('Admin Dashboard Initialized');
        console.log('Keyboard shortcuts:');
        console.log('Ctrl+M - Toggle sidebar');
        console.log('Ctrl+U - User Management');
        console.log('Ctrl+A - Analytics & Reports');
        console.log('Ctrl+S - System Settings');
        console.log('Ctrl+L - Audit Logs');
        console.log('Ctrl+P - My Profile');
        
        // Fix for iOS Safari 100vh issue
        function setRealViewportHeight() {
            const vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        }
        
        setRealViewportHeight();
        window.addEventListener('resize', setRealViewportHeight);
        window.addEventListener('orientationchange', setRealViewportHeight);
        
        // Auto-scroll to bottom for active sessions container
        function scrollActiveSessionsToBottom() {
            const container = document.querySelector('.active-sessions-content.scrollable');
            if (container) {
                // Scroll to show latest sessions (scroll to bottom)
                setTimeout(() => {
                    container.scrollTop = container.scrollHeight;
                }, 100);
            }
        }
        
        // Run after page loads
        setTimeout(scrollActiveSessionsToBottom, 500);
        
        // Logo fallback if image doesn't load
        const logo = document.querySelector('.sidebar-logo img');
        if (logo) {
            logo.addEventListener('error', function() {
                console.log('Logo image failed to load');
                // Create a fallback using initials
                const fallbackDiv = document.createElement('div');
                fallbackDiv.innerHTML = 'PLMUN';
                fallbackDiv.style.width = '70px';
                fallbackDiv.style.height = '70px';
                fallbackDiv.style.background = 'white';
                fallbackDiv.style.borderRadius = '50%';
                fallbackDiv.style.display = 'flex';
                fallbackDiv.style.alignItems = 'center';
                fallbackDiv.style.justifyContent = 'center';
                fallbackDiv.style.fontWeight = 'bold';
                fallbackDiv.style.color = '#8b5cf6';
                fallbackDiv.style.fontSize = '16px';
                this.parentNode.replaceChild(fallbackDiv, this);
            });
        }
        
        // Add smooth hover effects to action buttons
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(5px)';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
            });
        });
        
        // Check scrollable containers and add scroll indicator if needed
        function checkScrollableContainers() {
            document.querySelectorAll('.scrollable').forEach(container => {
                if (container.scrollHeight > container.clientHeight + 10) {
                    // Add subtle scroll indicator
                    container.style.borderRight = '1px solid #e5e7eb';
                }
            });
        }
        
        // Check containers after content loads
        setTimeout(checkScrollableContainers, 1000);
        
        // Add animation to stat cards on load
        document.querySelectorAll('.stat-card').forEach((card, index) => {
            setTimeout(() => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            }, 100);
        });
        
        // Ensure all quick action buttons are visible
        function ensureQuickActionsVisible() {
            const quickActions = document.querySelector('.quick-actions');
            if (quickActions) {
                // Force container to be tall enough to show all buttons
                const buttons = quickActions.querySelectorAll('.action-btn');
                const buttonHeight = buttons[0] ? buttons[0].offsetHeight : 50;
                const gap = 12; // gap from CSS
                const totalHeight = (buttons.length * buttonHeight) + ((buttons.length - 1) * gap);
                
                // Set minimum height to show all buttons
                quickActions.style.minHeight = Math.max(250, totalHeight + 20) + 'px';
            }
        }
        
        // Run after page loads
        setTimeout(ensureQuickActionsVisible, 300);
    </script>
</body>
</html>