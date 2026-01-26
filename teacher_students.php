<?php
// ==================== TEACHER STUDENTS PAGE ====================
// Start session and load configuration
require_once 'config.php';

// Require instructor or admin role
requireInstructor();

// Get current user data
$userData = getUserData();
$userId = $_SESSION['user_id'];

// Handle search and filter
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$course_filter = isset($_GET['course']) ? sanitizeInput($_GET['course']) : '';
$year_filter = isset($_GET['year_level']) ? sanitizeInput($_GET['year_level']) : '';

// Get instructor's classes for filter dropdown
try {
    $stmt = $pdo->prepare("
        SELECT id, class_name, class_code 
        FROM " . TABLE_CLASSES . " 
        WHERE instructor_id = ? AND is_active = 1
        ORDER BY class_name
    ");
    $stmt->execute([$userId]);
    $teacher_classes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Classes Query Error: " . $e->getMessage());
    $teacher_classes = [];
}

// Get unique courses and year levels for filters
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.course 
        FROM " . TABLE_STUDENTS . " s
        JOIN " . TABLE_USERS . " u ON s.user_id = u.id
        JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
        JOIN " . TABLE_CLASSES . " c ON ce.class_id = c.id
        WHERE c.instructor_id = ? AND u.role = 'student'
        ORDER BY s.course
    ");
    $stmt->execute([$userId]);
    $courses = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $stmt = $pdo->prepare("
        SELECT DISTINCT s.year_level 
        FROM " . TABLE_STUDENTS . " s
        JOIN " . TABLE_USERS . " u ON s.user_id = u.id
        JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
        JOIN " . TABLE_CLASSES . " c ON ce.class_id = c.id
        WHERE c.instructor_id = ? AND u.role = 'student'
        ORDER BY s.year_level
    ");
    $stmt->execute([$userId]);
    $year_levels = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (PDOException $e) {
    error_log("Filter Query Error: " . $e->getMessage());
    $courses = [];
    $year_levels = [];
}

// Build the main query for enrolled students
$query_params = [$userId];
$query_conditions = ["c.instructor_id = ?", "u.role = 'student'"];

// Add search condition
if (!empty($search)) {
    $query_conditions[] = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR s.student_number LIKE ?)";
    $search_term = "%$search%";
    array_push($query_params, $search_term, $search_term, $search_term, $search_term);
}

// Add class filter
if ($class_id > 0) {
    $query_conditions[] = "c.id = ?";
    $query_params[] = $class_id;
}

// Add course filter
if (!empty($course_filter)) {
    $query_conditions[] = "s.course = ?";
    $query_params[] = $course_filter;
}

// Add year level filter
if (!empty($year_filter)) {
    $query_conditions[] = "s.year_level = ?";
    $query_params[] = $year_filter;
}

// Build the complete query
$where_clause = !empty($query_conditions) ? "WHERE " . implode(" AND ", $query_conditions) : "";

try {
    // Get total count for pagination
    $count_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id) as total
        FROM " . TABLE_STUDENTS . " s
        JOIN " . TABLE_USERS . " u ON s.user_id = u.id
        JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
        JOIN " . TABLE_CLASSES . " c ON ce.class_id = c.id
        $where_clause
    ");
    $count_stmt->execute($query_params);
    $total_students = $count_stmt->fetch()['total'];

    // Pagination
    $per_page = 15;
    $total_pages = ceil($total_students / $per_page);
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($page - 1) * $per_page;

    // Main query for students
    $stmt = $pdo->prepare("
        SELECT 
            s.id as student_id,
            s.student_number,
            s.course,
            s.year_level,
            s.created_at as enrolled_date,
            u.id as user_id,
            u.full_name,
            u.username,
            u.email,
            u.personal_email,
            u.contact_number,
            u.is_active,
            GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR ', ') as enrolled_classes,
            COUNT(DISTINCT c.id) as total_classes,
            COUNT(DISTINCT sa.session_id) as total_sessions_attended,
            COALESCE(AVG(ses.engagement_score), 0) as avg_engagement,
            COALESCE(MAX(ses.created_at), NULL) as last_session_date
        FROM " . TABLE_STUDENTS . " s
        JOIN " . TABLE_USERS . " u ON s.user_id = u.id
        JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
        JOIN " . TABLE_CLASSES . " c ON ce.class_id = c.id
        LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON s.id = sa.student_id
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON s.id = ses.student_id
        $where_clause
        GROUP BY s.id, u.id
        ORDER BY u.full_name ASC
        LIMIT ? OFFSET ?
    ");
    
    // Add limit and offset to parameters
    $query_params[] = $per_page;
    $query_params[] = $offset;
    
    $stmt->execute($query_params);
    $students = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Students Query Error: " . $e->getMessage());
    $error_message = "An error occurred while loading student data. Please try again later.";
    $students = [];
    $total_students = 0;
    $total_pages = 1;
    $page = 1;
}

