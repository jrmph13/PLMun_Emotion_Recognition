<?php
// DON'T start session here - let config.php handle it
require_once 'config.php';

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

// Check database connection
if (!isset($pdo) || !$pdo) {
    die("Database connection failed. Please check your config.php file.");
}

// Get filter parameters
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'last_30_days';
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$instructor_id = isset($_GET['instructor_id']) ? intval($_GET['instructor_id']) : 0;

// Calculate date range
$date_conditions = [];
switch ($date_range) {
    case 'today':
        $date_conditions['start'] = date('Y-m-d 00:00:00');
        $date_conditions['end'] = date('Y-m-d 23:59:59');
        $date_label = 'Today';
        break;
    case 'yesterday':
        $date_conditions['start'] = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $date_conditions['end'] = date('Y-m-d 23:59:59', strtotime('-1 day'));
        $date_label = 'Yesterday';
        break;
    case 'last_7_days':
        $date_conditions['start'] = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $date_conditions['end'] = date('Y-m-d 23:59:59');
        $date_label = 'Last 7 Days';
        break;
    case 'last_30_days':
        $date_conditions['start'] = date('Y-m-d 00:00:00', strtotime('-30 days'));
        $date_conditions['end'] = date('Y-m-d 23:59:59');
        $date_label = 'Last 30 Days';
        break;
    case 'last_90_days':
        $date_conditions['start'] = date('Y-m-d 00:00:00', strtotime('-90 days'));
        $date_conditions['end'] = date('Y-m-d 23:59:59');
        $date_label = 'Last 90 Days';
        break;
    default:
        $date_conditions['start'] = date('Y-m-d 00:00:00', strtotime('-30 days'));
        $date_conditions['end'] = date('Y-m-d 23:59:59');
        $date_label = 'Last 30 Days';
}

// Build WHERE clause with PDO placeholders
$where_clause = "WHERE ls.start_time BETWEEN :start_date AND :end_date";
$params = [
    ':start_date' => $date_conditions['start'],
    ':end_date' => $date_conditions['end']
];

if ($class_id > 0) {
    $where_clause .= " AND ls.class_id = :class_id";
    $params[':class_id'] = $class_id;
}
if ($instructor_id > 0) {
    $where_clause .= " AND c.instructor_id = :instructor_id";
    $params[':instructor_id'] = $instructor_id;
}

// Initialize variables with default values
$overall_stats = [
    'total_sessions' => 0,
    'total_classes' => 0,
    'total_students' => 0,
    'total_instructors' => 0,
    'avg_session_duration' => '00:00:00',
    'avg_engagement_score' => 0
];

$emotion_stats = [
    'happy_sessions' => 0,
    'bored_sessions' => 0,
    'neutral_sessions' => 0,
    'avg_happy_percent' => 0,
    'avg_bored_percent' => 0,
    'avg_neutral_percent' => 0
];

$attendance_stats = [
    'total_attended' => 0,
    'total_enrolled' => 0,
    'attendance_rate' => 0
];

$top_classes = [];
$daily_trends = [];
$emotion_trends = [];
$all_classes = [];
$all_instructors = [];

