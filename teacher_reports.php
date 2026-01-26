<?php
// ==================== TEACHER REPORTS PAGE ====================
require_once 'config.php';

// Require instructor or admin role
requireInstructor();

// Get current user data
$userData = getUserData();
$userId = $_SESSION['user_id'];

// Get filter parameters
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : null;
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'session';

// Initialize arrays
$report_data = [];
$class_list = [];
$session_list = [];
$student_list = [];
$stats = [];

try {
    // Get instructor's classes for filters
    $stmt = $pdo->prepare("
        SELECT c.id, c.class_name, c.class_code
        FROM " . TABLE_CLASSES . " c
        WHERE c.instructor_id = ? AND c.is_active = 1
        ORDER BY c.class_name
    ");
    $stmt->execute([$userId]);
    $class_list = $stmt->fetchAll();

    // Get sessions based on filters
    $sessions_query = "
        SELECT ls.id, ls.session_name, ls.start_time, ls.end_time, ls.status,
               c.class_name, c.class_code,
               COUNT(DISTINCT sa.student_id) as attendance_count,
               AVG(ses.engagement_score) as avg_engagement
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id
        WHERE c.instructor_id = ?
    ";
    
    $sessions_params = [$userId];
    
    if ($class_id) {
        $sessions_query .= " AND ls.class_id = ?";
        $sessions_params[] = $class_id;
    }
    
    if ($date_from && $date_to) {
        $sessions_query .= " AND DATE(ls.start_time) BETWEEN ? AND ?";
        $sessions_params[] = $date_from;
        $sessions_params[] = $date_to;
    }
    
    $sessions_query .= " GROUP BY ls.id ORDER BY ls.start_time DESC";
    
    $stmt = $pdo->prepare($sessions_query);
    $stmt->execute($sessions_params);
    $session_list = $stmt->fetchAll();

    // Get students for filters
    $students_query = "
        SELECT DISTINCT s.id, s.student_number, u.full_name
        FROM " . TABLE_STUDENTS . " s
        JOIN " . TABLE_USERS . " u ON s.user_id = u.id
        JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
        JOIN " . TABLE_CLASSES . " c ON ce.class_id = c.id
        WHERE c.instructor_id = ?
        ORDER BY u.full_name
    ";
    
    $stmt = $pdo->prepare($students_query);
    $stmt->execute([$userId]);
    $student_list = $stmt->fetchAll();

    // Fetch report data based on report type
    switch ($report_type) {
        case 'session':
            if ($session_id) {
                // Session Detail Report
                $stmt = $pdo->prepare("
                    SELECT 
                        ls.session_name,
                        ls.start_time,
                        ls.end_time,
                        ls.status,
                        c.class_name,
                        c.class_code,
                        COUNT(DISTINCT sa.student_id) as total_students,
                        COUNT(DISTINCT ed.id) as emotion_samples,
                        AVG(ses.engagement_score) as avg_engagement,
                        AVG(ses.happy_percent) as avg_happy,
                        AVG(ses.bored_percent) as avg_bored,
                        AVG(ses.neutral_percent) as avg_neutral,
                        SUM(CASE WHEN ed.facial_emotion = 'happy' THEN 1 ELSE 0 END) as happy_count,
                        SUM(CASE WHEN ed.facial_emotion = 'sad' THEN 1 ELSE 0 END) as sad_count,
                        SUM(CASE WHEN ed.facial_emotion = 'angry' THEN 1 ELSE 0 END) as angry_count,
                        SUM(CASE WHEN ed.facial_emotion = 'bored' THEN 1 ELSE 0 END) as bored_count,
                        SUM(CASE WHEN ed.facial_emotion = 'neutral' THEN 1 ELSE 0 END) as neutral_count
                    FROM " . TABLE_LIVE_SESSIONS . " ls
                    JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
                    LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id
                    LEFT JOIN " . TABLE_EMOTION_DATA . " ed ON ls.id = ed.session_id
                    LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id
                    WHERE ls.id = ? AND c.instructor_id = ?
                ");
                $stmt->execute([$session_id, $userId]);
                $report_data = $stmt->fetch();
                
                // Get student-wise engagement for this session
                $stmt = $pdo->prepare("
                    SELECT 
                        s.id as student_id,
                        s.student_number,
                        u.full_name,
                        ses.engagement_score,
                        ses.happy_percent,
                        ses.bored_percent,
                        ses.neutral_percent,
                        ses.average_emotion,
                        sa.join_time,
                        sa.leave_time
                    FROM " . TABLE_STUDENTS . " s
                    JOIN " . TABLE_USERS . " u ON s.user_id = u.id
                    LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON s.id = ses.student_id AND ses.session_id = ?
                    LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON s.id = sa.student_id AND sa.session_id = ?
                    WHERE EXISTS (
                        SELECT 1 FROM " . TABLE_CLASS_ENROLLMENTS . " ce 
                        WHERE ce.student_id = s.id 
                        AND ce.class_id = (SELECT class_id FROM " . TABLE_LIVE_SESSIONS . " WHERE id = ?)
                    )
                    ORDER BY u.full_name
                ");
                $stmt->execute([$session_id, $session_id, $session_id]);
                $student_details = $stmt->fetchAll();
                
                $report_data['student_details'] = $student_details;
            }
            break;

        case 'student':
            if ($student_id) {
                // Student Engagement Report
                $stmt = $pdo->prepare("
                    SELECT 
                        s.id as student_id,
                        s.student_number,
                        u.full_name,
                        COUNT(DISTINCT ls.id) as total_sessions,
                        COUNT(DISTINCT sa.session_id) as attended_sessions,
                        AVG(ses.engagement_score) as avg_engagement,
                        AVG(ses.happy_percent) as avg_happy,
                        AVG(ses.bored_percent) as avg_bored,
                        GROUP_CONCAT(DISTINCT c.class_name SEPARATOR ', ') as enrolled_classes
                    FROM " . TABLE_STUDENTS . " s
                    JOIN " . TABLE_USERS . " u ON s.user_id = u.id
                    JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
                    JOIN " . TABLE_CLASSES . " c ON ce.class_id = c.id
                    LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id 
                        AND ls.start_time BETWEEN ? AND ?
                    LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id AND sa.student_id = s.id
                    LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id AND ses.student_id = s.id
                    WHERE s.id = ? AND c.instructor_id = ?
                    GROUP BY s.id
                ");
                $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59', $student_id, $userId]);
                $report_data = $stmt->fetch();
                
                // Get session-wise details for this student
                $stmt = $pdo->prepare("
                    SELECT 
                        ls.id as session_id,
                        ls.session_name,
                        ls.start_time,
                        c.class_name,
                        ses.engagement_score,
                        ses.happy_percent,
                        ses.bored_percent,
                        ses.neutral_percent,
                        ses.average_emotion,
                        sa.join_time,
                        sa.leave_time,
                        TIMESTAMPDIFF(MINUTE, sa.join_time, sa.leave_time) as duration_minutes
                    FROM " . TABLE_LIVE_SESSIONS . " ls
                    JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
                    LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id AND ses.student_id = ?
                    LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id AND sa.student_id = ?
                    WHERE c.instructor_id = ? 
                    AND ls.start_time BETWEEN ? AND ?
                    ORDER BY ls.start_time DESC
                ");
                $stmt->execute([$student_id, $student_id, $userId, $date_from . ' 00:00:00', $date_to . ' 23:59:59']);
                $session_details = $stmt->fetchAll();
                
                $report_data['session_details'] = $session_details;
            }
            break;

        case 'attendance':
            // Attendance Report
            $attendance_query = "
                SELECT 
                    c.id as class_id,
                    c.class_name,
                    c.class_code,
                    COUNT(DISTINCT ls.id) as total_sessions,
                    COUNT(DISTINCT ce.student_id) as total_students,
                    AVG(att.attendance_rate) as avg_attendance_rate,
                    MIN(att.attendance_rate) as min_attendance_rate,
                    MAX(att.attendance_rate) as max_attendance_rate
                FROM " . TABLE_CLASSES . " c
                LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id 
                    AND ls.start_time BETWEEN ? AND ?
                LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
                LEFT JOIN (
                    SELECT 
                        ls.class_id,
                        sa.student_id,
                        COUNT(DISTINCT sa.session_id) * 100.0 / COUNT(DISTINCT ls.id) as attendance_rate
                    FROM " . TABLE_LIVE_SESSIONS . " ls
                    LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id
                    WHERE ls.start_time BETWEEN ? AND ?
                    GROUP BY ls.class_id, sa.student_id
                ) att ON c.id = att.class_id
                WHERE c.instructor_id = ?
                GROUP BY c.id
                ORDER BY avg_attendance_rate DESC
            ";
            
            $stmt = $pdo->prepare($attendance_query);
            $stmt->execute([
                $date_from . ' 00:00:00', $date_to . ' 23:59:59',
                $date_from . ' 00:00:00', $date_to . ' 23:59:59',
                $userId
            ]);
            $report_data = $stmt->fetchAll();
            
            // Get detailed attendance if class is selected
            if ($class_id) {
                $stmt = $pdo->prepare("
                    SELECT 
                        s.id as student_id,
                        s.student_number,
                        u.full_name,
                        COUNT(DISTINCT ls.id) as total_sessions,
                        COUNT(DISTINCT sa.session_id) as attended_sessions,
                        CASE 
                            WHEN COUNT(DISTINCT ls.id) > 0 
                            THEN ROUND(COUNT(DISTINCT sa.session_id) * 100.0 / COUNT(DISTINCT ls.id), 2)
                            ELSE 0 
                        END as attendance_rate,
                        AVG(TIMESTAMPDIFF(MINUTE, sa.join_time, sa.leave_time)) as avg_duration
                    FROM " . TABLE_STUDENTS . " s
                    JOIN " . TABLE_USERS . " u ON s.user_id = u.id
                    JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
                    LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON ce.class_id = ls.class_id 
                        AND ls.start_time BETWEEN ? AND ?
                    LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id AND sa.student_id = s.id
                    WHERE ce.class_id = ?
                    GROUP BY s.id
                    ORDER BY attendance_rate DESC, u.full_name
                ");
                $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59', $class_id]);
                $attendance_details = $stmt->fetchAll();
                
                // Add details to first report item
                if (isset($report_data[0])) {
                    $report_data[0]['attendance_details'] = $attendance_details;
                }
            }
            break;
    }

    // Get overall statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT c.id) as total_classes,
            COUNT(DISTINCT ls.id) as total_sessions,
            COUNT(DISTINCT ce.student_id) as total_students,
            COALESCE(AVG(ses.engagement_score), 0) as avg_engagement,
            COUNT(DISTINCT sa.session_id) as attendance_records
        FROM " . TABLE_CLASSES . " c
        LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id 
            AND ls.start_time BETWEEN ? AND ?
        LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id
        LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id
        WHERE c.instructor_id = ?
    ");
    $stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59', $userId]);
    $stats = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Reports Query Error: " . $e->getMessage());
    $error_message = "An error occurred while loading report data.";
}

// Set page title
$page_title = "Reports - Emotion AI System";

// Log report access
logAuditTrail(
    $userId,
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    "Accessed {$report_type} report",
    null,
    null,
    [
        'class_id' => $class_id,
        'session_id' => $session_id,
        'student_id' => $student_id,
        'date_from' => $date_from,
        'date_to' => $date_to
    ]
);

// Helper functions for PHP
function getEngagementColor($score) {
    if ($score >= 80) return '#10b981';
    if ($score >= 60) return '#f59e0b';
    return '#ef4444';
}

function getAttendanceColor($rate) {
    if ($rate >= 90) return '#10b981';
    if ($rate >= 75) return '#3b82f6';
    if ($rate >= 60) return '#f59e0b';
    return '#ef4444';
}

function getEmotionBadge($emotion) {
    $badges = [
        'happy' => '<span class="badge badge-success"><i class="fas fa-smile"></i> Happy</span>',
        'bored' => '<span class="badge badge-warning"><i class="fas fa-meh"></i> Bored</span>',
        'neutral' => '<span class="badge badge-info"><i class="fas fa-meh-blank"></i> Neutral</span>',
        'sad' => '<span class="badge badge-info"><i class="fas fa-sad-tear"></i> Sad</span>',
        'angry' => '<span class="badge badge-danger"><i class="fas fa-angry"></i> Angry</span>'
    ];
    
    return $badges[strtolower($emotion)] ?? '<span class="badge">' . ucfirst($emotion) . '</span>';
}

function getInitials($name) {
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    return substr($initials, 0, 2);
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
        /* Reuse dashboard styles */
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

         /* Logo Styles - ADDED THIS */
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
        
        /* Report Controls */
        .report-controls {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .control-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .control-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .control-group label {
            font-weight: 600;
            color: #374151;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .control-group select,
        .control-group input {
            padding: 12px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .control-group select:focus,
        .control-group input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .btn {
            padding: 12px 24px;
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
        
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
        }
        
        .btn-export {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(16, 185, 129, 0.4);
        }
        
        /* Report Sections */
        .report-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            animation: slideUp 0.5s ease;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .section-header h2 {
            color: #1f2937;
            font-size: 22px;
            font-weight: 700;
        }
        
        .section-header h3 {
            color: #1f2937;
            font-size: 18px;
            font-weight: 600;
        }
        
        /* Charts */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .chart-card h3 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .chart-wrapper {
            height: 300px;
            position: relative;
        }
        
        /* Tables */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
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
        
        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .badge-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        
        .badge-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fca5a5 100%);
            color: #7f1d1d;
        }
        
        .badge-info {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        
        /* Emotion Indicators */
        .emotion-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .emotion-happy {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #166534;
        }
        
        .emotion-bored {
            background: linear-gradient(135deg, #fef9c3 0%, #fef08a 100%);
            color: #854d0e;
        }
        
        .emotion-neutral {
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            color: #374151;
        }
        
        .emotion-sad {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        
        .emotion-angry {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #7f1d1d;
        }
        
        /* Progress Bars */
        .progress-bar {
            height: 10px;
            background: #e5e7eb;
            border-radius: 5px;
            overflow: hidden;
            margin: 8px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 5px;
            transition: width 1s ease;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #e5e7eb;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            color: #6b7280;
            margin-bottom: 10px;
            font-size: 20px;
        }
        
        .empty-state p {
            color: #9ca3af;
            font-size: 16px;
            max-width: 500px;
            margin: 0 auto 20px;
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
        }
        
        .error-message i {
            font-size: 24px;
            color: #ef4444;
        }
        
        /* Quick Stats */
        .quick-stats {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin: 15px 0;
        }
        
        .stat-item {
            background: #f8fafc;
            padding: 12px 20px;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .stat-item-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .stat-item-value {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .charts-container {
                grid-template-columns: 1fr;
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
            
            .control-grid {
                grid-template-columns: 1fr;
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
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .charts-container {
                gap: 20px;
            }
            
            .chart-card {
                padding: 20px;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
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
            <a href="teacher_dashboard.php" class="menu-item">
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
            <a href="teacher_reports.php" class="menu-item active">
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
                <h2>Reports & Analytics</h2>
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
                        <a href="instructor_profile.php" class="user-menu-item">
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
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Error:</strong> <?php echo $error_message; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Report Controls -->
            <div class="report-controls">
                <form method="GET" action="">
                    <div class="control-grid">
                        <div class="control-group">
                            <label for="report_type"><i class="fas fa-chart-bar"></i> Report Type</label>
                            <select id="report_type" name="report_type" onchange="this.form.submit()">
                                <option value="session" <?php echo $report_type == 'session' ? 'selected' : ''; ?>>Session Reports</option>
                                <option value="student" <?php echo $report_type == 'student' ? 'selected' : ''; ?>>Student Engagement</option>
                                <option value="attendance" <?php echo $report_type == 'attendance' ? 'selected' : ''; ?>>Attendance Reports</option>
                            </select>
                        </div>

                        <div class="control-group">
                            <label for="class_id"><i class="fas fa-chalkboard-teacher"></i> Class</label>
                            <select id="class_id" name="class_id" onchange="this.form.submit()">
                                <option value="">All Classes</option>
                                <?php foreach ($class_list as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?> (<?php echo htmlspecialchars($class['class_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                        </select>
                        </div>

                        <?php if ($report_type == 'session'): ?>
                        <div class="control-group">
                            <label for="session_id"><i class="fas fa-video"></i> Session</label>
                            <select id="session_id" name="session_id" onchange="this.form.submit()">
                                <option value="">Select Session</option>
                                <?php foreach ($session_list as $session): ?>
                                    <option value="<?php echo $session['id']; ?>" <?php echo $session_id == $session['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($session['session_name'] ?: 'Session #' . $session['id']); ?> - 
                                        <?php echo formatDate($session['start_time'], 'M d, Y H:i'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php elseif ($report_type == 'student'): ?>
                        <div class="control-group">
                            <label for="student_id"><i class="fas fa-user-graduate"></i> Student</label>
                            <select id="student_id" name="student_id" onchange="this.form.submit()">
                                <option value="">Select Student</option>
                                <?php foreach ($student_list as $student): ?>
                                    <option value="<?php echo $student['id']; ?>" <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['student_number']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="control-group">
                            <label for="date_from"><i class="far fa-calendar"></i> Date From</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo $date_from; ?>" onchange="this.form.submit()">
                        </div>

                        <div class="control-group">
                            <label for="date_to"><i class="far fa-calendar-alt"></i> Date To</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo $date_to; ?>" onchange="this.form.submit()">
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="button" class="btn btn-secondary" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                        <button type="button" class="btn btn-export" onclick="exportReport()">
                            <i class="fas fa-file-export"></i> Export Data
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sync-alt"></i> Generate Report
                        </button>
                    </div>
                </form>
            </div>

            <!-- Report Content -->
            <?php if ($report_type == 'session' && $session_id && $report_data): ?>
            <!-- Session Detail Report -->
            <div class="report-section">
                <div class="section-header">
                    <h2>Session Report: <?php echo htmlspecialchars($report_data['session_name'] ?: 'Session #' . $session_id); ?></h2>
                    <span class="badge <?php echo $report_data['status'] == 'active' ? 'badge-success' : 'badge-info'; ?>">
                        <?php echo ucfirst($report_data['status']); ?>
                    </span>
                </div>

                <div class="quick-stats">
                    <div class="stat-item">
                        <span class="stat-item-label">Class</span>
                        <span class="stat-item-value"><?php echo htmlspecialchars($report_data['class_name']); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-item-label">Attendance</span>
                        <span class="stat-item-value"><?php echo $report_data['total_students'] ?? 0; ?> students</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-item-label">Emotion Samples</span>
                        <span class="stat-item-value"><?php echo $report_data['emotion_samples'] ?? 0; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-item-label">Avg Engagement</span>
                        <span class="stat-item-value"><?php echo round($report_data['avg_engagement'] ?? 0, 1); ?>%</span>
                    </div>
                </div>

                <div class="charts-container">
                    <div class="chart-card">
                        <h3>Emotion Distribution</h3>
                        <div class="chart-wrapper">
                            <canvas id="emotionChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Engagement Score</h3>
                        <div class="chart-wrapper">
                            <canvas id="engagementChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="section-header">
                    <h3>Student Performance</h3>
                </div>
                
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Engagement Score</th>
                                <th>Emotion Analysis</th>
                                <th>Attendance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($report_data['student_details']) && !empty($report_data['student_details'])): ?>
                                <?php foreach ($report_data['student_details'] as $student): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                                                <?php echo getInitials($student['full_name']); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 600;"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                                <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($student['student_number']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($student['engagement_score']): ?>
                                            <div style="font-weight: 600; font-size: 20px; color: <?php echo getEngagementColor($student['engagement_score']); ?>;">
                                                <?php echo $student['engagement_score']; ?>%
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $student['engagement_score']; ?>%;"></div>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">No data</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($student['happy_percent'] || $student['bored_percent']): ?>
                                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                <?php if ($student['happy_percent']): ?>
                                                    <span class="emotion-indicator emotion-happy">
                                                        <i class="fas fa-smile"></i> <?php echo round($student['happy_percent'], 1); ?>%
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($student['bored_percent']): ?>
                                                    <span class="emotion-indicator emotion-bored">
                                                        <i class="fas fa-meh"></i> <?php echo round($student['bored_percent'], 1); ?>%
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($student['neutral_percent']): ?>
                                                    <span class="emotion-indicator emotion-neutral">
                                                        <i class="fas fa-meh-blank"></i> <?php echo round($student['neutral_percent'], 1); ?>%
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">No emotion data</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($student['join_time']): ?>
                                            <?php echo formatDate($student['join_time'], 'H:i'); ?> - 
                                            <?php echo $student['leave_time'] ? formatDate($student['leave_time'], 'H:i') : 'Present'; ?>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">Absent</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($student['engagement_score']): ?>
                                            <?php if ($student['engagement_score'] >= 80): ?>
                                                <span class="badge badge-success">Highly Engaged</span>
                                            <?php elseif ($student['engagement_score'] >= 60): ?>
                                                <span class="badge badge-info">Moderately Engaged</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Needs Attention</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">No data</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <h3>No Student Data</h3>
                                        <p>No students attended this session or emotion data was not collected.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($report_type == 'student' && $student_id && $report_data): ?>
            <!-- Student Engagement Report -->
            <div class="report-section">
                <div class="section-header">
                    <h2>Student Engagement Report</h2>
                    <div>
                        <span class="badge badge-info"><?php echo htmlspecialchars($report_data['student_number']); ?></span>
                    </div>
                </div>

                <div style="margin-bottom: 30px; padding: 25px; background: #f8fafc; border-radius: 12px;">
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 32px;">
                            <?php echo getInitials($report_data['full_name']); ?>
                        </div>
                        <div>
                            <h3 style="font-size: 24px; color: #1f2937; margin-bottom: 5px;"><?php echo htmlspecialchars($report_data['full_name']); ?></h3>
                            <p style="color: #6b7280; margin-bottom: 15px;">Enrolled in: <?php echo htmlspecialchars($report_data['enrolled_classes']); ?></p>
                            <div style="display: flex; gap: 20px;">
                                <div>
                                    <div style="font-size: 12px; color: #6b7280;">Attendance Rate</div>
                                    <div style="font-size: 28px; font-weight: 700; color: <?php echo getAttendanceColor($report_data['total_sessions'] > 0 ? ($report_data['attended_sessions'] / $report_data['total_sessions'] * 100) : 0); ?>;">
                                        <?php echo $report_data['total_sessions'] > 0 ? round(($report_data['attended_sessions'] / $report_data['total_sessions']) * 100, 1) : 0; ?>%
                                    </div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: #6b7280;">Avg Engagement</div>
                                    <div style="font-size: 28px; font-weight: 700; color: <?php echo getEngagementColor($report_data['avg_engagement'] ?? 0); ?>;">
                                        <?php echo round($report_data['avg_engagement'] ?? 0, 1); ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($report_data['session_details']) && !empty($report_data['session_details'])): ?>
                <div class="section-header">
                    <h3>Session-by-Session Performance</h3>
                </div>
                
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Session</th>
                                <th>Date & Time</th>
                                <th>Class</th>
                                <th>Engagement</th>
                                <th>Emotion</th>
                                <th>Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data['session_details'] as $session): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($session['session_name'] ?: 'Session #' . $session['session_id']); ?></td>
                                <td><?php echo formatDate($session['start_time'], 'M d, Y H:i'); ?></td>
                                <td><?php echo htmlspecialchars($session['class_name']); ?></td>
                                <td>
                                    <?php if ($session['engagement_score']): ?>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="font-weight: 600; color: <?php echo getEngagementColor($session['engagement_score']); ?>;">
                                                <?php echo $session['engagement_score']; ?>%
                                            </div>
                                            <div class="progress-bar" style="flex: 1;">
                                                <div class="progress-fill" style="width: <?php echo $session['engagement_score']; ?>%;"></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">No data</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($session['average_emotion']): ?>
                                        <?php echo getEmotionBadge($session['average_emotion']); ?>
                                        <?php if ($session['happy_percent']): ?>
                                            <div style="font-size: 11px; color: #6b7280; margin-top: 3px;">
                                                Happy: <?php echo round($session['happy_percent'], 1); ?>%
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">No data</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($session['duration_minutes']): ?>
                                        <span class="badge badge-info"><?php echo $session['duration_minutes']; ?> mins</span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">Absent</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($report_type == 'attendance'): ?>
            <!-- Attendance Report -->
            <div class="report-section">
                <div class="section-header">
                    <h2>Attendance Reports</h2>
                    <span class="badge badge-info">
                        <i class="far fa-calendar"></i>
                        <?php echo formatDate($date_from, 'M d, Y'); ?> - <?php echo formatDate($date_to, 'M d, Y'); ?>
                    </span>
                </div>

                <?php if (!empty($report_data)): ?>
                    <?php foreach ($report_data as $class): ?>
                    <div class="report-section" style="margin-bottom: 30px;">
                        <div class="section-header">
                            <h3 style="margin: 0;">
                                <?php echo htmlspecialchars($class['class_name']); ?> 
                                <span style="font-size: 14px; color: #6b7280;">(<?php echo htmlspecialchars($class['class_code']); ?>)</span>
                            </h3>
                            <span class="badge <?php echo ($class['avg_attendance_rate'] ?? 0) >= 90 ? 'badge-success' : (($class['avg_attendance_rate'] ?? 0) >= 75 ? 'badge-info' : (($class['avg_attendance_rate'] ?? 0) >= 60 ? 'badge-warning' : 'badge-danger')); ?>">
                                Avg: <?php echo round($class['avg_attendance_rate'] ?? 0, 1); ?>%
                            </span>
                        </div>
                        
                        <?php if ($class_id == $class['class_id'] && isset($class['attendance_details'])): ?>
                        <div class="section-header">
                            <h4>Student Attendance Details</h4>
                        </div>
                        
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Sessions Attended</th>
                                        <th>Attendance Rate</th>
                                        <th>Avg Duration</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($class['attendance_details'] as $attendance): ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px;">
                                                    <?php echo getInitials($attendance['full_name']); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($attendance['full_name']); ?></div>
                                                    <div style="font-size: 11px; color: #6b7280;"><?php echo htmlspecialchars($attendance['student_number']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;">
                                                <?php echo $attendance['attended_sessions']; ?> / <?php echo $attendance['total_sessions']; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="font-weight: 600; color: <?php echo getAttendanceColor($attendance['attendance_rate']); ?>;">
                                                    <?php echo round($attendance['attendance_rate'], 1); ?>%
                                                </div>
                                                <div class="progress-bar" style="flex: 1;">
                                                    <div class="progress-fill" style="width: <?php echo $attendance['attendance_rate']; ?>%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($attendance['avg_duration']): ?>
                                                <span class="badge badge-info"><?php echo round($attendance['avg_duration'], 0); ?> mins</span>
                                            <?php else: ?>
                                                <span style="color: #9ca3af;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($attendance['attendance_rate'] >= 90): ?>
                                                <span class="badge badge-success">Excellent</span>
                                            <?php elseif ($attendance['attendance_rate'] >= 75): ?>
                                                <span class="badge badge-info">Good</span>
                                            <?php elseif ($attendance['attendance_rate'] >= 60): ?>
                                                <span class="badge badge-warning">Fair</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Poor</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Attendance Data</h3>
                        <p>No attendance records found for the selected period. Try changing the date range.</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- No Report Selected -->
            <div class="report-section">
                <div class="empty-state">
                    <?php if ($report_type == 'session'): ?>
                        <i class="fas fa-video" style="color: #8b5cf6;"></i>
                        <h3>Select a Session</h3>
                        <p>Choose a session from the dropdown above to view detailed emotion and engagement analysis.</p>
                        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                            <div style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 10px; min-width: 200px;">
                                <i class="fas fa-chart-pie" style="font-size: 32px; color: #8b5cf6; margin-bottom: 10px;"></i>
                                <h4 style="color: #1f2937; margin-bottom: 5px;">Emotion Analysis</h4>
                                <p style="color: #6b7280; font-size: 14px;">View student emotion distribution</p>
                            </div>
                            <div style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 10px; min-width: 200px;">
                                <i class="fas fa-user-graduate" style="font-size: 32px; color: #3b82f6; margin-bottom: 10px;"></i>
                                <h4 style="color: #1f2937; margin-bottom: 5px;">Student Engagement</h4>
                                <p style="color: #6b7280; font-size: 14px;">Individual engagement scores</p>
                            </div>
                        </div>
                    <?php elseif ($report_type == 'student'): ?>
                        <i class="fas fa-user-graduate" style="color: #10b981;"></i>
                        <h3>Select a Student</h3>
                        <p>Choose a student to view their engagement and attendance patterns across all sessions.</p>
                        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                            <div style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 10px; min-width: 200px;">
                                <i class="fas fa-chart-line" style="font-size: 32px; color: #10b981; margin-bottom: 10px;"></i>
                                <h4 style="color: #1f2937; margin-bottom: 5px;">Engagement Trends</h4>
                                <p style="color: #6b7280; font-size: 14px;">Track engagement over time</p>
                            </div>
                            <div style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 10px; min-width: 200px;">
                                <i class="fas fa-clipboard-check" style="font-size: 32px; color: #f59e0b; margin-bottom: 10px;"></i>
                                <h4 style="color: #1f2937; margin-bottom: 5px;">Attendance History</h4>
                                <p style="color: #6b7280; font-size: 14px;">View session attendance records</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <i class="fas fa-clipboard-check" style="color: #f59e0b;"></i>
                        <h3>Attendance Overview</h3>
                        <p>View attendance statistics for all your classes. Select a specific class for detailed student attendance.</p>
                        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                            <div style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 10px; min-width: 200px;">
                                <i class="fas fa-percentage" style="font-size: 32px; color: #f59e0b; margin-bottom: 10px;"></i>
                                <h4 style="color: #1f2937; margin-bottom: 5px;">Class Statistics</h4>
                                <p style="color: #6b7280; font-size: 14px;">Overall attendance rates</p>
                            </div>
                            <div style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 10px; min-width: 200px;">
                                <i class="fas fa-user-friends" style="font-size: 32px; color: #ef4444; margin-bottom: 10px;"></i>
                                <h4 style="color: #1f2937; margin-bottom: 5px;">Student Details</h4>
                                <p style="color: #6b7280; font-size: 14px;">Individual attendance patterns</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
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
        
        // Notification button
        // Helper functions for frontend
        function getEngagementColor(score) {
            if (score >= 80) return '#10b981';
            if (score >= 60) return '#f59e0b';
            return '#ef4444';
        }

        function getAttendanceColor(rate) {
            if (rate >= 90) return '#10b981';
            if (rate >= 75) return '#3b82f6';
            if (rate >= 60) return '#f59e0b';
            return '#ef4444';
        }

        // Export function
        function exportReport() {
            const reportType = '<?php echo $report_type; ?>';
            const fileName = `report_${reportType}_<?php echo date('Ymd_His'); ?>.csv`;
            
            // Create CSV content based on report type
            let csvContent = '';
            
            switch(reportType) {
                case 'session':
                    if (<?php echo $session_id ? 'true' : 'false'; ?>) {
                        // Export session data
                        const sessionName = '<?php echo addslashes($report_data["session_name"] ?? "Session"); ?>';
                        csvContent = `Session Report: ${sessionName}\n`;
                        csvContent += `Date Range: <?php echo $date_from; ?> to <?php echo $date_to; ?>\n\n`;
                        csvContent += "Student,Student Number,Engagement Score,Happy %,Bored %,Neutral %,Average Emotion,Join Time,Leave Time\n";
                        
                        <?php if (isset($report_data['student_details'])): ?>
                            <?php foreach ($report_data['student_details'] as $student): ?>
                                csvContent += `"<?php echo addslashes($student['full_name']); ?>","<?php echo $student['student_number']; ?>",<?php echo $student['engagement_score'] ?? 'N/A'; ?>,<?php echo $student['happy_percent'] ?? 'N/A'; ?>,<?php echo $student['bored_percent'] ?? 'N/A'; ?>,<?php echo $student['neutral_percent'] ?? 'N/A'; ?>,"<?php echo $student['average_emotion'] ?? 'N/A'; ?>","<?php echo $student['join_time'] ? formatDate($student['join_time'], 'Y-m-d H:i:s') : 'N/A'; ?>","<?php echo $student['leave_time'] ? formatDate($student['leave_time'], 'Y-m-d H:i:s') : 'N/A'; ?>"\n`;
                            <?php endforeach; ?>
                        <?php endif; ?>
                    }
                    break;
                    
                case 'student':
                    if (<?php echo $student_id ? 'true' : 'false'; ?>) {
                        // Export student data
                        const studentName = '<?php echo addslashes($report_data["full_name"] ?? "Student"); ?>';
                        csvContent = `Student Report: ${studentName}\n`;
                        csvContent += `Date Range: <?php echo $date_from; ?> to <?php echo $date_to; ?>\n\n`;
                        csvContent += "Session,Date,Class,Engagement Score,Happy %,Bored %,Neutral %,Average Emotion,Duration (mins)\n";
                        
                        <?php if (isset($report_data['session_details'])): ?>
                            <?php foreach ($report_data['session_details'] as $session): ?>
                                csvContent += `"<?php echo addslashes($session['session_name'] ?: 'Session #' . $session['session_id']); ?>","<?php echo formatDate($session['start_time'], 'Y-m-d H:i:s'); ?>","<?php echo addslashes($session['class_name']); ?>",<?php echo $session['engagement_score'] ?? 'N/A'; ?>,<?php echo $session['happy_percent'] ?? 'N/A'; ?>,<?php echo $session['bored_percent'] ?? 'N/A'; ?>,<?php echo $session['neutral_percent'] ?? 'N/A'; ?>,"<?php echo $session['average_emotion'] ?? 'N/A'; ?>",<?php echo $session['duration_minutes'] ?? 'N/A'; ?>\n`;
                            <?php endforeach; ?>
                        <?php endif; ?>
                    }
                    break;
                    
                case 'attendance':
                    // Export attendance data
                    csvContent = `Attendance Report\n`;
                    csvContent += `Date Range: <?php echo $date_from; ?> to <?php echo $date_to; ?>\n\n`;
                    csvContent += "Class,Class Code,Total Sessions,Total Students,Avg Attendance %,Min Attendance %,Max Attendance %\n";
                    
                    <?php foreach ($report_data as $class): ?>
                        csvContent += `"<?php echo addslashes($class['class_name']); ?>","<?php echo $class['class_code']; ?>",<?php echo $class['total_sessions']; ?>,<?php echo $class['total_students']; ?>,<?php echo round($class['avg_attendance_rate'] ?? 0, 1); ?>,<?php echo round($class['min_attendance_rate'] ?? 0, 1); ?>,<?php echo round($class['max_attendance_rate'] ?? 0, 1); ?>\n`;
                    <?php endforeach; ?>
                    break;
            }
            
            // Create download link
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', fileName);
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            alert('Report exported successfully!');
        }

        // Initialize charts for session report
        <?php if ($report_type == 'session' && $session_id && $report_data): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Emotion Distribution Chart
            const emotionCtx = document.getElementById('emotionChart');
            if (emotionCtx) {
                const emotionData = {
                    happy: <?php echo $report_data['happy_count'] ?? 0; ?>,
                    bored: <?php echo $report_data['bored_count'] ?? 0; ?>,
                    neutral: <?php echo $report_data['neutral_count'] ?? 0; ?>,
                    sad: <?php echo $report_data['sad_count'] ?? 0; ?>,
                    angry: <?php echo $report_data['angry_count'] ?? 0; ?>
                };
                
                new Chart(emotionCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Happy', 'Bored', 'Neutral', 'Sad', 'Angry'],
                        datasets: [{
                            data: Object.values(emotionData),
                            backgroundColor: [
                                '#10b981',
                                '#f59e0b',
                                '#6b7280',
                                '#3b82f6',
                                '#ef4444'
                            ],
                            borderWidth: 2,
                            borderColor: '#ffffff'
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
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return `${label}: ${value} samples (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Engagement Chart
            const engagementCtx = document.getElementById('engagementChart');
            if (engagementCtx && <?php echo isset($report_data['student_details']) ? 'true' : 'false'; ?>) {
                <?php if (isset($report_data['student_details'])): ?>
                const studentNames = <?php echo json_encode(array_column($report_data['student_details'], 'full_name')); ?>;
                const engagementScores = <?php echo json_encode(array_column($report_data['student_details'], 'engagement_score')); ?>;
                
                new Chart(engagementCtx, {
                    type: 'bar',
                    data: {
                        labels: studentNames,
                        datasets: [{
                            label: 'Engagement Score',
                            data: engagementScores.map(score => score || 0),
                            backgroundColor: engagementScores.map(score => {
                                if (!score) return '#e5e7eb';
                                if (score >= 80) return '#10b981';
                                if (score >= 60) return '#f59e0b';
                                return '#ef4444';
                            }),
                            borderColor: engagementScores.map(score => {
                                if (!score) return '#d1d5db';
                                if (score >= 80) return '#059669';
                                if (score >= 60) return '#d97706';
                                return '#dc2626';
                            }),
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                ticks: {
                                    font: {
                                        size: 11
                                    },
                                    maxRotation: 45
                                },
                                grid: {
                                    display: false
                                }
                            },
                            y: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                },
                                grid: {
                                    drawBorder: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `Engagement: ${context.raw}%`;
                                    }
                                }
                            }
                        }
                    }
                });
                <?php endif; ?>
            }
        });
        <?php endif; ?>

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + M to toggle sidebar
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sidebar.classList.toggle('active');
            }
            
            // Ctrl + P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Ctrl + E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportReport();
            }
        });
    </script>
</body>
</html>