<?php
// ==================== STUDENT ANNOUNCEMENTS ====================
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
$filter_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

// Get student's enrolled classes
$studentClasses = getUserClasses($userId, 'student');

// Get announcements for student's classes
$announcements = [];
$unreadCount = 0;
$readAnnouncements = [];

try {
    if ($studentId && !empty($studentClasses)) {
        $classIds = array_column($studentClasses, 'id');
        
        // Get which announcements the student has already read
        $stmt = $pdo->prepare("
            SELECT announcement_id 
            FROM announcement_reads 
            WHERE student_id = ?
        ");
        $stmt->execute([$studentId]);
        $readAnnouncements = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Build base query for announcements
        $base_query = "
            SELECT a.*, c.class_name, c.class_code, u.full_name as instructor_name,
                   c.id as class_id,
                   CASE WHEN ar.announcement_id IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   COALESCE(ar.read_at, NULL) as read_at
            FROM " . TABLE_ANNOUNCEMENTS . " a
            JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
            JOIN " . TABLE_USERS . " u ON a.teacher_id = u.id
            LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.student_id = ?
            WHERE a.class_id IN (" . str_repeat('?,', count($classIds) - 1) . "?)
        ";
        
        $params = array_merge([$studentId], $classIds);
        
        // Apply class filter (if 0, show all classes)
        if ($filter_class_id > 0) {
            $base_query .= " AND a.class_id = ?";
            $params[] = $filter_class_id;
        }
        
        $base_query .= " ORDER BY a.created_at DESC";
        
        // Execute main query for all announcements
        $stmt = $pdo->prepare($base_query);
        $stmt->execute($params);
        $announcements = $stmt->fetchAll();
        
        // Count unread announcements
        foreach ($announcements as $announcement) {
            if (!$announcement['is_read']) {
                $unreadCount++;
            }
        }
        
        // Handle mark as read request
        if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
            $announcementId = intval($_GET['mark_read']);
            
            // Check if student has access to this announcement
            $stmt = $pdo->prepare("
                SELECT a.id 
                FROM " . TABLE_ANNOUNCEMENTS . " a
                JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
                JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
                WHERE a.id = ? AND ce.student_id = ?
            ");
            $stmt->execute([$announcementId, $studentId]);
            
            if ($stmt->fetch()) {
                // Mark as read
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO announcement_reads (announcement_id, student_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$announcementId, $studentId]);
                
                // Log audit trail
                logAuditTrail(
                    $userId,
                    $_SESSION['role'],
                    $_SESSION['username'],
                    'update',
                    "Marked announcement as read",
                    'announcement_reads',
                    $pdo->lastInsertId(),
                    ['announcement_id' => $announcementId]
                );
                
                // Refresh page to update status
                header("Location: student_announcement.php" . ($filter_class_id > 0 ? "?class_id=" . $filter_class_id : ""));
                exit();
            }
        }
        
        // Handle mark all as read request
        if (isset($_GET['mark_all_read'])) {
            $filter_condition = "";
            $filter_params = [];
            
            if ($filter_class_id > 0) {
                $filter_condition = "AND a.class_id = ?";
                $filter_params = [$filter_class_id];
            }
            
            // Get all unread announcement IDs for the current student
            $stmt = $pdo->prepare("
                SELECT a.id 
                FROM " . TABLE_ANNOUNCEMENTS . " a
                JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
                JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
                LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.student_id = ?
                WHERE ce.student_id = ? 
                AND ar.announcement_id IS NULL
                $filter_condition
            ");
            
            $params = array_merge([$studentId, $studentId], $filter_params);
            $stmt->execute($params);
            $unreadIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($unreadIds)) {
                // Mark all as read
                $placeholders = str_repeat('?,', count($unreadIds) - 1) . '?';
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO announcement_reads (announcement_id, student_id)
                    SELECT id, ? FROM " . TABLE_ANNOUNCEMENTS . " 
                    WHERE id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$studentId], $unreadIds));
                
                // Log audit trail
                logAuditTrail(
                    $userId,
                    $_SESSION['role'],
                    $_SESSION['username'],
                    'update',
                    "Marked all announcements as read",
                    'announcement_reads',
                    null,
                    ['count' => count($unreadIds)]
                );
                
                $message = "All announcements marked as read!";
                $message_type = 'success';
            }
        }
        
        // Log audit trail
        logAuditTrail(
            $userId,
            $_SESSION['role'],
            $_SESSION['username'],
            'view',
            "Viewed announcements page",
            'announcements',
            null,
            [
                'filter_class_id' => $filter_class_id,
                'announcement_count' => count($announcements),
                'unread_count' => $unreadCount
            ]
        );
    }
    
} catch (PDOException $e) {
    error_log("Announcements Query Error: " . $e->getMessage());
    $message = "An error occurred while loading announcements.";
    $message_type = 'error';
}