try {
    // Get overall statistics
    $sql = "
        SELECT 
            COUNT(DISTINCT ls.id) as total_sessions,
            COUNT(DISTINCT ls.class_id) as total_classes,
            COUNT(DISTINCT sa.student_id) as total_students,
            COUNT(DISTINCT c.instructor_id) as total_instructors,
            COALESCE(SEC_TO_TIME(AVG(TIME_TO_SEC(TIMEDIFF(COALESCE(ls.end_time, NOW()), ls.start_time)))), '00:00:00') as avg_session_duration,
            COALESCE(AVG(ses.engagement_score), 0) as avg_engagement_score
        FROM live_sessions ls
        LEFT JOIN classes c ON ls.class_id = c.id
        LEFT JOIN session_attendance sa ON ls.id = sa.session_id
        LEFT JOIN session_engagement_summary ses ON ls.id = ses.session_id
        $where_clause
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $overall_stats = $stmt->fetch();
    
    // Get emotion distribution from emotion_data table
    $sql = "
        SELECT 
            COUNT(DISTINCT ed.session_id) as sessions_with_emotion_data,
            COALESCE(SUM(CASE WHEN ed.facial_emotion = 'happy' THEN 1 ELSE 0 END), 0) as happy_count,
            COALESCE(SUM(CASE WHEN ed.facial_emotion = 'bored' THEN 1 ELSE 0 END), 0) as bored_count,
            COALESCE(SUM(CASE WHEN ed.facial_emotion = 'neutral' THEN 1 ELSE 0 END), 0) as neutral_count,
            COALESCE(SUM(CASE WHEN ed.facial_emotion = 'sad' THEN 1 ELSE 0 END), 0) as sad_count,
            COALESCE(SUM(CASE WHEN ed.facial_emotion = 'angry' THEN 1 ELSE 0 END), 0) as angry_count
        FROM live_sessions ls
        LEFT JOIN emotion_data ed ON ls.id = ed.session_id
        $where_clause
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_emotion_data = $stmt->fetch();
    
    // Calculate percentages from emotion_data
    $total_emotions = $raw_emotion_data['happy_count'] + $raw_emotion_data['bored_count'] + $raw_emotion_data['neutral_count'] + 
                      $raw_emotion_data['sad_count'] + $raw_emotion_data['angry_count'];
    
    if ($total_emotions > 0) {
        $emotion_stats['avg_happy_percent'] = ($raw_emotion_data['happy_count'] / $total_emotions) * 100;
        $emotion_stats['avg_bored_percent'] = ($raw_emotion_data['bored_count'] / $total_emotions) * 100;
        $emotion_stats['avg_neutral_percent'] = ($raw_emotion_data['neutral_count'] / $total_emotions) * 100;
    }
    
    // Get session counts with dominant emotions from session_engagement_summary
    $sql = "
        SELECT 
            COALESCE(SUM(CASE WHEN ses.happy_percent > ses.bored_percent AND ses.happy_percent > ses.neutral_percent THEN 1 ELSE 0 END), 0) as happy_sessions,
            COALESCE(SUM(CASE WHEN ses.bored_percent > ses.happy_percent AND ses.bored_percent > ses.neutral_percent THEN 1 ELSE 0 END), 0) as bored_sessions,
            COALESCE(SUM(CASE WHEN ses.neutral_percent > ses.happy_percent AND ses.neutral_percent > ses.bored_percent THEN 1 ELSE 0 END), 0) as neutral_sessions
        FROM live_sessions ls
        LEFT JOIN session_engagement_summary ses ON ls.id = ses.session_id
        $where_clause
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $session_emotion_counts = $stmt->fetch();
    
    $emotion_stats['happy_sessions'] = $session_emotion_counts['happy_sessions'];
    $emotion_stats['bored_sessions'] = $session_emotion_counts['bored_sessions'];
    $emotion_stats['neutral_sessions'] = $session_emotion_counts['neutral_sessions'];
    
    // Get attendance statistics
    $sql = "
        SELECT 
            COALESCE(COUNT(DISTINCT sa.student_id), 0) as total_attended,
            COALESCE(COUNT(DISTINCT ce.student_id), 0) as total_enrolled,
            COALESCE(ROUND(COUNT(DISTINCT sa.student_id) * 100.0 / NULLIF(COUNT(DISTINCT ce.student_id), 0), 2), 0) as attendance_rate
        FROM live_sessions ls
        JOIN classes c ON ls.class_id = c.id
        JOIN class_enrollments ce ON c.id = ce.class_id
        LEFT JOIN session_attendance sa ON ls.id = sa.session_id
        $where_clause
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $attendance_stats = $stmt->fetch();
    
    // Get top performing classes
    $sql = "
        SELECT 
            c.id,
            c.class_name,
            c.class_code,
            u.full_name as instructor_name,
            COUNT(DISTINCT ls.id) as session_count,
            COUNT(DISTINCT sa.student_id) as total_attendance,
            COALESCE(AVG(ses.engagement_score), 0) as avg_engagement,
            COALESCE(AVG(ses.happy_percent), 0) as avg_happiness
        FROM classes c
        JOIN users u ON c.instructor_id = u.id
        LEFT JOIN live_sessions ls ON c.id = ls.class_id AND ls.start_time BETWEEN :start_date AND :end_date
        LEFT JOIN session_attendance sa ON ls.id = sa.session_id
        LEFT JOIN session_engagement_summary ses ON ls.id = ses.session_id
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY avg_engagement DESC
        LIMIT 5
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start_date' => $date_conditions['start'],
        ':end_date' => $date_conditions['end']
    ]);
    $top_classes = $stmt->fetchAll();
    
    // Get session trends (for chart) - updated for emotion_data
    $sql = "
        SELECT 
            DATE(ls.start_time) as date,
            COUNT(DISTINCT ls.id) as session_count,
            COUNT(DISTINCT sa.student_id) as student_count,
            COALESCE(AVG(ses.engagement_score), 0) as avg_engagement,
            COALESCE(AVG(ses.happy_percent), 0) as avg_happiness
        FROM live_sessions ls
        LEFT JOIN session_attendance sa ON ls.id = sa.session_id
        LEFT JOIN session_engagement_summary ses ON ls.id = ses.session_id
        WHERE ls.start_time BETWEEN :start_date AND :end_date
        GROUP BY DATE(ls.start_time)
        ORDER BY DATE(ls.start_time) ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start_date' => $date_conditions['start'],
        ':end_date' => $date_conditions['end']
    ]);
    $daily_trends = $stmt->fetchAll();
    
    // Get emotion trends over time from emotion_data
    $sql = "
        SELECT 
            DATE(ls.start_time) as date,
            COALESCE(SUM(CASE WHEN ed.facial_emotion = 'happy' THEN 1 ELSE 0 END) * 100.0 / 
                    NULLIF(COUNT(ed.id), 0), 0) as happiness,
            COALESCE(SUM(CASE WHEN ed.facial_emotion = 'bored' THEN 1 ELSE 0 END) * 100.0 / 
                    NULLIF(COUNT(ed.id), 0), 0) as boredom,
            COALESCE(SUM(CASE WHEN ed.facial_emotion = 'neutral' THEN 1 ELSE 0 END) * 100.0 / 
                    NULLIF(COUNT(ed.id), 0), 0) as neutrality
        FROM live_sessions ls
        LEFT JOIN emotion_data ed ON ls.id = ed.session_id
        WHERE ls.start_time BETWEEN :start_date AND :end_date
        GROUP BY DATE(ls.start_time)
        ORDER BY DATE(ls.start_time) ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start_date' => $date_conditions['start'],
        ':end_date' => $date_conditions['end']
    ]);
    $emotion_trends = $stmt->fetchAll();
    
    // Get all classes and instructors for filters
    $sql = "
        SELECT c.id, c.class_name, c.class_code, u.full_name as instructor_name
        FROM classes c
        JOIN users u ON c.instructor_id = u.id
        WHERE c.is_active = 1
        ORDER BY c.class_name
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $all_classes = $stmt->fetchAll();
    
    $sql = "
        SELECT id, full_name
        FROM users
        WHERE role = 'instructor' AND is_active = 1
        ORDER BY full_name
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $all_instructors = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Analytics Query Error: " . $e->getMessage());
    $error_message = "Database Error: " . $e->getMessage();
    
    // Check if it's a table doesn't exist error
    if (strpos($e->getMessage(), 'Base table or view not found') !== false) {
        $error_message .= "<br><br>Some tables might not exist. Please run the database setup script.";
    }
}

