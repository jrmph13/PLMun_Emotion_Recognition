<?php
// ==================== TEACHER ANNOUNCEMENT PAGE ====================
// Start session and load configuration
require_once 'config.php';

// Require instructor or admin role
requireInstructor();

// Get current user data
$userData = getUserData();
$teacher_id = $_SESSION['user_id'];

// Initialize variables
$success_message = '';
$error_message = '';
$announcements = [];
$teacher_classes = [];
$editing_id = null;
$edit_data = null;

// Handle edit request FIRST (before handling form submissions)
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editing_id = (int)$_GET['edit'];
    
    // Fetch announcement data for editing
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, c.class_name, c.class_code
            FROM " . TABLE_ANNOUNCEMENTS . " a
            JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
            WHERE a.id = ? AND a.teacher_id = ?
        ");
        $stmt->execute([$editing_id, $teacher_id]);
        $edit_data = $stmt->fetch();
        
        if (!$edit_data) {
            $error_message = "Announcement not found or you don't have permission to edit it.";
            $editing_id = null;
            $edit_data = null;
        }
    } catch (PDOException $e) {
        error_log("Announcement fetch error: " . $e->getMessage());
        $error_message = "Error loading announcement for editing.";
        $editing_id = null;
        $edit_data = null;
    }
}

// Handle cancel edit (should be before form submissions)
if (isset($_GET['cancel_edit'])) {
    $editing_id = null;
    $edit_data = null;
    // Clear any POST data
    $_POST = [];
}

// Handle form submission for new announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_announcement'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error_message = "Invalid security token. Please try again.";
    } else {
        // Sanitize inputs
        $class_id = isset($_POST['class_id']) ? (int)sanitizeInput($_POST['class_id']) : 0;
        $title = isset($_POST['title']) ? sanitizeInput($_POST['title']) : '';
        $message = isset($_POST['message']) ? sanitizeInput($_POST['message']) : '';
        
        // Validate inputs
        $errors = [];
        if ($class_id <= 0) {
            $errors[] = "Please select a class";
        }
        if (empty($title)) {
            $errors[] = "Announcement title is required";
        }
        if (empty($message)) {
            $errors[] = "Announcement message is required";
        }
        
        // Check if teacher owns the selected class
        if ($class_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM " . TABLE_CLASSES . " WHERE id = ? AND instructor_id = ?");
                $stmt->execute([$class_id, $teacher_id]);
                $class = $stmt->fetch();
                
                if (!$class) {
                    $errors[] = "You don't have permission to post announcements for this class";
                }
            } catch (PDOException $e) {
                error_log("Class validation error: " . $e->getMessage());
                $errors[] = "Error validating class selection";
            }
        }
        
        // If no errors, insert announcement
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO " . TABLE_ANNOUNCEMENTS . " 
                    (class_id, teacher_id, title, message, created_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([$class_id, $teacher_id, $title, $message]);
                $announcement_id = $pdo->lastInsertId();
                
                // Log the announcement creation
                logAuditTrail(
                    $teacher_id,
                    $_SESSION['role'],
                    $_SESSION['username'],
                    'create',
                    "Posted announcement: {$title}",
                    TABLE_ANNOUNCEMENTS,
                    $announcement_id,
                    ['class_id' => $class_id, 'title' => $title]
                );
                
                $success_message = "Announcement posted successfully!";
                
                // Clear form and editing state
                $_POST = [];
                $editing_id = null;
                $edit_data = null;
                
                // Redirect to clear POST data
                header("Location: teacher_announcement.php?success=posted");
                exit();
                
            } catch (PDOException $e) {
                error_log("Announcement creation error: " . $e->getMessage());
                $error_message = "Error posting announcement. Please try again.";
            }
        } else {
            $error_message = implode("<br>", $errors);
        }
    }
}