// Set page title
$page_title = "Students Management - Emotion AI System";

// Log page access for audit trail
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && isset($_SESSION['username'])) {
    logAuditTrail(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['username'],
        'view',
        'Accessed students management page',
        'students',
        null,
        ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']
    );
}

// Helper function to get initials
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reuse styles from teacher dashboard with some additions */
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
        
        .menu-icon {
            margin-right: 15px;
            font-size: 18px;
            width: 24px;
            text-align: center;
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
        
        /* User Menu Styles - ADDED */
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
            font-size: 16px;
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
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            color: #1f2937;
            font-size: 28px;
            font-weight: 700;
        }
        
        .page-header p {
            color: #6b7280;
            margin-top: 5px;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-top: 4px solid;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
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
            font-size: 28px;
            font-weight: 800;
            color: #1f2937;
            line-height: 1;
        }
        
        .stat-icon {
            font-size: 40px;
            opacity: 0.2;
            float: right;
            margin-top: -10px;
        }
        
        /* Filters Section */
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filters-header h3 {
            color: #1f2937;
            font-weight: 600;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            color: #4b5563;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .filter-input {
            padding: 12px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .filter-input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
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
        
        .btn-secondary {
            background: #f3f4f6;
            color: #4b5563;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* Students Table */
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .table-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            color: #1f2937;
            font-weight: 600;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .students-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }
        
        .students-table th {
            background: #f8fafc;
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .students-table td {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            color: #4b5563;
        }
        
        .students-table tr:hover {
            background: #f8fafc;
        }
        
        .students-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Student Info Cell */
        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 14px;
        }
        
        .student-details h4 {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
            font-size: 14px;
        }
        
        .student-details p {
            font-size: 12px;
            color: #6b7280;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .engagement-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .engagement-high {
            background: #d1fae5;
            color: #065f46;
        }
        
        .engagement-medium {
            background: #fef3c7;
            color: #92400e;
        }
        
        .engagement-low {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            color: #4b5563;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .btn-icon:hover {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            transform: translateY(-2px);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }
        
        .pagination-btn {
            padding: 8px 16px;
            border: 1px solid #e5e7eb;
            background: white;
            color: #4b5563;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .pagination-btn:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
        }
        
        .pagination-btn.active {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            border-color: transparent;
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #e5e7eb;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            color: #6b7280;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .empty-state p {
            color: #9ca3af;
            max-width: 400px;
            margin: 0 auto 20px;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
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
            
            .filter-row {
                grid-template-columns: 1fr;
            }
            
            .user-menu-btn span {
                display: none;
            }
            
            .user-menu-btn .fa-chevron-down {
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
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .user-menu-btn {
                padding: 8px;
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
            <a href="teacher_classes.php" class="menu-item">
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
            <a href="teacher_students.php" class="menu-item active">
                <i class="menu-icon fas fa-user-graduate"></i>
                <span>Students</span>
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
                <h2>Students Management</h2>
            </div>
            <div class="topbar-right">
                <!-- User Menu - ADDED -->
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
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Enrolled Students</h1>
                    <p>Manage and monitor student participation and engagement</p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="exportStudents()">
                        <i class="fas fa-download"></i>
                        <span>Export CSV</span>
                    </button>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card" style="border-top-color: #8b5cf6;">
                    <h3>Total Students</h3>
                    <div class="stat-value"><?php echo $total_students; ?></div>
                    <i class="fas fa-users stat-icon" style="color: #8b5cf6;"></i>
                </div>
                
                <div class="stat-card" style="border-top-color: #10b981;">
                    <h3>Active Students</h3>
                    <div class="stat-value">
                        <?php 
                            $active_count = 0;
                            foreach ($students as $student) {
                                if ($student['is_active']) $active_count++;
                            }
                            echo $active_count;
                        ?>
                    </div>
                    <i class="fas fa-user-check stat-icon" style="color: #10b981;"></i>
                </div>
                
                <div class="stat-card" style="border-top-color: #3b82f6;">
                    <h3>Avg Engagement</h3>
                    <div class="stat-value">
                        <?php 
                            $total_engagement = 0;
                            $engagement_count = 0;
                            foreach ($students as $student) {
                                if ($student['avg_engagement'] > 0) {
                                    $total_engagement += $student['avg_engagement'];
                                    $engagement_count++;
                                }
                            }
                            echo $engagement_count > 0 ? round($total_engagement / $engagement_count, 1) . '%' : 'N/A';
                        ?>
                    </div>
                    <i class="fas fa-chart-line stat-icon" style="color: #3b82f6;"></i>
                </div>
                
                <div class="stat-card" style="border-top-color: #f59e0b;">
                    <h3>Classes Enrolled</h3>
                    <div class="stat-value">
                        <?php 
                            $total_classes = 0;
                            foreach ($students as $student) {
                                $total_classes += $student['total_classes'];
                            }
                            echo $total_classes;
                        ?>
                    </div>
                    <i class="fas fa-chalkboard stat-icon" style="color: #f59e0b;"></i>
                </div>
            </div>
            
            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" action="" id="filterForm">
                    <div class="filters-header">
                        <h3>Filter Students</h3>
                        <button type="button" onclick="resetFilters()" class="btn btn-secondary">
                            <i class="fas fa-redo"></i>
                            <span>Reset Filters</span>
                        </button>
                    </div>
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="search">Search Student</label>
                            <input type="text" id="search" name="search" class="filter-input" 
                                   placeholder="Search by name, student number, or email" 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="class_id">Filter by Class</label>
                            <select id="class_id" name="class_id" class="filter-input">
                                <option value="0">All Classes</option>
                                <?php foreach ($teacher_classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" 
                                        <?php echo $class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name'] . ' (' . $class['class_code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="course">Filter by Course</label>
                            <select id="course" name="course" class="filter-input">
                                <option value="">All Courses</option>
                                <?php foreach ($courses as $course): ?>
                                    <?php if (!empty($course)): ?>
                                        <option value="<?php echo htmlspecialchars($course); ?>" 
                                            <?php echo $course_filter == $course ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($course); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="year_level">Filter by Year Level</label>
                            <select id="year_level" name="year_level" class="filter-input">
                                <option value="">All Year Levels</option>
                                <?php foreach ($year_levels as $year): ?>
                                    <?php if (!empty($year)): ?>
                                        <option value="<?php echo htmlspecialchars($year); ?>" 
                                            <?php echo $year_filter == $year ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i>
                            <span>Apply Filters</span>
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Students Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Enrolled Students List</h3>
                    <div>
                        <span class="text-sm text-gray-600">
                            Showing <?php echo count($students); ?> of <?php echo $total_students; ?> students
                        </span>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th>Student Information</th>
                                <th>Student Number</th>
                                <th>Course & Year</th>
                                <th>Classes Enrolled</th>
                                <th>Sessions Attended</th>
                                <th>Engagement Score</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <i class="fas fa-user-graduate"></i>
                                            <h3>No Students Found</h3>
                                            <p>No students are enrolled in your classes or match your search criteria.</p>
                                            <?php if (!empty($search) || $class_id > 0 || !empty($course_filter) || !empty($year_filter)): ?>
                                                <button onclick="resetFilters()" class="btn btn-primary">
                                                    <i class="fas fa-redo"></i>
                                                    <span>Clear Filters</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td>
                                            <div class="student-info">
                                                <div class="student-avatar">
                                                    <?php echo getInitials($student['full_name']); ?>
                                                </div>
                                                <div class="student-details">
                                                    <h4><?php echo htmlspecialchars($student['full_name']); ?></h4>
                                                    <p><?php echo htmlspecialchars($student['email']); ?></p>
                                                    <?php if (!empty($student['contact_number'])): ?>
                                                        <p style="font-size: 11px; color: #9ca3af;">
                                                            <i class="fas fa-phone-alt"></i> 
                                                            <?php echo htmlspecialchars($student['contact_number']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($student['student_number']); ?></strong>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($student['course']); ?></strong>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    Year <?php echo htmlspecialchars($student['year_level']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="max-width: 150px;">
                                                <div style="font-weight: 600; margin-bottom: 5px;">
                                                    <?php echo $student['total_classes']; ?> class(es)
                                                </div>
                                                <div style="font-size: 11px; color: #6b7280; line-height: 1.4;">
                                                    <?php 
                                                        $classes = explode(', ', $student['enrolled_classes']);
                                                        if (count($classes) > 2) {
                                                            echo htmlspecialchars(implode(', ', array_slice($classes, 0, 2))) . '...';
                                                        } else {
                                                            echo htmlspecialchars($student['enrolled_classes']);
                                                        }
                                                    ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="text-align: center;">
                                                <div style="font-weight: 700; font-size: 18px; color: #1f2937;">
                                                    <?php echo $student['total_sessions_attended']; ?>
                                                </div>
                                                <div style="font-size: 11px; color: #6b7280;">
                                                    sessions
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($student['avg_engagement'] > 0): ?>
                                                <?php
                                                    $engagement_class = 'engagement-medium';
                                                    if ($student['avg_engagement'] >= 80) {
                                                        $engagement_class = 'engagement-high';
                                                    } elseif ($student['avg_engagement'] < 60) {
                                                        $engagement_class = 'engagement-low';
                                                    }
                                                ?>
                                                <div class="engagement-badge <?php echo $engagement_class; ?>">
                                                    <i class="fas fa-chart-line"></i>
                                                    <?php echo round($student['avg_engagement'], 1); ?>%
                                                </div>
                                                <?php if ($student['last_session_date']): ?>
                                                    <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">
                                                        Last: <?php echo relativeTime($student['last_session_date']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-size: 12px;">No data</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($student['is_active']): ?>
                                                <span class="status-badge status-active">
                                                    <i class="fas fa-check-circle"></i> Active
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-inactive">
                                                    <i class="fas fa-times-circle"></i> Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="student_profile.php?id=<?php echo $student['student_id']; ?>" 
                                                   class="btn-icon" title="View Profile">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="student_engagement.php?student_id=<?php echo $student['student_id']; ?>" 
                                                   class="btn-icon" title="Engagement History">
                                                    <i class="fas fa-chart-line"></i>
                                                </a>
                                                <a href="mailto:<?php echo urlencode($student['email']); ?>" 
                                                   class="btn-icon" title="Send Email">
                                                    <i class="fas fa-envelope"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <!-- Previous Page -->
                    <button class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>" 
                            onclick="changePage(<?php echo $page - 1; ?>)" 
                            <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    
                    <!-- Page Numbers -->
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <button class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>" 
                                onclick="changePage(<?php echo $i; ?>)">
                            <?php echo $i; ?>
                        </button>
                    <?php endfor; ?>
                    
                    <!-- Next Page -->
                    <button class="pagination-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" 
                            onclick="changePage(<?php echo $page + 1; ?>)" 
                            <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                        <i class="fas fa-chevron-right"></i>
                    </button>
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
        
        // User menu dropdown - UPDATED
        const userMenuBtn = document.getElementById('userMenuBtn');
        const userMenuDropdown = document.getElementById('userMenuDropdown');
        
        userMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            userMenuDropdown.classList.toggle('show');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!userMenuBtn.contains(e.target) && !userMenuDropdown.contains(e.target)) {
                userMenuDropdown.classList.remove('show');
            }
        });
        
        // Close dropdown when clicking on a menu item
        document.querySelectorAll('.user-menu-item').forEach(item => {
            item.addEventListener('click', () => {
                userMenuDropdown.classList.remove('show');
            });
        });
        
        // Function to change page
        function changePage(page) {
            const form = document.getElementById('filterForm');
            const pageInput = document.createElement('input');
            pageInput.type = 'hidden';
            pageInput.name = 'page';
            pageInput.value = page;
            form.appendChild(pageInput);
            form.submit();
        }
        
        // Function to reset filters
        function resetFilters() {
            window.location.href = 'teacher_students.php';
        }
        
        // Function to export students to CSV
        function exportStudents() {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            
            // Show loading
            const originalText = document.querySelector('.btn-primary span').textContent;
            document.querySelector('.btn-primary span').textContent = 'Exporting...';
            document.querySelector('.btn-primary i').className = 'fas fa-spinner fa-spin';
            
            // Create export URL
            const exportUrl = 'export_students.php?' + params.toString();
            
            // Create hidden iframe for download
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = exportUrl;
            document.body.appendChild(iframe);
            
            // Restore button after 2 seconds
            setTimeout(() => {
                document.querySelector('.btn-primary span').textContent = originalText;
                document.querySelector('.btn-primary i').className = 'fas fa-download';
                document.body.removeChild(iframe);
            }, 2000);
        }
        
        // Auto-submit form when filter changes (optional)
        document.querySelectorAll('.filter-input').forEach(input => {
            input.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + F to focus on search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search').focus();
            }
            
            // Ctrl + R to reset filters
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                resetFilters();
            }
            
            // Escape to close dropdowns
            if (e.key === 'Escape') {
                userMenuDropdown.classList.remove('show');
            }
        });
        
        // Display keyboard shortcut hint
        console.log('Keyboard shortcuts:\nCtrl+F: Focus search\nCtrl+R: Reset filters\nEscape: Close dropdowns');
    </script>
</body>
</html>