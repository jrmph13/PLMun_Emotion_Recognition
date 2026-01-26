<?php
// ==================== TEACHER ATTENDANCE - FULLY FUNCTIONAL ====================
require_once 'config.php';

// Require instructor role
requireInstructor();

// Get current user data
$userData = getUserData();
$userId = $_SESSION['user_id'];

if (!$userData) {
    header('Location: logout.php');
    exit();
}

// Get filter parameters
$filter_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$filter_session = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$filter_from_date = isset($_GET['from_date']) ? sanitizeInput($_GET['from_date']) : date('Y-m-01');
$filter_to_date = isset($_GET['to_date']) ? sanitizeInput($_GET['to_date']) : date('Y-m-t');
$filter_student = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

// Validate date range
if ($filter_from_date > $filter_to_date) {
    $temp = $filter_from_date;
    $filter_from_date = $filter_to_date;
    $filter_to_date = $temp;
}

// Initialize variables
$attendanceRecords = [];
$attendanceSummary = [];
$teacherClasses = [];
$teacherSessions = [];
$studentsList = [];
$message = '';
$message_type = '';

// Get teacher's classes
try {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM " . TABLE_CLASS_ENROLLMENTS . " WHERE class_id = c.id) as student_count
        FROM " . TABLE_CLASSES . " c
        WHERE c.instructor_id = ? AND c.is_active = 1
        ORDER BY c.class_name
    ");
    $stmt->execute([$userId]);
    $teacherClasses = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Classes Query Error: " . $e->getMessage());
    $message = "An error occurred while loading classes.";
    $message_type = 'error';
}

// Get teacher's sessions
$sessionWhereConditions = ["c.instructor_id = ?"];
$sessionParams = [$userId];

if ($filter_class > 0) {
    $sessionWhereConditions[] = "ls.class_id = ?";
    $sessionParams[] = $filter_class;
}

if ($filter_from_date && $filter_to_date) {
    $sessionWhereConditions[] = "DATE(ls.start_time) >= ?";
    $sessionWhereConditions[] = "DATE(ls.start_time) <= ?";
    $sessionParams[] = $filter_from_date;
    $sessionParams[] = $filter_to_date;
}

$sessionWhereClause = !empty($sessionWhereConditions) ? "WHERE " . implode(' AND ', $sessionWhereConditions) : "";

try {
    $stmt = $pdo->prepare("
        SELECT ls.*, c.class_name, c.class_code
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        $sessionWhereClause
        ORDER BY ls.start_time DESC
    ");
    $stmt->execute($sessionParams);
    $teacherSessions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Sessions Query Error: " . $e->getMessage());
    $message = "An error occurred while loading sessions.";
    $message_type = 'error';
}

// Get students in teacher's classes
if (!empty($teacherClasses)) {
    $classIds = array_column($teacherClasses, 'id');
    $placeholders = str_repeat('?,', count($classIds) - 1) . '?';
    
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id as student_id, u.full_name, s.student_number, u.email
            FROM " . TABLE_STUDENTS . " s
            JOIN " . TABLE_USERS . " u ON s.user_id = u.id
            JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
            WHERE ce.class_id IN ($placeholders)
            ORDER BY u.full_name
        ");
        $stmt->execute($classIds);
        $studentsList = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Students Query Error: " . $e->getMessage());
        $message = "An error occurred while loading students.";
        $message_type = 'error';
    }
}