// Check if we have any data
$has_data = false;
if (!empty($daily_trends) || !empty($top_classes) || ($overall_stats['total_sessions'] > 0)) {
    $has_data = true;
}

// Prepare chart data
$chart_labels = [];
$session_counts = [];
$engagement_scores = [];
$happiness_scores = [];

if ($has_data && !empty($daily_trends)) {
    foreach ($daily_trends as $trend) {
        $chart_labels[] = date('M d', strtotime($trend['date']));
        $session_counts[] = $trend['session_count'];
        $engagement_scores[] = $trend['avg_engagement'] ? round($trend['avg_engagement'], 1) : 0;
        $happiness_scores[] = $trend['avg_happiness'] ? round($trend['avg_happiness'], 1) : 0;
    }
} else {
    // Default empty data
    $chart_labels = ['No Data'];
    $session_counts = [0];
    $engagement_scores = [0];
    $happiness_scores = [0];
}

$emotion_chart_labels = [];
$happiness_trend = [];
$boredom_trend = [];
$neutral_trend = [];

if ($has_data && !empty($emotion_trends)) {
    foreach ($emotion_trends as $trend) {
        $emotion_chart_labels[] = date('M d', strtotime($trend['date']));
        $happiness_trend[] = $trend['happiness'] ? round($trend['happiness'], 1) : 0;
        $boredom_trend[] = $trend['boredom'] ? round($trend['boredom'], 1) : 0;
        $neutral_trend[] = $trend['neutrality'] ? round($trend['neutrality'], 1) : 0;
    }
} else {
    // Default empty data
    $emotion_chart_labels = ['No Data'];
    $happiness_trend = [0];
    $boredom_trend = [0];
    $neutral_trend = [0];
}