// Function to get relative time for announcements
function getAnnouncementTime($dateString) {
    if (empty($dateString) || $dateString == '0000-00-00 00:00:00') return '';
    
    try {
        // Convert database time (UTC) to server time (PH) by adding 1 hour
        $dbTimestamp = strtotime($dateString);
        $serverTimestamp = $dbTimestamp + 3600; // Add 1 hour for UTC to PH conversion
        
        // Get current server time
        $nowTimestamp = time();
        
        // Calculate difference in seconds
        $diffInSeconds = $nowTimestamp - $serverTimestamp;
        
        // If the announcement is in the future (shouldn't happen)
        if ($diffInSeconds < 0) {
            return 'Just now';
        }
        
        // Less than 1 minute
        if ($diffInSeconds < 60) {
            return 'Just now';
        }
        
        // Less than 2 minutes
        if ($diffInSeconds < 120) {
            return '1 minute ago';
        }
        
        // Less than 1 hour
        if ($diffInSeconds < 3600) {
            $minutes = floor($diffInSeconds / 60);
            return $minutes . ' minutes ago';
        }
        
        // Less than 2 hours
        if ($diffInSeconds < 7200) {
            return '1 hour ago';
        }
        
        // Less than 1 day
        if ($diffInSeconds < 86400) {
            $hours = floor($diffInSeconds / 3600);
            return $hours . ' hours ago';
        }
        
        // Less than 2 days
        if ($diffInSeconds < 172800) {
            return 'Yesterday';
        }
        
        // Less than 1 week
        if ($diffInSeconds < 604800) {
            $days = floor($diffInSeconds / 86400);
            return $days . ' days ago';
        }
        
        // Less than 1 month
        if ($diffInSeconds < 2592000) {
            $weeks = floor($diffInSeconds / 604800);
            return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
        }
        
        // Less than 1 year
        if ($diffInSeconds < 31536000) {
            $months = floor($diffInSeconds / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        }
        
        // More than 1 year
        $years = floor($diffInSeconds / 31536000);
        return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        
    } catch (Exception $e) {
        // If there's an error, return a simple formatted date
        return date('M d, Y', strtotime($dateString));
    }
}

// Set page title
$page_title = "Announcements - " . $site_name;
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
        
        /* Filter Card Styles */
        .filter-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.06);
            margin-bottom: 20px;
        }
        
        .filter-card h3 {
            color: #1f2937;
            margin-bottom: 15px;
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-form {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 250px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }
        
        .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: #f8fafc;
            cursor: pointer;
        }
        
        .form-select:focus {
            outline: none;
            border-color: #8b5cf6;
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        /* Buttons */
        .btn {
            padding: 10px 18px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        /* Results Summary */
        .results-summary {
            background: white;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.06);
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .results-summary h3 {
            color: #1f2937;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .results-count {
            font-size: 13px;
            color: #6b7280;
            background: #f3f4f6;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .results-count span {
            color: #8b5cf6;
            font-weight: 700;
        }
        
        /* Announcements List with Scroll Bar */
        .announcements-list-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.06);
            overflow: hidden;
            max-height: <?php echo count($announcements) >= 10 ? '600px' : 'auto'; ?>;
        }
        
        .announcements-list {
            max-height: <?php echo count($announcements) >= 10 ? '600px' : 'none'; ?>;
            overflow-y: <?php echo count($announcements) >= 10 ? 'auto' : 'visible'; ?>;
            padding-right: 10px;
        }
        
        /* Custom scrollbar styling */
        .announcements-list::-webkit-scrollbar {
            width: 8px;
        }
        
        .announcements-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .announcements-list::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .announcements-list::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        .announcement-item {
            padding: 18px 20px;
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.3s;
            position: relative;
        }
        
        .announcement-item:hover {
            background: #f8fafc;
        }
        
        .announcement-item:last-child {
            border-bottom: none;
        }
        
        .announcement-item.unread {
            border-left: 4px solid #8b5cf6;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
        }
        
        .announcement-item.read {
            border-left: 4px solid #10b981;
            opacity: 0.8;
        }
        
        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            gap: 12px;
        }
        
        .announcement-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
            flex: 1;
        }
        
        .announcement-title a {
            color: inherit;
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .announcement-title a:hover {
            color: #8b5cf6;
        }
        
        .announcement-class {
            display: inline-block;
            padding: 4px 10px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Read/Unread Indicators */
        .read-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 10px;
        }
        
        .status-read {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .status-unread {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .mark-read-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border: 1px solid #8b5cf6;
            background: transparent;
            color: #8b5cf6;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .mark-read-btn:hover {
            background: #8b5cf6;
            color: white;
        }
        
        /* Content */
        .announcement-content {
            color: #4b5563;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 12px;
        }
        
        .announcement-content.expanded {
            max-height: none;
        }
        
        .announcement-content.collapsed {
            max-height: 60px;
            overflow: hidden;
            position: relative;
        }
        
        .announcement-content.collapsed::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 30px;
            background: linear-gradient(to bottom, transparent, white);
        }
        
        .read-more-btn {
            background: none;
            border: none;
            color: #8b5cf6;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 0;
            font-size: 13px;
            margin-bottom: 10px;
        }
        
        .read-more-btn:hover {
            text-decoration: underline;
        }
        
        /* Announcement Meta */
        .announcement-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 12px;
            color: #6b7280;
            align-items: center;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .meta-item i {
            color: #8b5cf6;
            font-size: 12px;
        }
        
        /* Read Time */
        .read-time {
            display: flex;
            align-items: center;
            gap: 4px;
            color: #10b981;
            font-weight: 600;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #6b7280;
            background: white;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.06);
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
        
        /* Scroll Indicator */
        .scroll-indicator {
            text-align: center;
            padding: 10px;
            background: #f8fafc;
            color: #6b7280;
            font-size: 12px;
            border-top: 1px solid #e5e7eb;
            display: <?php echo count($announcements) >= 10 ? 'block' : 'none'; ?>;
        }
        
        .scroll-indicator i {
            animation: bounce 2s infinite;
            margin: 0 5px;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-5px);
            }
            60% {
                transform: translateY(-3px);
            }
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
                padding: 12px;
                margin: 4px;
                border-radius: 8px;
            }
            
            .menu-icon {
                margin-right: 0;
                font-size: 18px;
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
            
            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-group {
                min-width: 100%;
            }
            
            .announcement-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .announcement-meta {
                gap: 8px;
            }
            
            .results-summary {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .announcements-list-container {
                max-height: 500px;
            }
            
            .announcements-list {
                max-height: 500px;
            }
        }
        
        @media (max-width: 480px) {
            .announcement-title {
                font-size: 15px;
            }
            
            .announcement-content {
                font-size: 13px;
            }
            
            .announcement-meta {
                font-size: 11px;
            }
            
            .announcements-list-container {
                max-height: 400px;
            }
            
            .announcements-list {
                max-height: 400px;
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
            </a>
            
            <a href="student_announcement.php" class="menu-item active">
                <i class="menu-icon fas fa-bullhorn"></i>
                <span>Announcement</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="menu-badge"><?php echo $unreadCount; ?></span>
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
                <h2>Class Announcements</h2>
            </div>
            <div class="topbar-right">
                <button class="notification-btn" id="notificationBtn">
                    <i class="fas fa-bell"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notification-badge"><?php echo $unreadCount; ?></span>
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
                        <a href="privacy_consent.php" class="user-menu-item">
                            <i class="fas fa-shield-alt"></i>
                            <span>Privacy & Consent</span>
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
            
            <!-- Filter Card -->
            <div class="filter-card">
                <h3><i class="fas fa-filter"></i> Filter Announcements</h3>
                <div class="filter-form">
                    <div class="form-group">
                        <label class="form-label" for="class_id">Select Class</label>
                        <select id="class_id" name="class_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All Classes</option>
                            <?php foreach ($studentClasses as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $filter_class_id == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?> (<?php echo htmlspecialchars($class['class_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($unreadCount > 0): ?>
                        <a href="student_announcement.php?<?php echo $filter_class_id > 0 ? 'class_id=' . $filter_class_id . '&' : ''; ?>mark_all_read=1" 
                           class="btn btn-success"
                           onclick="return confirm('Mark all announcements as read?')">
                            <i class="fas fa-check-double"></i>
                            Mark All as Read
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($filter_class_id > 0): ?>
                        <a href="student_announcement.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Clear Filter
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Results Summary -->
            <div class="results-summary">
                <h3><i class="fas fa-bullhorn"></i> Announcements</h3>
                <div class="results-count">
                    <span><?php echo count($announcements); ?></span> announcement(s) 
                    <?php if ($unreadCount > 0): ?>
                        (<span style="color: #f59e0b;"><?php echo $unreadCount; ?></span> unread)
                    <?php else: ?>
                        (All read)
                    <?php endif; ?>
                    <?php if ($filter_class_id > 0): ?>
                        for selected class
                    <?php else: ?>
                        across all classes
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Main Announcements List -->
            <div>
                <?php if (!empty($announcements)): ?>
                    <div class="announcements-list-container">
                        <div class="announcements-list" id="announcementsList">
                            <?php foreach ($announcements as $announcement): ?>
                                <?php
                                // Calculate relative time
                                $relative_time = getAnnouncementTime($announcement['created_at']);
                                
                                // Calculate if announcement is read
                                $isRead = $announcement['is_read'];
                                
                                // Calculate if announcement is new (within 24 hours AND unread)
                                $dbTimestamp = strtotime($announcement['created_at']);
                                $adjustedTimestamp = $dbTimestamp + 3600; // Add 1 hour for UTC to PH conversion
                                $isNew = (!$isRead && ($adjustedTimestamp + 86400) > time()); // Within last 24 hours and unread
                                
                                // Check if content is long
                                $content_length = strlen($announcement['message']);
                                $isLong = $content_length > 200;
                                $display_content = $isLong ? substr($announcement['message'], 0, 200) . '...' : $announcement['message'];
                                
                                // Get formatted times
                                $formatted_date = date('M d, Y', $adjustedTimestamp);
                                $iso_date = date('c', $adjustedTimestamp);
                                
                                // Get read time if available
                                $read_time = '';
                                if ($isRead && $announcement['read_at']) {
                                    $readTimestamp = strtotime($announcement['read_at']) + 3600;
                                    $read_time = 'Read ' . getAnnouncementTime($announcement['read_at']);
                                }
                                ?>
                                
                                <div class="announcement-item <?php echo $isRead ? 'read' : 'unread'; ?>" 
                                     data-created="<?php echo $iso_date; ?>"
                                     data-announcement-id="<?php echo $announcement['id']; ?>"
                                     data-is-read="<?php echo $isRead ? '1' : '0'; ?>">
                                    <div class="announcement-header">
                                        <div style="flex: 1;">
                                            <div class="announcement-title">
                                                <?php echo htmlspecialchars($announcement['title']); ?>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px;">
                                                <div class="announcement-class">
                                                    <?php echo htmlspecialchars($announcement['class_name']); ?> (<?php echo htmlspecialchars($announcement['class_code']); ?>)
                                                </div>
                                                
                                                <?php if ($isRead): ?>
                                                    <div class="read-status status-read">
                                                        <i class="fas fa-check-circle"></i>
                                                        Read
                                                    </div>
                                                <?php else: ?>
                                                    <div class="read-status status-unread">
                                                        <i class="fas fa-exclamation-circle"></i>
                                                        Unread
                                                    </div>
                                                    <a href="student_announcement.php?<?php echo $filter_class_id > 0 ? 'class_id=' . $filter_class_id . '&' : ''; ?>mark_read=<?php echo $announcement['id']; ?>" 
                                                       class="mark-read-btn">
                                                        <i class="fas fa-check"></i>
                                                        Mark as Read
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($isNew): ?>
                                            <div class="read-status status-unread">
                                                <i class="fas fa-star"></i>
                                                New
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="announcement-content <?php echo $isLong ? 'collapsed' : 'expanded'; ?>" 
                                         id="content-<?php echo $announcement['id']; ?>">
                                        <?php echo nl2br(htmlspecialchars($display_content)); ?>
                                    </div>
                                    
                                    <?php if ($isLong): ?>
                                        <button class="read-more-btn" onclick="toggleReadMore(<?php echo $announcement['id']; ?>)">
                                            <i class="fas fa-chevron-down"></i>
                                            Read More
                                        </button>
                                    <?php endif; ?>
                                    
                                    <div class="announcement-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-user-tie"></i>
                                            <span><?php echo htmlspecialchars($announcement['instructor_name']); ?></span>
                                        </div>
                                        
                                        <div class="meta-item">
                                            <i class="far fa-clock"></i>
                                            <span class="relative-time" data-iso="<?php echo $iso_date; ?>">
                                                <?php echo $relative_time; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="meta-item">
                                            <i class="far fa-calendar"></i>
                                            <span><?php echo $formatted_date; ?></span>
                                        </div>
                                        
                                        <?php if ($isRead && $read_time): ?>
                                            <div class="meta-item">
                                                <i class="fas fa-check-circle"></i>
                                                <span class="read-time"><?php echo $read_time; ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (count($announcements) >= 10): ?>
                            <div class="scroll-indicator">
                                <i class="fas fa-chevron-down"></i>
                                Scroll for more announcements
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-bullhorn"></i>
                        <h3>No Announcements Found</h3>
                        <p>
                            <?php if ($filter_class_id > 0): ?>
                                There are no announcements for the selected class.
                            <?php elseif (empty($studentClasses)): ?>
                                You are not enrolled in any classes yet.
                            <?php else: ?>
                                There are no announcements for your enrolled classes yet.
                            <?php endif; ?>
                        </p>
                        <?php if ($filter_class_id > 0): ?>
                            <div style="margin-top: 20px;">
                                <a href="student_announcement.php" class="btn btn-primary">
                                    <i class="fas fa-times"></i>
                                    Show All Classes
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
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
            alert('You have <?php echo $unreadCount; ?> unread announcement(s).');
        });
        
        // Read More functionality
        function toggleReadMore(announcementId) {
            const content = document.getElementById('content-' + announcementId);
            const button = content.nextElementSibling;
            
            if (content.classList.contains('collapsed')) {
                content.classList.remove('collapsed');
                content.classList.add('expanded');
                button.innerHTML = '<i class="fas fa-chevron-up"></i> Show Less';
                
                // Auto-mark as read when expanding
                const announcementItem = content.closest('.announcement-item');
                if (announcementItem && announcementItem.getAttribute('data-is-read') === '0') {
                    const announcementId = announcementItem.getAttribute('data-announcement-id');
                    markAsRead(announcementId);
                }
            } else {
                content.classList.remove('expanded');
                content.classList.add('collapsed');
                button.innerHTML = '<i class="fas fa-chevron-down"></i> Read More';
            }
        }
        
        // Mark announcement as read via AJAX
        function markAsRead(announcementId) {
            // Update UI immediately
            const announcementItem = document.querySelector(`[data-announcement-id="${announcementId}"]`);
            if (announcementItem) {
                announcementItem.classList.remove('unread');
                announcementItem.classList.add('read');
                announcementItem.setAttribute('data-is-read', '1');
                
                // Update status badge
                const statusDiv = announcementItem.querySelector('.read-status.status-unread');
                if (statusDiv) {
                    statusDiv.className = 'read-status status-read';
                    statusDiv.innerHTML = '<i class="fas fa-check-circle"></i> Read';
                }
                
                // Remove "Mark as Read" button
                const markReadBtn = announcementItem.querySelector('.mark-read-btn');
                if (markReadBtn) {
                    markReadBtn.remove();
                }
                
                // Update unread count in badge
                updateUnreadCount();
            }
            
            // Send AJAX request to server
            const xhr = new XMLHttpRequest();
            xhr.open('GET', 'student_announcement.php?mark_read=' + announcementId, true);
            xhr.send();
        }
        
        // Update unread count
        function updateUnreadCount() {
            const unreadItems = document.querySelectorAll('.announcement-item.unread');
            const unreadCount = unreadItems.length;
            
            // Update sidebar badge
            const sidebarBadge = document.querySelector('.menu-item.active .menu-badge');
            if (unreadCount > 0) {
                if (sidebarBadge) {
                    sidebarBadge.textContent = unreadCount;
                } else {
                    // Create badge if it doesn't exist
                    const menuItem = document.querySelector('.menu-item.active');
                    const badge = document.createElement('span');
                    badge.className = 'menu-badge';
                    badge.textContent = unreadCount;
                    menuItem.appendChild(badge);
                }
            } else if (sidebarBadge) {
                sidebarBadge.remove();
            }
            
            // Update topbar notification badge
            const notificationBadge = document.querySelector('.notification-badge');
            if (unreadCount > 0) {
                if (notificationBadge) {
                    notificationBadge.textContent = unreadCount;
                } else {
                    // Create badge if it doesn't exist
                    const notificationBtn = document.getElementById('notificationBtn');
                    const badge = document.createElement('span');
                    badge.className = 'notification-badge';
                    badge.textContent = unreadCount;
                    notificationBtn.appendChild(badge);
                }
            } else if (notificationBadge) {
                notificationBadge.remove();
            }
            
            // Update results summary
            const resultsCount = document.querySelector('.results-count span[style*="color: #f59e0b"]');
            if (unreadCount > 0) {
                if (resultsCount) {
                    resultsCount.textContent = unreadCount;
                } else {
                    // Add unread count to results summary
                    const resultsDiv = document.querySelector('.results-count');
                    if (resultsDiv) {
                        resultsDiv.innerHTML = resultsDiv.innerHTML.replace(
                            /\(\d+ unread\)/,
                            `(<span style="color: #f59e0b;">${unreadCount}</span> unread)`
                        );
                    }
                }
            } else {
                // Remove unread count from results summary
                const resultsDiv = document.querySelector('.results-count');
                if (resultsDiv) {
                    resultsDiv.innerHTML = resultsDiv.innerHTML.replace(/\(\d+ unread\)/, '(All read)');
                }
            }
        }
        
        // Auto-mark as read when clicking on announcement
        document.querySelectorAll('.announcement-title').forEach(title => {
            title.addEventListener('click', function(e) {
                const announcementItem = this.closest('.announcement-item');
                if (announcementItem && announcementItem.getAttribute('data-is-read') === '0') {
                    const announcementId = announcementItem.getAttribute('data-announcement-id');
                    markAsRead(announcementId);
                }
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
        
        // Function to update relative times
        function updateAllRelativeTimes() {
            document.querySelectorAll('.relative-time').forEach(timeElement => {
                const isoDate = timeElement.getAttribute('data-iso');
                if (isoDate) {
                    const relativeTime = getRelativeTime(isoDate);
                    timeElement.textContent = relativeTime;
                }
            });
        }
        
        // Function to calculate relative time
        function getRelativeTime(isoDate) {
            try {
                const date = new Date(isoDate);
                const now = new Date();
                const diffInSeconds = Math.floor((now - date) / 1000);
                
                if (diffInSeconds < 0) return 'Just now';
                if (diffInSeconds < 60) return 'Just now';
                if (diffInSeconds < 120) return '1 minute ago';
                if (diffInSeconds < 3600) return Math.floor(diffInSeconds / 60) + ' minutes ago';
                if (diffInSeconds < 7200) return '1 hour ago';
                if (diffInSeconds < 86400) return Math.floor(diffInSeconds / 3600) + ' hours ago';
                if (diffInSeconds < 172800) return 'Yesterday';
                if (diffInSeconds < 604800) return Math.floor(diffInSeconds / 86400) + ' days ago';
                if (diffInSeconds < 2592000) return Math.floor(diffInSeconds / 604800) + ' weeks ago';
                if (diffInSeconds < 31536000) return Math.floor(diffInSeconds / 2592000) + ' months ago';
                return Math.floor(diffInSeconds / 31536000) + ' years ago';
                
            } catch (error) {
                return 'Recent';
            }
        }
        
        // Smooth scroll for announcements list
        const announcementsList = document.getElementById('announcementsList');
        if (announcementsList && <?php echo count($announcements) >= 10 ? 'true' : 'false'; ?>) {
            // Add smooth scrolling
            announcementsList.style.scrollBehavior = 'smooth';
            
            // Add scroll event listener to hide indicator when scrolled
            announcementsList.addEventListener('scroll', function() {
                const scrollIndicator = document.querySelector('.scroll-indicator');
                if (scrollIndicator) {
                    if (this.scrollTop > 50) {
                        scrollIndicator.style.opacity = '0.5';
                    } else {
                        scrollIndicator.style.opacity = '1';
                    }
                    
                    // Hide indicator when near bottom
                    const bottom = this.scrollHeight - this.clientHeight - 50;
                    if (this.scrollTop >= bottom) {
                        scrollIndicator.style.display = 'none';
                    } else {
                        scrollIndicator.style.display = 'block';
                    }
                }
            });
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateAllRelativeTimes();
            
            // Update times every minute
            setInterval(updateAllRelativeTimes, 60000);
            
            // Auto-mark announcements as read when they come into view
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const announcementItem = entry.target;
                        if (announcementItem.getAttribute('data-is-read') === '0') {
                            const announcementId = announcementItem.getAttribute('data-announcement-id');
                            markAsRead(announcementId);
                        }
                    }
                });
            }, { threshold: 0.5 });
            
            // Observe all unread announcements
            document.querySelectorAll('.announcement-item.unread').forEach(item => {
                observer.observe(item);
            });
            
            // Add scroll to top button for long lists
            if (<?php echo count($announcements) >= 10 ? 'true' : 'false'; ?>) {
                const scrollToTopBtn = document.createElement('button');
                scrollToTopBtn.innerHTML = '<i class="fas fa-chevron-up"></i>';
                scrollToTopBtn.style.position = 'fixed';
                scrollToTopBtn.style.bottom = '20px';
                scrollToTopBtn.style.right = '20px';
                scrollToTopBtn.style.zIndex = '1000';
                scrollToTopBtn.style.width = '40px';
                scrollToTopBtn.style.height = '40px';
                scrollToTopBtn.style.borderRadius = '50%';
                scrollToTopBtn.style.background = 'linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%)';
                scrollToTopBtn.style.color = 'white';
                scrollToTopBtn.style.border = 'none';
                scrollToTopBtn.style.cursor = 'pointer';
                scrollToTopBtn.style.display = 'none';
                scrollToTopBtn.style.justifyContent = 'center';
                scrollToTopBtn.style.alignItems = 'center';
                scrollToTopBtn.style.boxShadow = '0 2px 10px rgba(0,0,0,0.2)';
                scrollToTopBtn.style.transition = 'all 0.3s';
                
                scrollToTopBtn.addEventListener('click', () => {
                    announcementsList.scrollTo({ top: 0, behavior: 'smooth' });
                });
                
                document.body.appendChild(scrollToTopBtn);
                
                // Show/hide button based on scroll position
                announcementsList.addEventListener('scroll', () => {
                    if (announcementsList.scrollTop > 200) {
                        scrollToTopBtn.style.display = 'flex';
                    } else {
                        scrollToTopBtn.style.display = 'none';
                    }
                });
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + M to toggle sidebar
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sidebar.classList.toggle('active');
            }
            
            // Ctrl + F to focus on filter select
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const classSelect = document.getElementById('class_id');
                if (classSelect) {
                    classSelect.focus();
                }
            }
            
            // Ctrl + R to refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }
            
            // Escape to clear filter
            if (e.key === 'Escape' && <?php echo $filter_class_id > 0 ? 'true' : 'false'; ?>) {
                window.location.href = 'student_announcement.php';
            }
            
            // Space to mark first unread announcement as read
            if (e.key === ' ' && !e.target.matches('input, textarea, select')) {
                e.preventDefault();
                const firstUnread = document.querySelector('.announcement-item.unread');
                if (firstUnread) {
                    const announcementId = firstUnread.getAttribute('data-announcement-id');
                    markAsRead(announcementId);
                }
            }
            
            // Arrow keys to navigate announcements when list is scrollable
            if (<?php echo count($announcements) >= 10 ? 'true' : 'false'; ?> && announcementsList) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    announcementsList.scrollBy({ top: 100, behavior: 'smooth' });
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    announcementsList.scrollBy({ top: -100, behavior: 'smooth' });
                }
                if (e.key === 'Home') {
                    e.preventDefault();
                    announcementsList.scrollTo({ top: 0, behavior: 'smooth' });
                }
                if (e.key === 'End') {
                    e.preventDefault();
                    announcementsList.scrollTo({ top: announcementsList.scrollHeight, behavior: 'smooth' });
                }
            }
        });
        
        // Display keyboard shortcut hint
        console.log('Keyboard shortcuts available:\nCtrl+M: Toggle sidebar\nCtrl+F: Focus class filter\nCtrl+R: Refresh page\nEsc: Clear filter (if active)\nSpace: Mark first unread as read\nArrow keys: Navigate announcements (when list is scrollable)');
    </script>
</body>
</html>