// Get attendance records for teacher's classes
if (!empty($teacherClasses)) {
    try {
        // Get attendance summary by class
        $summaryWhereConditions = ["c.instructor_id = ?"];
        $summaryParams = [$userId];
        
        if ($filter_class > 0) {
            $summaryWhereConditions[] = "c.id = ?";
            $summaryParams[] = $filter_class;
        }
        
        if ($filter_from_date && $filter_to_date) {
            $summaryWhereConditions[] = "ls.start_time >= ?";
            $summaryWhereConditions[] = "ls.start_time <= ?";
            $summaryParams[] = $filter_from_date . ' 00:00:00';
            $summaryParams[] = $filter_to_date . ' 23:59:59';
        }
        
        $summaryWhereClause = !empty($summaryWhereConditions) ? "WHERE " . implode(' AND ', $summaryWhereConditions) : "";
        
        // FIXED QUERY: Get attendance summary
        $stmt = $pdo->prepare("
            SELECT 
                c.id as class_id, 
                c.class_name, 
                c.class_code,
                COUNT(DISTINCT ls.id) as total_sessions,
                COUNT(DISTINCT sa.id) as total_attendances,
                COUNT(DISTINCT ce.student_id) as total_students,
                ROUND(
                    CASE 
                        WHEN COUNT(DISTINCT ls.id) > 0 AND COUNT(DISTINCT ce.student_id) > 0 
                        THEN (COUNT(DISTINCT sa.id) * 100.0) / (COUNT(DISTINCT ls.id) * COUNT(DISTINCT ce.student_id))
                        ELSE 0
                    END, 1
                ) as overall_attendance_rate
            FROM " . TABLE_CLASSES . " c
            LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
            LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id 
                AND ls.status IN ('active', 'ended')
            LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id 
                AND ce.student_id = sa.student_id
            $summaryWhereClause
            GROUP BY c.id, c.class_name, c.class_code
            ORDER BY c.class_name
        ");
        $stmt->execute($summaryParams);
        $attendanceSummary = $stmt->fetchAll();
        
        // Get detailed attendance records
        $whereConditions = ["c.instructor_id = ?"];
        $params = [$userId];
        
        if ($filter_class > 0) {
            $whereConditions[] = "c.id = ?";
            $params[] = $filter_class;
        }
        
        if ($filter_session > 0) {
            $whereConditions[] = "ls.id = ?";
            $params[] = $filter_session;
        }
        
        if ($filter_student > 0) {
            $whereConditions[] = "s.id = ?";
            $params[] = $filter_student;
        }
        
        if ($filter_from_date && $filter_to_date) {
            $whereConditions[] = "DATE(ls.start_time) >= ?";
            $whereConditions[] = "DATE(ls.start_time) <= ?";
            $params[] = $filter_from_date;
            $params[] = $filter_to_date;
        }
        
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(' AND ', $whereConditions) : "";
        
        // UPDATED QUERY: Get detailed attendance records with status calculation
        $query = "
            SELECT 
                ls.id as session_id,
                ls.session_name,
                ls.start_time,
                ls.end_time,
                ls.status as session_status,
                c.id as class_id,
                c.class_name,
                c.class_code,
                s.id as student_id,
                u.full_name as student_name,
                s.student_number,
                sa.join_time,
                sa.leave_time,
                -- Calculate duration in minutes
                CASE 
                    WHEN sa.join_time IS NOT NULL AND sa.leave_time IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, sa.join_time, sa.leave_time)
                    WHEN sa.join_time IS NOT NULL AND ls.end_time IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, sa.join_time, ls.end_time)
                    WHEN sa.join_time IS NOT NULL AND ls.status = 'ended' 
                    THEN TIMESTAMPDIFF(MINUTE, sa.join_time, NOW())
                    ELSE 0
                END as duration_minutes,
                -- Calculate join delay in minutes
                TIMESTAMPDIFF(MINUTE, ls.start_time, sa.join_time) as join_delay_minutes,
                -- Determine attendance status based on presence and duration
                CASE 
                    WHEN sa.id IS NULL THEN 'Absent'
                    WHEN sa.join_time IS NULL THEN 'Absent'
                    ELSE 
                        CASE 
                            -- Mark as Absent if stayed less than 20 minutes
                            WHEN (
                                CASE 
                                    WHEN sa.join_time IS NOT NULL AND sa.leave_time IS NOT NULL 
                                    THEN TIMESTAMPDIFF(MINUTE, sa.join_time, sa.leave_time)
                                    WHEN sa.join_time IS NOT NULL AND ls.end_time IS NOT NULL 
                                    THEN TIMESTAMPDIFF(MINUTE, sa.join_time, ls.end_time)
                                    WHEN sa.join_time IS NOT NULL AND ls.status = 'ended' 
                                    THEN TIMESTAMPDIFF(MINUTE, sa.join_time, NOW())
                                    ELSE 0
                                END
                            ) < 20 THEN 'Absent'
                            ELSE 'Present'
                        END
                END as attendance_status
            FROM " . TABLE_CLASSES . " c
            JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id
            JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
            JOIN " . TABLE_STUDENTS . " s ON ce.student_id = s.id
            JOIN " . TABLE_USERS . " u ON s.user_id = u.id
            LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id AND sa.student_id = s.id
            $whereClause
            ORDER BY ls.start_time DESC, u.full_name
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $attendanceRecords = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Attendance Query Error: " . $e->getMessage());
        $message = "An error occurred while loading attendance records: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Calculate overall statistics
$overallStats = [
    'total_sessions' => 0,
    'total_students' => 0,
    'total_attendances' => 0,
    'attendance_rate' => 0
];

if (!empty($attendanceSummary)) {
    foreach ($attendanceSummary as $class) {
        $overallStats['total_sessions'] += $class['total_sessions'];
        $overallStats['total_students'] += $class['total_students'];
        $overallStats['total_attendances'] += $class['total_attendances'];
    }
    
    if ($overallStats['total_sessions'] > 0 && $overallStats['total_students'] > 0) {
        $total_possible_attendances = $overallStats['total_sessions'] * $overallStats['total_students'];
        if ($total_possible_attendances > 0) {
            $overallStats['attendance_rate'] = round(
                ($overallStats['total_attendances'] / $total_possible_attendances) * 100, 
                1
            );
        }
    }
}

// Helper function to format duration
function formatDuration($minutes) {
    if ($minutes < 1) {
        return 'Less than 1 min';
    } elseif ($minutes < 60) {
        return $minutes . ' min' . ($minutes != 1 ? 's' : '');
    } else {
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        if ($remainingMinutes == 0) {
            return $hours . ' hr' . ($hours != 1 ? 's' : '');
        } else {
            return $hours . ' hr' . ($hours != 1 ? 's' : '') . ' ' . $remainingMinutes . ' min' . ($remainingMinutes != 1 ? 's' : '');
        }
    }
}

// Set page title
$page_title = "Attendance Management - " . $site_name;

// Log page access
logAuditTrail(
    $userId,
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    "Accessed teacher attendance page",
    null,
    null,
    [
        'page' => 'teacher_attendance',
        'filters' => [
            'class_id' => $filter_class,
            'session_id' => $filter_session,
            'student_id' => $filter_student,
            'from_date' => $filter_from_date,
            'to_date' => $filter_to_date
        ]
    ]
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
        
        /* Message Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 12px;
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
        
        /* Filters Section - FIXED FOR BETTER LAYOUT */
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .filters-title {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 700;
        }   
        
        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }
        
        .filter-select, .filter-input {
            padding: 10px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #f8fafc;
            transition: all 0.3s;
            width: 100%;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #8b5cf6;
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        /* Date Range Container - FIXED LAYOUT */
        .date-range-group {
            grid-column: span 2; /* Make date range span 2 columns on larger screens */
        }
        
        .date-range-container {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: nowrap;
        }
        
        .date-range-container .filter-group {
            flex: 1;
            min-width: 120px;
        }
        
        .date-range-separator {
            color: #6b7280;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 8px;
            white-space: nowrap;
        }
        
        /* Action Buttons Group */
        .action-buttons-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
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
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        /* REMOVED SUMMARY CONTAINER CSS SECTION */
        
        /* Attendance Table Container */
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-top: 20px;
            max-height: 500px;
            display: flex;
            flex-direction: column;
        }
        
        .table-header {
            padding: 20px;
            border-bottom: 2px solid #f3f4f6;
            flex-shrink: 0;
        }
        
        .table-header h2 {
            color: #1f2937;
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }
        
        .table-body-container {
            flex: 1;
            overflow-y: auto;
            max-height: 400px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px; /* Reduced from 1000px since we removed 2 columns */
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f3f4f6;
            color: #4b5563;
            font-size: 13px;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-present {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .status-absent {
            background: linear-gradient(135deg, #fee2e2 0%, #fca5a5 100%);
            color: #7f1d1d;
        }
        
        .status-late {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        
        .status-on-time {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        
        /* Duration Styles */
        .duration-short {
            color: #ef4444;
            font-weight: 600;
        }
        
        .duration-medium {
            color: #f59e0b;
            font-weight: 600;
        }
        
        .duration-long {
            color: #10b981;
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
        
        /* Scrollbar Styling */
        .table-body-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .table-body-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .table-body-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .table-body-container::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .date-range-group {
                grid-column: span 1; /* On medium screens, date range takes 1 column */
            }
            
            .filters-form {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            
            .filters-form {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .date-range-group {
                grid-column: span 1;
            }
            
            .date-range-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .date-range-separator {
                display: none;
            }
            
            .table-body-container {
                max-height: 350px;
            }
            
            .data-table {
                min-width: 700px;
            }
            
            .data-table th,
            .data-table td {
                padding: 10px 12px;
                font-size: 12px;
            }
        }
        
        @media (max-width: 480px) {
            .btn {
                padding: 8px 16px;
                font-size: 13px;
            }
            
            .table-container {
                max-height: 400px;
            }
            
            .table-body-container {
                max-height: 300px;
            }
            
            .filters-section {
                padding: 20px 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar - UPDATED FOR TEACHER -->
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

            <a href="teacher_attendance.php" class="menu-item active">
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
    
    <!-- Main Content - UPDATED FOR TEACHER -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Attendance Management</h2>
            </div>
            <div class="topbar-right">
                <button class="notification-btn" id="notificationBtn">
                    <i class="fas fa-bell"></i>
                    <?php if ($overallStats['total_attendances'] > 0): ?>
                        <span class="notification-badge"><?php echo $overallStats['total_attendances']; ?></span>
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
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'info-circle')); ?>"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Filters Section - UPDATED LAYOUT -->
            <div class="filters-section">
                <div class="filters-title">Filter Attendance Records</div>
                <form method="GET" action="" class="filters-form">
                    <div class="filter-group">
                        <label class="filter-label" for="class_id">Class</label>
                        <select name="class_id" id="class_id" class="filter-select">
                            <option value="0">All Classes</option>
                            <?php foreach ($teacherClasses as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?> (<?php echo $class['student_count']; ?> students)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label" for="session_id">Session</label>
                        <select name="session_id" id="session_id" class="filter-select">
                            <option value="0">All Sessions</option>
                            <?php foreach ($teacherSessions as $session): ?>
                                <option value="<?php echo $session['id']; ?>" <?php echo $filter_session == $session['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($session['session_name'] ?: 'Session'); ?> - <?php echo formatDate($session['start_time'], 'M d, Y'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label" for="student_id">Student</label>
                        <select name="student_id" id="student_id" class="filter-select">
                            <option value="0">All Students</option>
                            <?php foreach ($studentsList as $student): ?>
                                <option value="<?php echo $student['student_id']; ?>" <?php echo $filter_student == $student['student_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo htmlspecialchars($student['student_number']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Date Range - IMPROVED LAYOUT -->
                    <div class="filter-group date-range-group">
                        <label class="filter-label" for="date_range">Date Range</label>
                        <div class="date-range-container">
                            <div class="filter-group">
                                <input type="date" 
                                       name="from_date" 
                                       id="from_date" 
                                       class="filter-input"
                                       value="<?php echo $filter_from_date; ?>"
                                       max="<?php echo date('Y-m-d'); ?>"
                                       title="Start Date">
                            </div>
                            <div class="date-range-separator">to</div>
                            <div class="filter-group">
                                <input type="date" 
                                       name="to_date" 
                                       id="to_date" 
                                       class="filter-input"
                                       value="<?php echo $filter_to_date; ?>"
                                       max="<?php echo date('Y-m-d'); ?>"
                                       title="End Date">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="filter-group action-buttons-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                        <a href="teacher_attendance.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i>
                            Clear Filters
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- REMOVED: Overall Statistics Container -->
            
            <!-- REMOVED: Attendance Summary by Class Container -->
            
            <!-- Detailed Attendance Records - UPDATED WITH ENABLED COLUMNS -->
            <div class="table-container">
                <div class="table-header">
                    <h2>Detailed Attendance Records</h2>
                    <div style="font-size: 13px; color: #6b7280; margin-top: 5px;">
                        <?php 
                        $totalRecords = count($attendanceRecords);
                        echo "Showing {$totalRecords} attendance record" . ($totalRecords != 1 ? 's' : '');
                        
                        $filterDetails = [];
                        if ($filter_class > 0) {
                            $class = array_filter($teacherClasses, function($c) use ($filter_class) {
                                return $c['id'] == $filter_class;
                            });
                            $class = reset($class);
                            if ($class) {
                                $filterDetails[] = "Class: " . htmlspecialchars($class['class_name']);
                            }
                        }
                        
                        if ($filter_session > 0) {
                            $session = array_filter($teacherSessions, function($s) use ($filter_session) {
                                return $s['id'] == $filter_session;
                            });
                            $session = reset($session);
                            if ($session) {
                                $filterDetails[] = "Session: " . htmlspecialchars($session['session_name'] ?: 'Session');
                            }
                        }
                        
                        if ($filter_student > 0) {
                            $student = array_filter($studentsList, function($s) use ($filter_student) {
                                return $s['student_id'] == $filter_student;
                            });
                            $student = reset($student);
                            if ($student) {
                                $filterDetails[] = "Student: " . htmlspecialchars($student['full_name']);
                            }
                        }
                        
                        if ($filter_from_date && $filter_to_date) {
                            $filterDetails[] = "Date: " . date('M d, Y', strtotime($filter_from_date)) . " to " . date('M d, Y', strtotime($filter_to_date));
                        }
                        
                        if (!empty($filterDetails)) {
                            echo " (" . implode(', ', $filterDetails) . ")";
                        }
                        ?>
                    </div>
                </div>
                
                <?php if (!empty($attendanceRecords)): ?>
                    <div class="table-body-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Student Number</th>
                                    <th>Session Date</th>
                                    <th>Session</th>
                                    <th>Class</th>
                                    <th>Status</th>
                                    <th>Join Time</th>
                                    <th>Duration</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendanceRecords as $record): ?>
                                    <?php 
                                    $duration = $record['duration_minutes'] ?? 0;
                                    $joinDelay = $record['join_delay_minutes'] ?? 0;
                                    $attendanceStatus = $record['attendance_status'] ?? 'Absent';
                                    
                                    // Determine status class and text
                                    $statusClass = strtolower($attendanceStatus);
                                    $statusText = $attendanceStatus;
                                    
                                    // Add late indicator for present students who joined late
                                    if ($attendanceStatus === 'Present' && $joinDelay > 5) {
                                        $statusClass = 'late';
                                        $statusText = 'Present (Late)';
                                    } elseif ($attendanceStatus === 'Present' && $joinDelay <= 5) {
                                        $statusClass = 'on-time';
                                        $statusText = 'Present (On Time)';
                                    }
                                    
                                    // Determine duration color class
                                    $durationClass = 'duration-short';
                                    if ($duration >= 60) {
                                        $durationClass = 'duration-long';
                                    } elseif ($duration >= 30) {
                                        $durationClass = 'duration-medium';
                                    }
                                    
                                    // Format duration for display
                                    $durationDisplay = formatDuration($duration);
                                    ?>
                                    
                                    <tr>
                                        <td>
                                            <strong style="font-size: 13px;"><?php echo htmlspecialchars($record['student_name']); ?></strong>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($record['student_number']); ?>
                                        </td>
                                        <td>
                                            <?php if ($record['start_time']): ?>
                                                <?php echo formatDate($record['start_time'], 'M d, Y'); ?><br>
                                                <small style="color: #6b7280; font-size: 11px;"><?php echo formatDate($record['start_time'], 'h:i A'); ?></small>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-size: 12px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($record['session_name'] ?: 'Class Session'); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($record['class_name']); ?><br>
                                            <small style="color: #6b7280; font-size: 11px;"><?php echo htmlspecialchars($record['class_code']); ?></small>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $statusClass; ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                            <?php if ($attendanceStatus === 'Absent' && $duration > 0 && $duration < 20): ?>
                                                <small style="display: block; font-size: 10px; color: #ef4445; margin-top: 2px;">
                                                    (Stayed <?php echo $duration; ?> min)
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($record['join_time'] && $attendanceStatus !== 'Absent'): ?>
                                                <?php echo formatDate($record['join_time'], 'h:i A'); ?><br>
                                                <?php if ($joinDelay > 0): ?>
                                                    <small style="color: #f59e0b; font-size: 11px;">
                                                        <?php echo "+{$joinDelay} min late"; ?>
                                                    </small>
                                                <?php else: ?>
                                                    <small style="color: #10b981; font-size: 11px;">
                                                        On time
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-size: 12px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($duration > 0 && $attendanceStatus !== 'Absent'): ?>
                                                <span class="<?php echo $durationClass; ?>">
                                                    <?php echo $durationDisplay; ?>
                                                </span>
                                                <?php if ($duration < 20): ?>
                                                    <small style="display: block; font-size: 10px; color: #ef4445;">
                                                        (Less than 20 min)
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-size: 12px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding: 30px;">
                        <i class="fas fa-clipboard-check"></i>
                        <h3>No Attendance Records Found</h3>
                        <p style="font-size: 14px;">
                            No attendance records found for the selected filters. 
                            <?php if ($filter_class > 0 || ($filter_from_date && $filter_to_date) || $filter_session > 0 || $filter_student > 0): ?>
                                Try adjusting your filters.
                            <?php else: ?>
                                Attendance records will appear here after students attend your live sessions.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Export Options -->
            <?php if (!empty($attendanceRecords)): ?>
                <div style="margin-top: 30px; text-align: center;">
                    <div style="background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                        <h3 style="margin-bottom: 12px; color: #1f2937; font-size: 15px;">Export Attendance Data</h3>
                        <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                            <a href="#" class="btn btn-success" onclick="exportData('csv')" style="font-size: 13px; padding: 8px 15px;">
                                <i class="fas fa-file-csv"></i>
                                Export as CSV
                            </a>
                            <a href="#" class="btn btn-primary" onclick="exportData('pdf')" style="font-size: 13px; padding: 8px 15px;">
                                <i class="fas fa-file-pdf"></i>
                                Export as PDF
                            </a>
                            <a href="#" class="btn btn-warning" onclick="printAttendance()" style="font-size: 13px; padding: 8px 15px;">
                                <i class="fas fa-print"></i>
                                Print Report
                            </a>
                        </div>
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
            alert('You have <?php echo $overallStats['total_attendances']; ?> total attendances across all classes.');
        });
        
        // Export functions
        function exportData(format) {
            alert(`Exporting attendance data as ${format.toUpperCase()}...\n\nThis feature would generate and download a ${format.toUpperCase()} file with attendance records.`);
        }
        
        function printAttendance() {
            window.print();
        }
        
        // Set default date ranges
        const fromDateInput = document.getElementById('from_date');
        const toDateInput = document.getElementById('to_date');
        
        // Set to_date to today if empty
        if (toDateInput && !toDateInput.value) {
            toDateInput.value = '<?php echo date("Y-m-d"); ?>';
        }
        
        // Set from_date to start of month if empty
        if (fromDateInput && !fromDateInput.value) {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            fromDateInput.value = firstDay.toISOString().split('T')[0];
        }
        
        // Validate date range
        function validateDateRange() {
            if (fromDateInput.value && toDateInput.value) {
                if (fromDateInput.value > toDateInput.value) {
                    alert('"From" date cannot be after "To" date. Dates will be swapped automatically.');
                    // Swap dates
                    const temp = fromDateInput.value;
                    fromDateInput.value = toDateInput.value;
                    toDateInput.value = temp;
                }
            }
        }
        
        // Add validation on form submit
        const filtersForm = document.querySelector('.filters-form');
        if (filtersForm) {
            filtersForm.addEventListener('submit', function(e) {
                validateDateRange();
            });
        }
        
        // Add scroll indicator when table has many rows
        const tableContainer = document.querySelector('.table-body-container');
        if (tableContainer) {
            const tableRows = tableContainer.querySelectorAll('tbody tr');
            if (tableRows.length > 10) {
                tableContainer.style.boxShadow = 'inset 0 -10px 10px -10px rgba(0,0,0,0.1)';
                
                setTimeout(() => {
                    tableContainer.scrollTop = 10;
                    setTimeout(() => {
                        tableContainer.scrollTop = 0;
                    }, 300);
                }, 1000);
            }
        }
        
        // Dynamic filter updates
        const classFilter = document.getElementById('class_id');
        const sessionFilter = document.getElementById('session_id');
        
        // Update session filter when class changes
        if (classFilter) {
            classFilter.addEventListener('change', function() {
                // This would ideally fetch sessions for the selected class via AJAX
                // For now, we'll just note that sessions are filtered by class
                console.log('Class filter changed, would fetch sessions for class:', this.value);
            });
        }
        
        // Quick date range buttons (hidden by default, can be added if needed)
        function setDateRange(days) {
            const today = new Date();
            const fromDate = new Date();
            fromDate.setDate(today.getDate() - days);
            
            fromDateInput.value = fromDate.toISOString().split('T')[0];
            toDateInput.value = today.toISOString().split('T')[0];
            
            validateDateRange();
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sidebar.classList.toggle('active');
            }
            
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printAttendance();
            }
            
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('.filters-form').scrollIntoView({ behavior: 'smooth' });
            }
            
            if (tableContainer && tableContainer.contains(document.activeElement)) {
                if (e.key === 'ArrowDown') {
                    tableContainer.scrollTop += 50;
                } else if (e.key === 'ArrowUp') {
                    tableContainer.scrollTop -= 50;
                }
            }
        });
        
        // Make date inputs responsive on mobile
        function adjustDateInputs() {
            if (window.innerWidth <= 768) {
                // On mobile, add placeholders to date inputs
                if (fromDateInput) fromDateInput.setAttribute('placeholder', 'Start Date');
                if (toDateInput) toDateInput.setAttribute('placeholder', 'End Date');
            } else {
                // On desktop, remove placeholders
                if (fromDateInput) fromDateInput.removeAttribute('placeholder');
                if (toDateInput) toDateInput.removeAttribute('placeholder');
            }
        }
        
        // Call on load and resize
        window.addEventListener('load', adjustDateInputs);
        window.addEventListener('resize', adjustDateInputs);
        
        console.log('Keyboard shortcuts available:\nCtrl+M: Toggle sidebar\nCtrl+P: Print report\nCtrl+F: Jump to filters');
    </script>
</body>
</html>