// Handle announcement update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_announcement'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error_message = "Invalid security token. Please try again.";
    } else {
        // Sanitize inputs
        $announcement_id = isset($_POST['announcement_id']) ? (int)sanitizeInput($_POST['announcement_id']) : 0;
        $class_id = isset($_POST['class_id']) ? (int)sanitizeInput($_POST['class_id']) : 0;
        $title = isset($_POST['title']) ? sanitizeInput($_POST['title']) : '';
        $message = isset($_POST['message']) ? sanitizeInput($_POST['message']) : '';
        
        // Validate inputs
        $errors = [];
        if ($announcement_id <= 0) {
            $errors[] = "Invalid announcement ID";
        }
        if ($class_id <= 0) {
            $errors[] = "Please select a class";
        }
        if (empty($title)) {
            $errors[] = "Announcement title is required";
        }
        if (empty($message)) {
            $errors[] = "Announcement message is required";
        }
        
        // Check if teacher owns the announcement
        if ($announcement_id > 0) {
            try {
                $stmt = $pdo->prepare("
                    SELECT a.id, c.id as class_id 
                    FROM " . TABLE_ANNOUNCEMENTS . " a
                    JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
                    WHERE a.id = ? AND a.teacher_id = ?
                ");
                $stmt->execute([$announcement_id, $teacher_id]);
                $announcement = $stmt->fetch();
                
                if (!$announcement) {
                    $errors[] = "You don't have permission to edit this announcement";
                }
            } catch (PDOException $e) {
                error_log("Announcement validation error: " . $e->getMessage());
                $errors[] = "Error validating announcement";
            }
        }
        
        // If no errors, update announcement
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE " . TABLE_ANNOUNCEMENTS . " 
                    SET class_id = ?, title = ?, message = ?, updated_at = NOW()
                    WHERE id = ? AND teacher_id = ?
                ");
                
                $stmt->execute([$class_id, $title, $message, $announcement_id, $teacher_id]);
                
                // Log the announcement update
                logAuditTrail(
                    $teacher_id,
                    $_SESSION['role'],
                    $_SESSION['username'],
                    'update',
                    "Updated announcement: {$title}",
                    TABLE_ANNOUNCEMENTS,
                    $announcement_id,
                    ['class_id' => $class_id, 'title' => $title]
                );
                
                $success_message = "Announcement updated successfully!";
                
                // Clear form and editing state
                $_POST = [];
                $editing_id = null;
                $edit_data = null;
                
                // Redirect to clear POST data and editing state
                header("Location: teacher_announcement.php?success=updated");
                exit();
                
            } catch (PDOException $e) {
                error_log("Announcement update error: " . $e->getMessage());
                $error_message = "Error updating announcement. Please try again.";
            }
        } else {
            $error_message = implode("<br>", $errors);
        }
    }
}

// Handle announcement deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $announcement_id = (int)$_GET['delete'];
    
    // Verify ownership before deletion
    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.title, c.class_name 
            FROM " . TABLE_ANNOUNCEMENTS . " a
            JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
            WHERE a.id = ? AND a.teacher_id = ?
        ");
        $stmt->execute([$announcement_id, $teacher_id]);
        $announcement = $stmt->fetch();
        
        if ($announcement) {
            $stmt = $pdo->prepare("DELETE FROM " . TABLE_ANNOUNCEMENTS . " WHERE id = ?");
            $stmt->execute([$announcement_id]);
            
            // Log the deletion
            logAuditTrail(
                $teacher_id,
                $_SESSION['role'],
                $_SESSION['username'],
                'delete',
                "Deleted announcement: {$announcement['title']}",
                TABLE_ANNOUNCEMENTS,
                $announcement_id,
                ['class_name' => $announcement['class_name']]
            );
            
            $success_message = "Announcement deleted successfully!";
        } else {
            $error_message = "Announcement not found or you don't have permission to delete it.";
        }
    } catch (PDOException $e) {
        error_log("Announcement deletion error: " . $e->getMessage());
        $error_message = "Error deleting announcement. Please try again.";
    }
    
    // Redirect to remove delete parameter from URL
    header("Location: teacher_announcement.php?success=deleted");
    exit();
}

// Check for success parameter from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'posted':
            $success_message = "Announcement posted successfully!";
            break;
        case 'updated':
            $success_message = "Announcement updated successfully!";
            break;
        case 'deleted':
            $success_message = "Announcement deleted successfully!";
            break;
    }
}