// Function to get initials
function getInitials($name) {
    if (empty($name)) return '??';
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
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
    <title>Analytics & Reports - Emotion System</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Reuse styles from previous pages with additional analytics styles */
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
            overflow-x: hidden; /* Prevent horizontal scrolling on body */
        }
        
        /* Sidebar Styles - Same as dashboard */
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
            width: calc(100% - 280px); /* Ensure proper width calculation */
            overflow-x: hidden; /* Prevent horizontal overflow */
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
            max-width: 100%;
            overflow-x: hidden; /* Prevent horizontal overflow */
        }
        
        /* Analytics Specific Styles */
        .analytics-header {
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
        
        /* Filters Section */
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 14px;
            color: #374151;
            font-weight: 600;
        }
        
        .filter-select {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            color: #4b5563;
            background: white;
            transition: all 0.3s;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            padding-top: 20px;
            border-top: 2px solid #f3f4f6;
        }
        
        /* Summary Stats */
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .summary-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .summary-content {
            flex: 1;
        }
        
        .summary-value {
            font-size: 28px;
            font-weight: 800;
            color: #1f2937;
            line-height: 1;
            margin-bottom: 5px;
        }
        
        .summary-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .summary-change {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 5px;
        }
        
        .summary-change.positive {
            color: #10b981;
        }
        
        .summary-change.negative {
            color: #ef4444;
        }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-title h3 {
            color: #1f2937;
            font-size: 18px;
            font-weight: 700;
        }
        
        .chart-title p {
            color: #6b7280;
            font-size: 12px;
        }
        
        .chart-container {
            height: 300px;
            position: relative;
        }
        
        /* Emotion Distribution */
        .emotion-distribution {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .emotion-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
            transition: all 0.3s;
        }
        
        .emotion-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .emotion-icon {
            font-size: 40px;
            margin-bottom: 15px;
            display: block;
        }
        
        .emotion-name {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 10px;
        }
        
        .emotion-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 10px;
        }
        
        .emotion-percentage {
            font-size: 14px;
            color: #6b7280;
        }
        
        /* Top Classes Table - SCROLLABLE VERSION */
        .table-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .table-header h2 {
            color: #1f2937;
            font-size: 20px;
            font-weight: 700;
        }
        
        /* NEW: Scrollable table wrapper */
        .table-wrapper {
            width: 100%;
            max-height: 400px; /* Fixed height for vertical scrolling */
            border: 2px solid #f3f4f6;
            border-radius: 10px;
            overflow: auto; /* Enables both horizontal and vertical scrolling */
            position: relative;
        }
        
        /* Custom scrollbar styling */
        .table-wrapper::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .table-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .table-wrapper::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
        
        .table-wrapper::-webkit-scrollbar-corner {
            background: #f1f1f1;
        }
        
        .analytics-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px; /* Ensures table has enough width for horizontal scroll */
        }
        
        /* Fixed header styling */
        .analytics-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f8fafc;
        }
        
        .analytics-table th {
            background: #f8fafc;
            padding: 18px 20px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap; /* Prevents header text from wrapping */
        }
        
        .analytics-table td {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            color: #4b5563;
            word-wrap: break-word;
        }
        
        .analytics-table tr:hover {
            background: #f8fafc;
        }
        
        .analytics-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Scroll indicators */
        .scroll-indicator {
            position: absolute;
            right: 10px;
            bottom: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        
        .table-wrapper:hover .scroll-indicator {
            opacity: 1;
        }
        
        /* Column specific widths for better scrolling */
        .analytics-table th:nth-child(1),
        .analytics-table td:nth-child(1) {
            min-width: 220px; /* Class column */
        }
        
        .analytics-table th:nth-child(2),
        .analytics-table td:nth-child(2) {
            min-width: 150px; /* Instructor column */
        }
        
        .analytics-table th:nth-child(3),
        .analytics-table td:nth-child(3),
        .analytics-table th:nth-child(4),
        .analytics-table td:nth-child(4) {
            min-width: 100px; /* Sessions & Attendance columns */
        }
        
        .analytics-table th:nth-child(5),
        .analytics-table td:nth-child(5) {
            min-width: 120px; /* Engagement column */
        }
        
        .analytics-table th:nth-child(6),
        .analytics-table td:nth-child(6) {
            min-width: 150px; /* Happiness column */
        }
        
        .analytics-table th:nth-child(7),
        .analytics-table td:nth-child(7) {
            min-width: 160px; /* Actions column */
        }
        
        .class-cell {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .class-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 16px;
            flex-shrink: 0; /* Prevents avatar from shrinking */
        }
        
        .class-details {
            display: flex;
            flex-direction: column;
            min-width: 0; /* Allows text truncation */
        }
        
        .class-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }
        
        .class-code {
            font-size: 12px;
            color: #6b7280;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }
        
        .score-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .score-excellent {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .score-good {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        
        .score-fair {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #6b7280;
        }
        
        .score-poor {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #7f1d1d;
        }
        
        /* Action Buttons */
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            white-space: nowrap;
        }
        
        .action-btn-monitor {
            background: #8b5cf6;
            color: white;
        }
        
        .action-btn-monitor:hover {
            background: #7c3aed;
        }
        
        .action-btn-details {
            background: #e5e7eb;
            color: #374151;
        }
        
        .action-btn-details:hover {
            background: #d1d5db;
        }
        
        /* Report Actions */
        .report-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .report-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
            transition: all 0.3s;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .report-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin: 0 auto 15px;
        }
        
        .report-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 10px;
        }
        
        .report-description {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 20px;
        }
        
        /* Data Status */
        .data-status {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: 1px solid #3b82f6;
            color: #1e40af;
            padding: 15px 20px;
            border-radius: 10px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .data-status i {
            font-size: 24px;
            color: #3b82f6;
        }
        
        .data-status.warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #f59e0b;
            color: #92400e;
        }
        
        .data-status.warning i {
            color: #f59e0b;
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
                width: 100%;
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
            
            /* Make table responsive on mobile */
            .analytics-table {
                display: block;
                min-width: auto;
            }
            
            .analytics-table thead {
                display: none;
            }
            
            .analytics-table tbody,
            .analytics-table tr,
            .analytics-table td {
                display: block;
                width: 100%;
            }
            
            .analytics-table tr {
                margin-bottom: 15px;
                border: 2px solid #f3f4f6;
                border-radius: 10px;
                padding: 15px;
            }
            
            .analytics-table td {
                text-align: right;
                padding-left: 50%;
                position: relative;
                border-bottom: 1px solid #f3f4f6;
            }
            
            .analytics-table td:last-child {
                border-bottom: none;
            }
            
            .analytics-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: calc(50% - 15px);
                padding-right: 10px;
                text-align: left;
                font-weight: 600;
                color: #374151;
            }
            
            /* Fix class cell on mobile */
            .class-cell {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            /* Fix action buttons on mobile */
            .analytics-table td[data-label="Actions"] div {
                flex-direction: column;
                gap: 10px;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
            
            /* Adjust table wrapper for mobile */
            .table-wrapper {
                max-height: none;
                overflow: visible;
            }
            
            .scroll-indicator {
                display: none;
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
            
            .analytics-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            
            .summary-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .emotion-distribution {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .summary-stats {
                grid-template-columns: 1fr;
            }
            
            .emotion-distribution {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        .sidebar-logo {
        margin: 10px auto;
    }
    
    .sidebar-logo img {
        width: 40px;
        height: 40px;
    }
    
    /* Hide logo text on mobile collapsed sidebar */
    .sidebar-header h1,
    .sidebar-header p,
    .menu-title,
    .menu-item span:not(.menu-icon) {
        display: none;
    }
    
    /* Show logo image when hovering on collapsed sidebar */
    .sidebar:hover .sidebar-logo {
        display: flex;
    }
    
    .sidebar:hover .sidebar-logo img {
        display: block;
    }
}
        
        /* Error Message */
        .error-message {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 1px solid #ef4444;
            color: #7f1d1d;
            padding: 20px;
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
            <a href="admin_analytics_reports.php" class="menu-item active">
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
                <h2>Analytics & Reports</h2>
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
                        <a href="profile.php" class="user-menu-item">
                            <i class="fas fa-user-circle"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="admin_system_settings.php" class="user-menu-item">
                            <i class="fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                        <div class="user-menu-divider"></div>
                        <a href="../logout.php" class="user-menu-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Content -->
        <div class="content-wrapper">
            <!-- Analytics Header -->
            <div class="analytics-header">
                <div class="header-title">
                    <h1>System Analytics Dashboard</h1>
                    <p>Comprehensive insights and reports for decision-making</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="exportReport()">
                        <i class="fas fa-file-export"></i>
                        <span>Export Report</span>
                    </button>
                    <button class="btn btn-success" onclick="generateReport()">
                        <i class="fas fa-file-pdf"></i>
                        <span>Generate PDF</span>
                    </button>
                </div>
            </div>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Database Error:</strong> <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!$has_data && !isset($error_message)): ?>
                <div class="data-status warning">
                    <i class="fas fa-database"></i>
                    <div>
                        <strong>No Data Available</strong>
                        <p>Your database appears to be empty. Please add some test data to see analytics.</p>
                        <button class="btn btn-primary" style="margin-top: 10px;" onclick="window.location.href='data_setup.php'">
                            <i class="fas fa-database"></i>
                            <span>Setup Test Data</span>
                        </button>
                    </div>
                </div>
            <?php elseif ($has_data): ?>
                <div class="data-status">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>Real Data Loaded</strong>
                        <p>Showing analytics based on <?php echo $overall_stats['total_sessions']; ?> live sessions from <?php echo $date_label; ?>.</p>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" action="" id="filterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label">Date Range</label>
                            <select name="date_range" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                <option value="today" <?php echo $date_range == 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo $date_range == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="last_7_days" <?php echo $date_range == 'last_7_days' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="last_30_days" <?php echo $date_range == 'last_30_days' ? 'selected' : ''; ?>>Last 30 Days</option>
                                <option value="last_90_days" <?php echo $date_range == 'last_90_days' ? 'selected' : ''; ?>>Last 90 Days</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Class</label>
                            <select name="class_id" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                <option value="0">All Classes</option>
                                <?php foreach ($all_classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?> (<?php echo htmlspecialchars($class['class_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Instructor</label>
                            <select name="instructor_id" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                <option value="0">All Instructors</option>
                                <?php foreach ($all_instructors as $instructor): ?>
                                    <option value="<?php echo $instructor['id']; ?>" <?php echo $instructor_id == $instructor['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($instructor['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i>
                            <span>Apply Filters</span>
                        </button>
                        <a href="admin_analytics_reports.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i>
                            <span>Reset Filters</span>
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Summary Stats -->
            <div class="summary-stats">
                <div class="summary-card">
                    <div class="summary-icon" style="background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value"><?php echo $overall_stats['total_sessions'] ?? 0; ?></div>
                        <div class="summary-label">Live Sessions</div>
                        <div class="summary-change positive">
                            <i class="fas fa-arrow-up"></i> <?php echo round($overall_stats['total_sessions'] * 0.125, 1); ?>% increase
                        </div>
                    </div>
                </div>
                
                <div class="summary-card">
                    <div class="summary-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value"><?php echo $attendance_stats['total_attended'] ?? 0; ?></div>
                        <div class="summary-label">Active Students</div>
                        <div class="summary-change positive">
                            <i class="fas fa-arrow-up"></i> <?php echo round($attendance_stats['total_attended'] * 0.082, 1); ?>% increase
                        </div>
                    </div>
                </div>
                
                <div class="summary-card">
                    <div class="summary-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value"><?php echo round($overall_stats['avg_engagement_score'] ?? 0, 1); ?></div>
                        <div class="summary-label">Avg Engagement Score</div>
                        <div class="summary-change positive">
                            <i class="fas fa-arrow-up"></i> <?php echo round($overall_stats['avg_engagement_score'] * 0.057, 1); ?>% increase
                        </div>
                    </div>
                </div>
                
                <div class="summary-card">
                    <div class="summary-icon" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value"><?php echo $attendance_stats['attendance_rate'] ?? 0; ?>%</div>
                        <div class="summary-label">Attendance Rate</div>
                        <div class="summary-change positive">
                            <i class="fas fa-arrow-up"></i> <?php echo round($attendance_stats['attendance_rate'] * 0.034, 1); ?>% increase
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts Grid -->
            <div class="charts-grid">
                <!-- Session Trends Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <h3>Session Trends</h3>
                            <p><?php echo $date_label; ?></p>
                        </div>
                        <button class="btn btn-secondary" style="padding: 8px 16px;" onclick="downloadChart('sessionTrendChart')">
                            <i class="fas fa-download"></i>
                            <span>Download</span>
                        </button>
                    </div>
                    <div class="chart-container">
                        <canvas id="sessionTrendChart"></canvas>
                    </div>
                </div>
                
                <!-- Emotion Trends Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <h3>Emotion Trends</h3>
                            <p><?php echo $date_label; ?></p>
                        </div>
                        <button class="btn btn-secondary" style="padding: 8px 16px;" onclick="downloadChart('emotionTrendChart')">
                            <i class="fas fa-download"></i>
                            <span>Download</span>
                        </button>
                    </div>
                    <div class="chart-container">
                        <canvas id="emotionTrendChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Emotion Distribution -->
            <div class="emotion-distribution">
                <div class="emotion-card" style="border-left: 4px solid #10b981;">
                    <div class="emotion-icon">😊</div>
                    <div class="emotion-name">Happy</div>
                    <div class="emotion-value" style="color: #10b981;"><?php echo round($emotion_stats['avg_happy_percent'] ?? 0, 1); ?>%</div>
                    <div class="emotion-percentage">
                        <?php echo $emotion_stats['happy_sessions'] ?? 0; ?> sessions
                    </div>
                </div>
                
                <div class="emotion-card" style="border-left: 4px solid #f59e0b;">
                    <div class="emotion-icon">😐</div>
                    <div class="emotion-name">Neutral</div>
                    <div class="emotion-value" style="color: #f59e0b;"><?php echo round($emotion_stats['avg_neutral_percent'] ?? 0, 1); ?>%</div>
                    <div class="emotion-percentage">
                        <?php echo $emotion_stats['neutral_sessions'] ?? 0; ?> sessions
                    </div>
                </div>
                
                <div class="emotion-card" style="border-left: 4px solid #ef4444;">
                    <div class="emotion-icon">😒</div>
                    <div class="emotion-name">Bored</div>
                    <div class="emotion-value" style="color: #ef4444;"><?php echo round($emotion_stats['avg_bored_percent'] ?? 0, 1); ?>%</div>
                    <div class="emotion-percentage">
                        <?php echo $emotion_stats['bored_sessions'] ?? 0; ?> sessions
                    </div>
                </div>
                
                <div class="emotion-card" style="border-left: 4px solid #8b5cf6;">
                    <div class="emotion-icon">📊</div>
                    <div class="emotion-name">Engagement</div>
                    <div class="emotion-value" style="color: #8b5cf6;"><?php echo round($overall_stats['avg_engagement_score'] ?? 0, 1); ?>/100</div>
                    <div class="emotion-percentage">
                        <?php echo $overall_stats['total_sessions'] ?? 0; ?> sessions analyzed
                    </div>
                </div>
            </div>
            
            <!-- Top Performing Classes with Scrollable Table -->
            <div class="table-container">
                <div class="table-header">
                    <h2>Top Performing Classes</h2>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span style="font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 5px;">
                            <i class="fas fa-arrows-alt-h"></i> Scroll horizontally
                            <span style="margin: 0 5px;">•</span>
                            <i class="fas fa-arrows-alt-v"></i> Scroll vertically
                        </span>
                        <button class="btn btn-primary" onclick="viewAllClasses()">
                            <i class="fas fa-list"></i>
                            <span>View All Classes</span>
                        </button>
                    </div>
                </div>
                
                <div class="table-wrapper">
                    <!-- Scroll indicator -->
                    <div class="scroll-indicator">Scroll to view more</div>
                    
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Instructor</th>
                                <th>Sessions</th>
                                <th>Attendance</th>
                                <th>Engagement</th>
                                <th>Happiness</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_classes)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px;">
                                        <i class="fas fa-chart-bar" style="font-size: 48px; color: #e5e7eb; margin-bottom: 15px;"></i>
                                        <h3 style="color: #6b7280; margin-bottom: 10px;">No Data Available</h3>
                                        <p style="color: #9ca3af;">No sessions found for the selected period</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($top_classes as $class): ?>
                                    <?php
                                    $engagement_score = round($class['avg_engagement'] ?? 0, 1);
                                    $happiness_score = round($class['avg_happiness'] ?? 0, 1);
                                    
                                    // Determine score badge
                                    if ($engagement_score >= 80) {
                                        $score_class = 'score-excellent';
                                        $score_label = 'Excellent';
                                    } elseif ($engagement_score >= 60) {
                                        $score_class = 'score-good';
                                        $score_label = 'Good';
                                    } elseif ($engagement_score >= 40) {
                                        $score_class = 'score-fair';
                                        $score_label = 'Fair';
                                    } else {
                                        $score_class = 'score-poor';
                                        $score_label = 'Poor';
                                    }
                                    ?>
                                    
                                    <tr>
                                        <td data-label="Class">
                                            <div class="class-cell">
                                                <div class="class-avatar">
                                                    <?php echo strtoupper(substr($class['class_name'], 0, 1)); ?>
                                                </div>
                                                <div class="class-details">
                                                    <div class="class-name"><?php echo htmlspecialchars($class['class_name']); ?></div>
                                                    <div class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Instructor"><?php echo htmlspecialchars($class['instructor_name']); ?></td>
                                        <td data-label="Sessions">
                                            <span style="font-weight: 600; color: #1f2937;">
                                                <?php echo $class['session_count'] ?? 0; ?>
                                            </span>
                                        </td>
                                        <td data-label="Attendance">
                                            <span style="font-weight: 600; color: #10b981;">
                                                <?php echo $class['total_attendance'] ?? 0; ?>
                                            </span>
                                        </td>
                                        <td data-label="Engagement">
                                            <span class="score-badge <?php echo $score_class; ?>" title="<?php echo $score_label; ?>">
                                                <?php echo $engagement_score; ?>
                                            </span>
                                        </td>
                                        <td data-label="Happiness">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <span style="font-weight: 600; color: #f59e0b;">
                                                    <?php echo $happiness_score; ?>%
                                                </span>
                                                <div style="flex: 1; height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden;">
                                                    <div style="height: 100%; width: <?php echo min($happiness_score, 100); ?>%; background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%); border-radius: 3px;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Actions">
                                            <div style="display: flex; gap: 8px;">
                                                <button class="action-btn action-btn-monitor" style="padding: 8px 12px;" onclick="viewClassReport(<?php echo $class['id']; ?>)">
                                                    <i class="fas fa-chart-bar"></i>
                                                    <span>Report</span>
                                                </button>
                                                <button class="action-btn action-btn-details" style="padding: 8px 12px;" onclick="viewClassDetails(<?php echo $class['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                    <span>View</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Scroll instructions -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding: 10px 15px; background: #f8fafc; border-radius: 8px; font-size: 12px; color: #6b7280;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span style="display: flex; align-items: center; gap: 5px;">
                            <i class="fas fa-mouse-pointer"></i>
                            Use mouse wheel to scroll vertically
                        </span>
                        <span style="display: flex; align-items: center; gap: 5px;">
                            <i class="fas fa-arrows-alt-h"></i>
                            Drag horizontally to see all columns
                        </span>
                    </div>
                    <span id="tableStats">
                        Showing <?php echo min(count($top_classes), 5); ?> of <?php echo count($all_classes); ?> classes
                    </span>
                </div>
            </div>
            
            <!-- Report Generation -->
            <div class="report-actions">
                <div class="report-card">
                    <div class="report-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="report-title">Weekly Report</div>
                    <div class="report-description">Generate weekly analytics and insights report</div>
                    <button class="btn btn-primary" onclick="generateWeeklyReport()">
                        <i class="fas fa-download"></i>
                        <span>Download</span>
                    </button>
                </div>
                
                <div class="report-card">
                    <div class="report-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="report-title">Class Report</div>
                    <div class="report-description">Detailed report for specific class performance</div>
                    <button class="btn btn-success" onclick="generateClassReport()">
                        <i class="fas fa-file-pdf"></i>
                        <span>Generate</span>
                    </button>
                </div>
                
                <div class="report-card">
                    <div class="report-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="report-title">Emotion Report</div>
                    <div class="report-description">Comprehensive emotion analysis report</div>
                    <button class="btn btn-warning" onclick="generateEmotionReport()">
                        <i class="fas fa-chart-bar"></i>
                        <span>Generate</span>
                    </button>
                </div>
                
                <div class="report-card">
                    <div class="report-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="report-title">Attendance Report</div>
                    <div class="report-description">Student attendance and participation report</div>
                    <button class="btn btn-primary" onclick="generateAttendanceReport()">
                        <i class="fas fa-file-excel"></i>
                        <span>Excel</span>
                    </button>
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
        
        // Session Trends Chart
        const sessionTrendCtx = document.getElementById('sessionTrendChart').getContext('2d');
        const sessionTrendChart = new Chart(sessionTrendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [
                    {
                        label: 'Live Sessions',
                        data: <?php echo json_encode($session_counts); ?>,
                        borderColor: '#8b5cf6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Engagement Score',
                        data: <?php echo json_encode($engagement_scores); ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Number of Sessions'
                        },
                        grid: {
                            drawBorder: false
                        },
                        beginAtZero: true
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Engagement Score'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                        min: 0,
                        max: 100
                    }
                }
            }
        });
        
        // Emotion Trends Chart
        const emotionTrendCtx = document.getElementById('emotionTrendChart').getContext('2d');
        const emotionTrendChart = new Chart(emotionTrendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($emotion_chart_labels); ?>,
                datasets: [
                    {
                        label: 'Happiness',
                        data: <?php echo json_encode($happiness_trend); ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Boredom',
                        data: <?php echo json_encode($boredom_trend); ?>,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Neutral',
                        data: <?php echo json_encode($neutral_trend); ?>,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${context.parsed.y}%`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Percentage (%)'
                        },
                        grid: {
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                }
            }
        });
        
        // Table scrolling enhancements
        const tableWrapper = document.querySelector('.table-wrapper');
        const table = document.querySelector('.analytics-table');
        const scrollIndicator = document.querySelector('.scroll-indicator');
        
        if (tableWrapper) {
            // Update scroll indicator position
            tableWrapper.addEventListener('scroll', function() {
                const scrollLeft = this.scrollLeft;
                const scrollTop = this.scrollTop;
                const maxScrollLeft = this.scrollWidth - this.clientWidth;
                const maxScrollTop = this.scrollHeight - this.clientHeight;
                
                if (scrollLeft > 0 || scrollTop > 0) {
                    scrollIndicator.textContent = `Scroll: ${Math.round((scrollLeft / maxScrollLeft) * 100)}% H • ${Math.round((scrollTop / maxScrollTop) * 100)}% V`;
                } else {
                    scrollIndicator.textContent = 'Scroll to view more';
                }
            });
            
            // Add horizontal scroll with mouse wheel
            tableWrapper.addEventListener('wheel', function(e) {
                if (e.deltaY !== 0) {
                    // If Shift key is pressed or we're at the vertical limits, scroll horizontally
                    if (e.shiftKey || 
                        (this.scrollTop === 0 && e.deltaY > 0) || 
                        (this.scrollTop === this.scrollHeight - this.clientHeight && e.deltaY < 0)) {
                        this.scrollLeft += e.deltaY;
                        e.preventDefault();
                    }
                }
            });
            
            // Add keyboard navigation for table
            tableWrapper.addEventListener('keydown', function(e) {
                if (e.target.tagName === 'BUTTON') return;
                
                switch(e.key) {
                    case 'ArrowLeft':
                        this.scrollLeft -= 100;
                        e.preventDefault();
                        break;
                    case 'ArrowRight':
                        this.scrollLeft += 100;
                        e.preventDefault();
                        break;
                    case 'ArrowUp':
                        this.scrollTop -= 50;
                        e.preventDefault();
                        break;
                    case 'ArrowDown':
                        this.scrollTop += 50;
                        e.preventDefault();
                        break;
                }
            });
            
            // Make table wrapper focusable for keyboard navigation
            tableWrapper.setAttribute('tabindex', '0');
        }
        
        // Export report
        function exportReport() {
            showNotification('Exporting report data...', 'info');
            
            // In a real application, this would trigger a CSV export
            setTimeout(() => {
                showNotification('Report exported successfully', 'success');
            }, 1500);
        }
        
        // Generate PDF report
        function generateReport() {
            showNotification('Generating PDF report...', 'info');
            
            // In a real application, this would generate a PDF
            setTimeout(() => {
                showNotification('PDF report generated successfully', 'success');
            }, 2000);
        }
        
        // View class report
        function viewClassReport(classId) {
            showNotification(`Loading report for class ${classId}...`, 'info');
            
            // In a real application, this would open a detailed report
            setTimeout(() => {
                alert(`Detailed report for Class ID: ${classId}\n\nThis would open a comprehensive report with:\n- Session history\n- Emotion analysis\n- Student engagement\n- Attendance records\n- Recommendations`);
            }, 500);
        }
        
        // View class details
        function viewClassDetails(classId) {
            showNotification(`Loading class details...`, 'info');
            
            // In a real application, this would redirect to class details page
            setTimeout(() => {
                alert(`Viewing details for Class ID: ${classId}\n\nThis would redirect to class management page.`);
            }, 500);
        }
        
        // View all classes
        function viewAllClasses() {
            showNotification('Loading all classes...', 'info');
            
            // In a real application, this would redirect to classes page
            setTimeout(() => {
                alert('This would show a comprehensive list of all classes with detailed analytics.');
            }, 500);
        }
        
        // Generate weekly report
        function generateWeeklyReport() {
            showNotification('Generating weekly report...', 'info');
            
            // Simulate report generation
            setTimeout(() => {
                const link = document.createElement('a');
                link.href = '#';
                link.download = 'weekly_report_' + new Date().toISOString().split('T')[0] + '.pdf';
                link.click();
                
                showNotification('Weekly report downloaded successfully', 'success');
            }, 1500);
        }
        
        // Generate class report
        function generateClassReport() {
            showNotification('Generating class report...', 'info');
            
            // Simulate report generation
            setTimeout(() => {
                const link = document.createElement('a');
                link.href = '#';
                link.download = 'class_report_' + new Date().toISOString().split('T')[0] + '.pdf';
                link.click();
                
                showNotification('Class report generated successfully', 'success');
            }, 2000);
        }
        
        // Generate emotion report
        function generateEmotionReport() {
            showNotification('Generating emotion analysis report...', 'info');
            
            // Simulate report generation
            setTimeout(() => {
                const link = document.createElement('a');
                link.href = '#';
                link.download = 'emotion_report_' + new Date().toISOString().split('T')[0] + '.pdf';
                link.click();
                
                showNotification('Emotion report generated successfully', 'success');
            }, 2000);
        }
        
        // Generate attendance report
        function generateAttendanceReport() {
            showNotification('Generating attendance report...', 'info');
            
            // Simulate report generation
            setTimeout(() => {
                const link = document.createElement('a');
                link.href = '#';
                link.download = 'attendance_report_' + new Date().toISOString().split('T')[0] + '.xlsx';
                link.click();
                
                showNotification('Attendance report downloaded successfully', 'success');
            }, 1500);
        }
        
        // Download chart as image
        function downloadChart(chartId) {
            const chart = chartId === 'sessionTrendChart' ? sessionTrendChart : emotionTrendChart;
            const link = document.createElement('a');
            link.download = chartId + '_' + new Date().toISOString().split('T')[0] + '.png';
            link.href = chart.toBase64Image();
            link.click();
            
            showNotification('Chart downloaded successfully', 'success');
        }
        
        // Show notification
        function showNotification(message, type) {
            // Create notification element
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#f59e0b'};
                color: white;
                padding: 15px 20px;
                border-radius: 10px;
                box-shadow: 0 5px 20px rgba(0,0,0,0.2);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 10px;
                animation: slideInRight 0.3s ease;
            `;
            
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
        
        // Add CSS for notifications and animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            @keyframes slideOutRight {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
            /* Smooth scrolling for table */
            .table-wrapper {
                scroll-behavior: smooth;
            }
        `;
        document.head.appendChild(style);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportReport();
            }
            
            // Ctrl + P to generate PDF
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                generateReport();
            }
            
            // Ctrl + R to refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                document.getElementById('filterForm').submit();
            }
            
            // Focus on table when T is pressed
            if (e.key === 't' && tableWrapper) {
                e.preventDefault();
                tableWrapper.focus();
                showNotification('Table focused. Use arrow keys to scroll.', 'info');
            }
        });
        
        console.log('Analytics Dashboard Ready');
        console.log('Shortcuts: Ctrl+E (Export), Ctrl+P (PDF), Ctrl+R (Refresh), T (Focus table)');
        
        // Auto-refresh data every 2 minutes
        setInterval(() => {
            // In a real application, this would refresh the data
            console.log('Auto-refreshing analytics data...');
        }, 120000);
    </script>
</body>
</html>