// Get teacher's classes
try {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               COUNT(DISTINCT ce.student_id) as student_count
        FROM " . TABLE_CLASSES . " c
        LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
        WHERE c.instructor_id = ? AND c.is_active = 1
        GROUP BY c.id
        ORDER BY c.class_name
    ");
    $stmt->execute([$teacher_id]);
    $teacher_classes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Teacher classes query error: " . $e->getMessage());
    $error_message = "Error loading classes. Please try again later.";
}

// Get teacher's announcements with class information
try {
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            c.class_name,
            c.class_code,
            u.full_name as teacher_name,
            (SELECT COUNT(DISTINCT ce.student_id) 
             FROM " . TABLE_CLASS_ENROLLMENTS . " ce 
             WHERE ce.class_id = a.class_id) as total_students
        FROM " . TABLE_ANNOUNCEMENTS . " a
        JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
        JOIN " . TABLE_USERS . " u ON a.teacher_id = u.id
        WHERE a.teacher_id = ?
        ORDER BY a.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$teacher_id]);
    $announcements = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Announcements query error: " . $e->getMessage());
    if (empty($error_message)) {
        $error_message = "Error loading announcements. Please try again later.";
    }
}

// Set page title
$page_title = "Class Announcements - Emotion AI System";

// Log page access for audit trail
logAuditTrail(
    $teacher_id,
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    'Accessed announcements page',
    null,
    null,
    ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']
);

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

// Calculate if we need scrollbar
$has_scrollbar = count($announcements) > 5;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ==================== MAIN STYLES (Matching Teacher Dashboard) ==================== */
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
        
        /* Content Wrapper */
        .content-wrapper {
            padding: 30px;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Button Styles */
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
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
        
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
        }
        
        /* ==================== ANNOUNCEMENT SPECIFIC STYLES ==================== */
        /* Two Column Layout */
        .announcement-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1024px) {
            .announcement-container {
                grid-template-columns: 1fr;
            }
        }
        
        /* Announcement Form Card */
        .form-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 100px;
        }
        
        .form-card h3 {
            color: #1f2937;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            font-weight: 700;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-card h3 i {
            color: #8b5cf6;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 20px;
            padding-right: 40px;
        }
        
        textarea.form-control {
            min-height: 150px;
            resize: vertical;
            line-height: 1.5;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }
        
        .form-actions .btn {
            flex: 1;
            justify-content: center;
        }
        
        /* Announcement List Card */
        .announcements-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
        }
        
        .announcements-card h3 {
            color: #1f2937;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            font-weight: 700;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .announcements-card h3 i {
            color: #8b5cf6;
        }
        
        .announcement-count {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            font-size: 14px;
            padding: 3px 10px;
            border-radius: 20px;
            margin-left: 10px;
        }
        
        /* Announcement List with Scrollbar */
        .announcement-list-container {
            position: relative;
        }
        
        .announcement-list {
            display: flex;
            flex-direction: column;
            gap: 15px; /* Reduced from 20px */
            max-height: <?php echo $has_scrollbar ? '600px' : 'auto'; ?>;
            <?php echo $has_scrollbar ? 'overflow-y: auto; padding-right: 10px;' : ''; ?>
        }
        
        /* Custom Scrollbar Styles */
        .announcement-list::-webkit-scrollbar {
            width: 8px;
        }
        
        .announcement-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .announcement-list::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 4px;
        }
        
        .announcement-list::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #1d4ed8 100%);
        }
        
        /* UPDATED: Smaller Announcement Item */
        .announcement-item {
            border: 1px solid #e5e7eb;
            border-radius: 10px; /* Slightly smaller radius */
            padding: 20px; /* Reduced from 25px */
            transition: all 0.3s;
            background: white;
            position: relative;
            flex-shrink: 0;
        }
        
        .announcement-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            border-color: #d1d5db;
        }
        
        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px; /* Reduced from 15px */
            gap: 15px;
        }
        
        /* UPDATED: Smaller title */
        .announcement-title {
            font-size: 16px; /* Reduced from 18px */
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 6px; /* Reduced from 8px */
            line-height: 1.4;
        }
        
        .announcement-class {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            color: #0369a1;
            padding: 4px 10px; /* Reduced from 6px 12px */
            border-radius: 16px; /* Slightly smaller */
            font-size: 12px; /* Reduced from 13px */
            font-weight: 600;
        }
        
        .announcement-class i {
            font-size: 11px; /* Reduced from 12px */
        }
        
        /* UPDATED: Smaller meta information */
        .announcement-meta {
            display: flex;
            align-items: center;
            gap: 12px; /* Reduced from 15px */
            font-size: 12px; /* Reduced from 13px */
            color: #6b7280;
            margin-bottom: 15px; /* Reduced from 20px */
            flex-wrap: wrap;
        }
        
        .announcement-date {
            display: flex;
            align-items: center;
            gap: 5px; /* Reduced from 6px */
            background: #f8fafc;
            padding: 3px 8px; /* Reduced from 4px 10px */
            border-radius: 5px; /* Reduced from 6px */
        }
        
        .announcement-students {
            display: flex;
            align-items: center;
            gap: 5px; /* Reduced from 6px */
            background: #f0f9ff;
            padding: 3px 8px; /* Reduced from 4px 10px */
            border-radius: 5px; /* Reduced from 6px */
            color: #0369a1;
        }
        
        /* UPDATED: Smaller message text */
        .announcement-message {
            color: #4b5563;
            line-height: 1.5; /* Reduced from 1.6 */
            margin-bottom: 15px; /* Reduced from 20px */
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 14px; /* Added smaller font size */
        }
        
        .announcement-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            padding-top: 12px; /* Reduced from 15px */
            border-top: 1px solid #f3f4f6;
        }
        
        /* Edit Mode Styles */
        .announcement-item.editing {
            border: 2px solid #8b5cf6;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { border-color: #8b5cf6; }
            50% { border-color: #3b82f6; }
            100% { border-color: #8b5cf6; }
        }
        
        .edit-mode-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 10px rgba(139, 92, 246, 0.3);
            z-index: 1;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px dashed #e5e7eb;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 20px;
        }
        
        .empty-state h4 {
            color: #6b7280;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .empty-state p {
            color: #9ca3af;
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto 20px;
        }
        
        /* Message Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border: 1px solid #10b981;
            color: #065f46;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 1px solid #ef4444;
            color: #7f1d1d;
        }
        
        .alert i {
            font-size: 20px;
        }
        
        /* Confirmation Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            padding: 20px;
        }
        
        .modal {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            width: 100%;
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .modal-header h3 {
            color: #1f2937;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-header i {
            color: #ef4444;
        }
        
        .modal-body {
            margin-bottom: 25px;
            color: #4b5563;
            line-height: 1.6;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        /* Scrollbar Indicator */
        .scrollbar-indicator {
            position: absolute;
            bottom: 10px;
            right: 20px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
            opacity: 0.8;
            transition: opacity 0.3s;
        }
        
        .scrollbar-indicator:hover {
            opacity: 1;
        }
        
        /* Responsive Styles */
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
            
            .form-actions {
                flex-direction: column;
            }
            
            .announcement-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .announcement-actions {
                flex-wrap: wrap;
            }
            
            .topbar-right {
                gap: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .announcement-container {
                gap: 20px;
            }
            
            .form-card,
            .announcements-card {
                padding: 20px;
            }
            
            .user-menu-btn span {
                display: none;
            }
            
            .user-menu-btn i.fa-chevron-down {
                display: none;
            }
            
            /* Even smaller announcement items on mobile */
            .announcement-item {
                padding: 15px;
            }
            
            .announcement-title {
                font-size: 15px;
            }
            
            .announcement-meta {
                font-size: 11px;
                gap: 8px;
            }
            
            .announcement-message {
                font-size: 13px;
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
            <a href="teacher_reports.php" class="menu-item">
                <i class="menu-icon fas fa-chart-bar"></i>
                <span>Reports</span>
            </a>

            <a href="teacher_announcement.php" class="menu-item active">
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
                <h2>Class Announcements</h2>
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
            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $success_message; ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error_message; ?></div>
                </div>
            <?php endif; ?>
            
            <!-- Two Column Layout -->
            <div class="announcement-container">
                <!-- Announcement Form -->
                <div class="form-card">
                    <h3>
                        <i class="fas fa-bullhorn"></i> 
                        <?php echo $editing_id ? 'Edit Announcement' : 'Post New Announcement'; ?>
                        <?php if ($editing_id): ?>
                            <span style="background: #8b5cf6; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px;">
                                Editing
                            </span>
                        <?php endif; ?>
                    </h3>
                    
                    <form method="POST" action="" id="announcementForm">
                        <?php echo csrfField(); ?>
                        <?php if ($editing_id): ?>
                            <input type="hidden" name="update_announcement" value="1">
                            <input type="hidden" name="announcement_id" value="<?php echo $editing_id; ?>">
                        <?php else: ?>
                            <input type="hidden" name="post_announcement" value="1">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label class="form-label" for="class_id">
                                <i class="fas fa-chalkboard"></i> Select Class
                            </label>
                            <select class="form-control form-select" id="class_id" name="class_id" required>
                                <option value="">-- Choose a Class --</option>
                                <?php if (empty($teacher_classes)): ?>
                                    <option value="" disabled>No classes available</option>
                                <?php else: ?>
                                    <?php foreach ($teacher_classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" 
                                            <?php if ($editing_id && $edit_data): ?>
                                                <?php echo $edit_data['class_id'] == $class['id'] ? 'selected' : ''; ?>
                                            <?php else: ?>
                                                <?php echo (isset($_POST['class_id']) && $_POST['class_id'] == $class['id']) ? 'selected' : ''; ?>
                                            <?php endif; ?>>
                                            <?php echo htmlspecialchars($class['class_name']); ?> 
                                            (<?php echo htmlspecialchars($class['class_code']); ?>)
                                            - <?php echo $class['student_count'] ?? 0; ?> students
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($teacher_classes)): ?>
                                <small style="color: #6b7280; display: block; margin-top: 8px;">
                                    <i class="fas fa-info-circle"></i> You need to create a class first before posting announcements.
                                </small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="title">
                                <i class="fas fa-heading"></i> Announcement Title
                            </label>
                            <input type="text" class="form-control" id="title" name="title" 
                                   placeholder="Enter announcement title" 
                                   value="<?php 
                                       if ($editing_id && $edit_data) {
                                           echo htmlspecialchars($edit_data['title']);
                                       } else {
                                           echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : '';
                                       }
                                   ?>" 
                                   required maxlength="150">
                            <small style="color: #6b7280; display: block; margin-top: 8px;">
                                <span id="charCount">0/150</span> characters
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="message">
                                <i class="fas fa-comment-alt"></i> Announcement Message
                            </label>
                            <textarea class="form-control" id="message" name="message" 
                                      placeholder="Write your announcement here..." 
                                      required><?php 
                                          if ($editing_id && $edit_data) {
                                              echo htmlspecialchars($edit_data['message']);
                                          } else {
                                              echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : '';
                                          }
                                      ?></textarea>
                            <small style="color: #6b7280; display: block; margin-top: 8px;">
                                <i class="fas fa-info-circle"></i> Students will see this message on their dashboard
                            </small>
                        </div>
                        
                        <div class="form-actions">
                            <?php if ($editing_id): ?>
                                <button type="button" class="btn btn-secondary" onclick="cancelEdit()">
                                    <i class="fas fa-times"></i>
                                    <span>Cancel</span>
                                </button>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-save"></i>
                                    <span>Update Announcement</span>
                                </button>
                            <?php else: ?>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i>
                                    <span>Clear</span>
                                </button>
                                <button type="submit" class="btn btn-success" 
                                    <?php echo empty($teacher_classes) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-paper-plane"></i>
                                    <span>Post Announcement</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Announcements List -->
                <div class="announcements-card">
                    <h3>
                        <i class="fas fa-history"></i> Recent Announcements
                        <?php if (!empty($announcements)): ?>
                            <span class="announcement-count"><?php echo count($announcements); ?></span>
                        <?php endif; ?>
                    </h3>
                    
                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <h4>No Announcements Yet</h4>
                            <p>You haven't posted any announcements yet. Create your first announcement to communicate with your students.</p>
                            <?php if (empty($teacher_classes)): ?>
                                <a href="create_class.php" class="btn btn-primary" style="margin-top: 15px;">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>Create Your First Class</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="announcement-list-container">
                            <div class="announcement-list" id="announcementList">
                                <?php $announcement_count = 0; ?>
                                <?php foreach ($announcements as $announcement): ?>
                                    <?php $announcement_count++; ?>
                                    <div class="announcement-item <?php echo $editing_id == $announcement['id'] ? 'editing' : ''; ?>" 
                                         id="announcement-<?php echo $announcement['id']; ?>">
                                        <?php if ($editing_id == $announcement['id']): ?>
                                            <div class="edit-mode-badge">
                                                <i class="fas fa-edit"></i> Editing
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="announcement-header">
                                            <div style="flex: 1;">
                                                <div class="announcement-title">
                                                    <?php echo htmlspecialchars($announcement['title']); ?>
                                                </div>
                                                <span class="announcement-class">
                                                    <i class="fas fa-chalkboard"></i>
                                                    <?php echo htmlspecialchars($announcement['class_name']); ?>
                                                    (<?php echo htmlspecialchars($announcement['class_code']); ?>)
                                                </span>
                                            </div>
                                            <div class="announcement-actions">
                                                <a href="?edit=<?php echo $announcement['id']; ?>" 
                                                   class="btn btn-warning btn-sm" 
                                                   title="Edit Announcement">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button class="btn btn-danger btn-sm delete-btn" 
                                                        data-id="<?php echo $announcement['id']; ?>"
                                                        data-title="<?php echo htmlspecialchars($announcement['title']); ?>"
                                                        title="Delete Announcement">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="announcement-meta">
                                            <span class="announcement-date">
                                                <i class="far fa-clock"></i>
                                                <?php echo formatDate($announcement['created_at'], 'F j, Y g:i A'); ?>
                                            </span>
                                            <span class="announcement-students">
                                                <i class="fas fa-user-graduate"></i>
                                                <?php echo $announcement['total_students']; ?> students
                                            </span>
                                            <span style="color: #6b7280;">
                                                <i class="fas fa-user-tie"></i>
                                                Posted by: <?php echo htmlspecialchars($announcement['teacher_name']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="announcement-message">
                                            <?php echo nl2br(htmlspecialchars($announcement['message'])); ?>
                                        </div>
                                        
                                        <?php if ($announcement['updated_at'] && $announcement['updated_at'] != $announcement['created_at']): ?>
                                            <div style="font-size: 12px; color: #6b7280; margin-top: 10px;">
                                                <i class="fas fa-history"></i> 
                                                Last updated: <?php echo formatDate($announcement['updated_at'], 'F j, Y g:i A'); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($has_scrollbar): ?>
                                <div class="scrollbar-indicator" id="scrollbarIndicator">
                                    <i class="fas fa-chevron-down"></i>
                                    <span>Scroll for more</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmationModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the announcement "<strong id="modalAnnouncementTitle"></strong>"?</p>
                <p style="color: #ef4444; font-size: 14px; margin-top: 10px;">
                    <i class="fas fa-exclamation-circle"></i> This action cannot be undone.
                </p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" id="cancelDelete">
                    <i class="fas fa-times"></i>
                    <span>Cancel</span>
                </button>
                <a href="#" class="btn btn-danger" id="confirmDelete">
                    <i class="fas fa-trash"></i>
                    <span>Delete Announcement</span>
                </a>
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
        
        // Announcement form validation
        const announcementForm = document.getElementById('announcementForm');
        const titleInput = document.getElementById('title');
        const messageInput = document.getElementById('message');
        const charCount = document.getElementById('charCount');
        
        // Initialize character counter
        if (titleInput && charCount) {
            // Update character count on page load
            updateCharCount();
            
            // Update character count on input
            titleInput.addEventListener('input', updateCharCount);
            
            function updateCharCount() {
                const count = titleInput.value.length;
                const max = 150;
                charCount.textContent = `${count}/${max}`;
                
                // Change color based on character count
                if (count > max) {
                    charCount.style.color = '#ef4444';
                    charCount.innerHTML = `${count}/${max} <i class="fas fa-exclamation-circle"></i> (Exceeded limit)`;
                } else if (count > max * 0.8) {
                    charCount.style.color = '#f59e0b';
                    charCount.innerHTML = `${count}/${max} <i class="fas fa-exclamation-triangle"></i> (Approaching limit)`;
                } else {
                    charCount.style.color = '#6b7280';
                }
            }
        }
        
        if (announcementForm) {
            announcementForm.addEventListener('submit', function(e) {
                const title = titleInput.value.trim();
                const message = messageInput.value.trim();
                
                if (title.length > 150) {
                    e.preventDefault();
                    alert('Announcement title must be 150 characters or less.');
                    titleInput.focus();
                    return;
                }
                
                if (title.length === 0) {
                    e.preventDefault();
                    alert('Please enter an announcement title.');
                    titleInput.focus();
                    return;
                }
                
                if (message.length === 0) {
                    e.preventDefault();
                    alert('Please enter an announcement message.');
                    messageInput.focus();
                    return;
                }
            });
        }
        
        // Delete confirmation modal
        const deleteButtons = document.querySelectorAll('.delete-btn');
        const confirmationModal = document.getElementById('confirmationModal');
        const modalAnnouncementTitle = document.getElementById('modalAnnouncementTitle');
        const confirmDelete = document.getElementById('confirmDelete');
        const cancelDelete = document.getElementById('cancelDelete');
        
        deleteButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                
                const announcementId = this.getAttribute('data-id');
                const announcementTitle = this.getAttribute('data-title');
                
                modalAnnouncementTitle.textContent = announcementTitle;
                confirmDelete.href = `?delete=${announcementId}`;
                
                confirmationModal.style.display = 'flex';
            });
        });
        
        // Close modal functions
        cancelDelete.addEventListener('click', function(e) {
            e.preventDefault();
            confirmationModal.style.display = 'none';
        });
        
        // Close modal when clicking outside
        confirmationModal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && confirmationModal.style.display === 'flex') {
                confirmationModal.style.display = 'none';
            }
        });
        
        // Auto-dismiss success messages after 5 seconds
        const successAlert = document.querySelector('.alert-success');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.opacity = '0';
                successAlert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => {
                    successAlert.remove();
                }, 500);
            }, 5000);
        }
        
        // Form reset confirmation
        const clearButton = announcementForm?.querySelector('button[type="reset"]');
        if (clearButton) {
            clearButton.addEventListener('click', function(e) {
                if (titleInput.value.trim() || messageInput.value.trim()) {
                    if (!confirm('Are you sure you want to clear the form? All entered data will be lost.')) {
                        e.preventDefault();
                    }
                }
            });
        }
        
        // Auto-focus title input if it's empty
        if (titleInput && !titleInput.value.trim()) {
            setTimeout(() => {
                titleInput.focus();
            }, 300);
        }
        
        // Scrollbar indicator visibility
        const announcementList = document.getElementById('announcementList');
        const scrollbarIndicator = document.getElementById('scrollbarIndicator');
        
        if (announcementList && scrollbarIndicator) {
            announcementList.addEventListener('scroll', function() {
                const scrollPercentage = (this.scrollTop / (this.scrollHeight - this.clientHeight)) * 100;
                
                if (scrollPercentage > 20) {
                    scrollbarIndicator.style.opacity = '0.3';
                } else {
                    scrollbarIndicator.style.opacity = '0.8';
                }
                
                // Hide indicator if at bottom
                if (scrollPercentage > 90) {
                    scrollbarIndicator.style.opacity = '0';
                }
            });
            
            // Hide indicator after 5 seconds if not scrolling
            let scrollTimeout;
            announcementList.addEventListener('scroll', function() {
                scrollbarIndicator.style.opacity = '0.8';
                
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => {
                    scrollbarIndicator.style.opacity = '0.3';
                }, 3000);
            });
            
            // Show indicator on hover
            scrollbarIndicator.addEventListener('mouseenter', function() {
                this.style.opacity = '1';
            });
            
            scrollbarIndicator.addEventListener('mouseleave', function() {
                this.style.opacity = '0.3';
            });
            
            // Scroll to bottom when clicking indicator
            scrollbarIndicator.addEventListener('click', function() {
                announcementList.scrollTo({
                    top: announcementList.scrollHeight,
                    behavior: 'smooth'
                });
            });
        }
        
        // Cancel edit function
        function cancelEdit() {
            window.location.href = '?cancel_edit=1';
        }
        
        // Scroll to editing announcement if in edit mode
        <?php if ($editing_id && $edit_data): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const editingAnnouncement = document.getElementById('announcement-<?php echo $editing_id; ?>');
            if (editingAnnouncement) {
                // Smooth scroll to the editing announcement
                editingAnnouncement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // Add highlight effect
                editingAnnouncement.style.boxShadow = '0 0 0 4px rgba(139, 92, 246, 0.3)';
                setTimeout(() => {
                    editingAnnouncement.style.boxShadow = '';
                }, 2000);
            }
        });
        <?php endif; ?>
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + Enter to submit form
            if (e.ctrlKey && e.key === 'Enter' && announcementForm) {
                if (document.activeElement === messageInput) {
                    e.preventDefault();
                    announcementForm.querySelector('button[type="submit"]').click();
                }
            }
            
            // Ctrl + N to focus new announcement form
            if (e.ctrlKey && e.key === 'n' && !<?php echo $editing_id ? 'true' : 'false'; ?>) {
                e.preventDefault();
                if (titleInput) {
                    titleInput.focus();
                }
            }
            
            // Ctrl + M to toggle sidebar
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sidebar.classList.toggle('active');
            }
            
            // Ctrl + U to toggle user menu
            if (e.ctrlKey && e.key === 'u') {
                e.preventDefault();
                userMenuDropdown.classList.toggle('show');
            }
            
            // Escape to cancel edit
            if (e.key === 'Escape' && <?php echo $editing_id ? 'true' : 'false'; ?>) {
                e.preventDefault();
                cancelEdit();
            }
        });
        
        // Display keyboard shortcut hint
        console.log('Keyboard shortcuts available:\nCtrl+N: Focus new announcement\nCtrl+Enter: Submit form (when in message field)\nCtrl+M: Toggle sidebar\nCtrl+U: Toggle user menu\nEscape: Cancel edit');
        
        // Clear form after successful submission
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we have a success message but are not editing
            const successAlert = document.querySelector('.alert-success');
            const isEditing = <?php echo $editing_id ? 'true' : 'false'; ?>;
            
            if (successAlert && !isEditing) {
                // Clear form fields after successful submission
                setTimeout(() => {
                    if (titleInput) titleInput.value = '';
                    if (messageInput) messageInput.value = '';
                    if (charCount) charCount.textContent = '0/150';
                    
                    // Reset form to "Post New Announcement" mode
                    const hiddenFields = announcementForm.querySelectorAll('input[type="hidden"]');
                    hiddenFields.forEach(field => {
                        if (field.name === 'update_announcement') {
                            field.remove();
                        }
                    });
                    
                    // Change submit button back to "Post Announcement"
                    const submitBtn = announcementForm.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.className = 'btn btn-success';
                        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i><span>Post Announcement</span>';
                    }
                    
                    // Change form title
                    const formTitle = document.querySelector('.form-card h3');
                    if (formTitle) {
                        formTitle.innerHTML = '<i class="fas fa-bullhorn"></i> Post New Announcement';
                    }
                }, 100);
            }
        });
    </script>
</body>
</html>