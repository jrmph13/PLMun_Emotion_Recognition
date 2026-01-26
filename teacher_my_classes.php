<?php
// ==================== TEACHER MY CLASSES - FULLY FUNCTIONAL ====================
// Start session and load configuration
require_once 'config.php';

// Require instructor or admin role
requireInstructor();

// Get current user data
$userData = getUserData();
$userId = $_SESSION['user_id'];

// Initialize variables
$error_message = '';
$success_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error_message = "Invalid CSRF token. Please try again.";
    } else {
        // ==================== CREATE CLASS ====================
        if (isset($_POST['create_class'])) {
            $class_name = sanitizeInput($_POST['class_name'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $schedule = $_POST['schedule'] ?? '';
            $max_students = intval($_POST['max_students'] ?? 30);
            $emotion_tracking = isset($_POST['emotion_tracking']) ? 1 : 0;
            $auto_attendance = isset($_POST['auto_attendance']) ? 1 : 0;
            
            // Validate inputs
            $errors = [];
            if (empty($class_name)) {
                $errors[] = "Class name is required";
            }
            if (empty($schedule)) {
                $errors[] = "Class schedule is required";
            }
            if ($max_students < 1 || $max_students > 100) {
                $errors[] = "Maximum students must be between 1 and 100";
            }
            
            if (empty($errors)) {
                try {
                    // Generate unique class code
                    $class_code = generateClassCode();
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO " . TABLE_CLASSES . " 
                        (instructor_id, class_name, class_code, description, schedule, max_students, emotion_tracking, auto_attendance, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    
                    $stmt->execute([
                        $userId,
                        $class_name,
                        $class_code,
                        $description,
                        $schedule,
                        $max_students,
                        $emotion_tracking,
                        $auto_attendance
                    ]);
                    
                    $class_id = $pdo->lastInsertId();
                    
                    // Log the action
                    logAuditTrail(
                        $userId,
                        $_SESSION['role'],
                        $_SESSION['username'],
                        'create',
                        "Created new class: {$class_name}",
                        'classes',
                        $class_id,
                        [
                            'class_code' => $class_code,
                            'class_name' => $class_name
                        ]
                    );
                    
                    $success_message = "Class created successfully! Class Code: <strong>{$class_code}</strong>";
                    
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        // Duplicate class code (extremely rare but handle it)
                        $error_message = "Class code already exists. Please try again.";
                    } else {
                        error_log("Create class error: " . $e->getMessage());
                        $error_message = "An error occurred while creating the class. Please try again.";
                    }
                }
            } else {
                $error_message = implode("<br>", $errors);
            }
        }
        
        // ==================== UPDATE CLASS ====================
        elseif (isset($_POST['update_class'])) {
            $class_id = intval($_POST['class_id'] ?? 0);
            $class_name = sanitizeInput($_POST['class_name'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $schedule = $_POST['schedule'] ?? '';
            $max_students = intval($_POST['max_students'] ?? 30);
            $emotion_tracking = isset($_POST['emotion_tracking']) ? 1 : 0;
            $auto_attendance = isset($_POST['auto_attendance']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Validate ownership
            $stmt = $pdo->prepare("SELECT * FROM " . TABLE_CLASSES . " WHERE id = ? AND instructor_id = ?");
            $stmt->execute([$class_id, $userId]);
            $existing_class = $stmt->fetch();
            
            if (!$existing_class) {
                $error_message = "Class not found or you don't have permission to edit it.";
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE " . TABLE_CLASSES . " SET
                        class_name = ?,
                        description = ?,
                        schedule = ?,
                        max_students = ?,
                        emotion_tracking = ?,
                        auto_attendance = ?,
                        is_active = ?
                        WHERE id = ? AND instructor_id = ?
                    ");
                    
                    $stmt->execute([
                        $class_name,
                        $description,
                        $schedule,
                        $max_students,
                        $emotion_tracking,
                        $auto_attendance,
                        $is_active,
                        $class_id,
                        $userId
                    ]);
                    
                    // Log the action
                    logAuditTrail(
                        $userId,
                        $_SESSION['role'],
                        $_SESSION['username'],
                        'update',
                        "Updated class: {$class_name}",
                        'classes',
                        $class_id,
                        [
                            'old_class_name' => $existing_class['class_name'],
                            'new_class_name' => $class_name
                        ]
                    );
                    
                    $success_message = "Class updated successfully!";
                    
                } catch (PDOException $e) {
                    error_log("Update class error: " . $e->getMessage());
                    $error_message = "An error occurred while updating the class. Please try again.";
                }
            }
        }
        
        // ==================== DELETE CLASS (PERMANENT) ====================
        elseif (isset($_POST['delete_class'])) {
            $class_id = intval($_POST['class_id'] ?? 0);
            
            // Validate ownership
            $stmt = $pdo->prepare("SELECT * FROM " . TABLE_CLASSES . " WHERE id = ? AND instructor_id = ?");
            $stmt->execute([$class_id, $userId]);
            $existing_class = $stmt->fetch();
            
            if (!$existing_class) {
                $error_message = "Class not found or you don't have permission to delete it.";
            } else {
                try {
                    // PERMANENT DELETE instead of soft delete
                    $stmt = $pdo->prepare("
                        DELETE FROM " . TABLE_CLASSES . " 
                        WHERE id = ? AND instructor_id = ?
                    ");
                    
                    $stmt->execute([$class_id, $userId]);
                    
                    // Log the action
                    logAuditTrail(
                        $userId,
                        $_SESSION['role'],
                        $_SESSION['username'],
                        'delete',
                        "Permanently deleted class: {$existing_class['class_name']}",
                        'classes',
                        $class_id,
                        [
                            'class_code' => $existing_class['class_code'],
                            'class_name' => $existing_class['class_name']
                        ]
                    );
                    
                    $success_message = "Class permanently deleted successfully!";
                    
                    // Refresh the page to show updated list
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                    
                } catch (PDOException $e) {
                    error_log("Delete class error: " . $e->getMessage());
                    $error_message = "An error occurred while deleting the class. Please try again.";
                }
            }
        }
        
        // ==================== REGENERATE CLASS CODE ====================
        elseif (isset($_POST['regenerate_code'])) {
            $class_id = intval($_POST['class_id'] ?? 0);
            
            // Validate ownership
            $stmt = $pdo->prepare("SELECT * FROM " . TABLE_CLASSES . " WHERE id = ? AND instructor_id = ?");
            $stmt->execute([$class_id, $userId]);
            $existing_class = $stmt->fetch();
            
            if (!$existing_class) {
                $error_message = "Class not found or you don't have permission to modify it.";
            } else {
                try {
                    $new_class_code = generateClassCode();
                    
                    $stmt = $pdo->prepare("
                        UPDATE " . TABLE_CLASSES . " 
                        SET class_code = ? 
                        WHERE id = ? AND instructor_id = ?
                    ");
                    
                    $stmt->execute([$new_class_code, $class_id, $userId]);
                    
                    // Log the action
                    logAuditTrail(
                        $userId,
                        $_SESSION['role'],
                        $_SESSION['username'],
                        'update',
                        "Regenerated class code for: {$existing_class['class_name']}",
                        'classes',
                        $class_id,
                        [
                            'old_class_code' => $existing_class['class_code'],
                            'new_class_code' => $new_class_code,
                            'class_name' => $existing_class['class_name']
                        ]
                    );
                    
                    $success_message = "Class code regenerated successfully! New Code: <strong>{$new_class_code}</strong>";
                    
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        // Duplicate class code (extremely rare but handle it)
                        $error_message = "Generated code already exists. Please try again.";
                    } else {
                        error_log("Regenerate code error: " . $e->getMessage());
                        $error_message = "An error occurred while regenerating the class code. Please try again.";
                    }
                }
            }
        }
    }
}

// Get teacher's classes with student count
try {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               COUNT(DISTINCT ce.student_id) as student_count,
               COUNT(DISTINCT ls.id) as session_count,
               u.full_name as instructor_name
        FROM " . TABLE_CLASSES . " c
        LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
        LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id
        JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
        WHERE c.instructor_id = ? 
        GROUP BY c.id
        ORDER BY c.is_active DESC, c.created_at DESC
    ");
    $stmt->execute([$userId]);
    $teacher_classes = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Get classes error: " . $e->getMessage());
    $error_message = "An error occurred while loading classes. Please try again.";
    $teacher_classes = [];
}

// Helper function to generate unique class code
function generateClassCode() {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Helper function for initials
function getInitials($name) {
    if (empty($name)) return 'IN';
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    return substr($initials, 0, 2);
}

// Check if we need scrollbars (5 or more items)
$has_many_classes = count($teacher_classes) >= 5;
$table_scroll_class = $has_many_classes ? 'scrollable-table' : '';

// Set page title
$page_title = "My Classes - Emotion AI System";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ==================== REUSE TEACHER DASHBOARD STYLES ==================== */
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
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
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
        }
        
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .topbar-left h2 {
            color: #1f2937;
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        /* User Menu Styles */
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
            color: #374151;
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
            margin-top: 5px;
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
        
        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 30px;
            background: white;
            border-radius: 12px 12px 0 0;
            overflow: hidden;
        }
        
        .tab-btn {
            padding: 18px 30px;
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }
        
        .tab-btn:hover {
            color: #8b5cf6;
            background: #f8fafc;
        }
        
        .tab-btn.active {
            color: #8b5cf6;
        }
        
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Form Styles */
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-weight: 500;
            color: #4b5563;
        }
        
        .checkbox-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #8b5cf6;
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

        /* Button Styles */
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 16px;
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
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(239, 68, 68, 0.4);
        }
        
        .btn-outline {
            background: white;
            color: #4b5563;
            border: 2px solid #e5e7eb;
        }
        
        .btn-outline:hover {
            border-color: #8b5cf6;
            color: #8b5cf6;
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 14px;
        }
        
        /* NEW: Scrollable Table Container */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: white;
            position: relative;
        }
        
        /* Scrollable Table (when 5+ items) */
        .table-container.scrollable-table {
            max-height: 500px; /* Fixed height for scrolling */
            overflow-y: auto; /* Vertical scrollbar */
        }
        
        /* Style the scrollbar */
        .table-container.scrollable-table::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .table-container.scrollable-table::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .table-container.scrollable-table::-webkit-scrollbar-thumb {
            background: #8b5cf6;
            border-radius: 4px;
        }
        
        .table-container.scrollable-table::-webkit-scrollbar-thumb:hover {
            background: #7c3aed;
        }
        
        /* NEW: Scrollable Table Header (sticky header when scrolling) */
        .table-container.scrollable-table .data-table thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f8fafc;
            box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
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
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .class-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 16px;
            color: #8b5cf6;
            background: #f5f3ff;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px dashed #8b5cf6;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 25px 30px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            color: #1f2937;
            font-size: 20px;
            font-weight: 700;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #6b7280;
            cursor: pointer;
            padding: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover {
            background: #f3f4f6;
            color: #ef4444;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: fadeIn 0.5s ease;
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
        
        .alert-icon {
            font-size: 24px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 72px;
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
            max-width: 400px;
            margin: 0 auto 20px;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        /* NEW: Item Count Badge */
        .item-count-badge {
            position: absolute;
            top: -12px;
            right: -12px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            box-shadow: 0 2px 10px rgba(139, 92, 246, 0.3);
            z-index: 5;
        }
        
        /* NEW: Scroll Indicator */
        .scroll-indicator {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            display: none;
            z-index: 5;
        }
        
        .table-container.scrollable-table .scroll-indicator {
            display: block;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
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
        }
        
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab-btn {
                padding: 15px;
                text-align: left;
            }
            
            /* Adjust scrollable height for mobile */
            .table-container.scrollable-table {
                max-height: 400px;
            }
            
            /* Adjust user menu for mobile */
            .user-menu-btn span {
                display: none;
            }
            
            .user-menu-btn {
                padding: 8px;
            }
        }
        
        .menu-toggle {
            background: none;
            border: none;
            font-size: 20px;
            color: #4b5563;
            cursor: pointer;
            display: none;
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
            <a href="teacher_dashboard.php" class="menu-item ">
                <i class="menu-icon fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="menu-title">Teaching</div>
            <a href="teacher_my_classes.php" class="menu-item active">
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
                <h2>My Classes</h2>
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
            <!-- Alert Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle alert-icon"></i>
                    <div><?php echo $success_message; ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle alert-icon"></i>
                    <div><?php echo $error_message; ?></div>
                </div>
            <?php endif; ?>
            
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" data-tab="manage-classes">Manage Classes</button>
                <button class="tab-btn" data-tab="create-class">Create New Class</button>
                <button class="tab-btn" data-tab="class-codes">Class Codes</button>
            </div>
            
            <!-- Tab Contents -->
            
            <!-- Manage Classes Tab -->
            <div class="tab-content active" id="manage-classes">
                <div class="table-container <?php echo $table_scroll_class; ?>">
                    <?php if (empty($teacher_classes)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <h3>No Classes Created Yet</h3>
                            <p>Create your first class to get started with teaching</p>
                            <button class="btn btn-primary" onclick="switchTab('create-class')">
                                <i class="fas fa-plus"></i> Create First Class
                            </button>
                        </div>
                    <?php else: ?>
                        <!-- Item Count Badge -->
                        <?php if ($has_many_classes): ?>
                            <div class="item-count-badge">
                                <i class="fas fa-list"></i> <?php echo count($teacher_classes); ?> Classes
                            </div>
                            <div class="scroll-indicator">
                                <i class="fas fa-arrow-down"></i> Scroll to view more
                            </div>
                        <?php endif; ?>
                        
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Class Name</th>
                                    <th>Class Code</th>
                                    <th>Schedule</th>
                                    <th>Students</th>
                                    <th>Sessions</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teacher_classes as $class): ?>
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
                                                        <?php echo htmlspecialchars($class['description'] ?? 'No description'); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($class['schedule']): ?>
                                                <div style="font-weight: 500; color: #1f2937;">
                                                    <?php echo date('D, M j', strtotime($class['schedule'])); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    <?php echo date('h:i A', strtotime($class['schedule'])); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-style: italic;">Not scheduled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f0f9ff; border-radius: 20px; font-weight: 600; font-size: 14px;">
                                                <i class="fas fa-user-graduate"></i>
                                                <?php echo $class['student_count'] ?? 0; ?> / <?php echo $class['max_students'] ?? 30; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f8fafc; border-radius: 20px; font-weight: 600; font-size: 14px;">
                                                <i class="fas fa-chart-bar"></i>
                                                <?php echo $class['session_count'] ?? 0; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($class['is_active']): ?>
                                                <span class="status-badge status-active">
                                                    <i class="fas fa-check-circle"></i> Active
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-inactive">
                                                    <i class="fas fa-ban"></i> Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-primary" onclick="editClass(<?php echo $class['id']; ?>, '<?php echo addslashes($class['class_name']); ?>', '<?php echo addslashes($class['description'] ?? ''); ?>', '<?php echo $class['schedule']; ?>', <?php echo $class['max_students']; ?>, <?php echo $class['emotion_tracking']; ?>, <?php echo $class['auto_attendance']; ?>, <?php echo $class['is_active']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $class['id']; ?>, '<?php echo addslashes($class['class_name']); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Create Class Tab -->
            <div class="tab-content" id="create-class">
                <div class="form-container">
                    <h3 style="margin-bottom: 25px; color: #1f2937;">Create New Class</h3>
                    <form method="POST" action="" id="createClassForm">
                        <?php echo csrfField(); ?>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="class_name">Class Name *</label>
                                <input type="text" id="class_name" name="class_name" class="form-control" required 
                                       placeholder="e.g., Mathematics 101">
                            </div>
                            
                            <div class="form-group">
                                <label for="schedule">Class Schedule *</label>
                                <input type="datetime-local" id="schedule" name="schedule" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3" 
                                      placeholder="Enter class description..."></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="max_students">Maximum Students</label>
                                <input type="number" id="max_students" name="max_students" class="form-control" 
                                       min="1" max="100" value="30">
                            </div>
                            
                            <div class="form-group">
                                <label>Class Settings</label>
                                <div class="checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="emotion_tracking" value="1" checked>
                                        <span>Enable Emotion Tracking</span>
                                    </label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="auto_attendance" value="1" checked>
                                        <span>Enable Auto Attendance</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" name="create_class" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> Create Class
                            </button>
                            <button type="button" class="btn btn-outline" onclick="switchTab('manage-classes')">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Class Codes Tab -->
            <div class="tab-content" id="class-codes">
                <div class="form-container">
                    <h3 style="margin-bottom: 25px; color: #1f2937;">Class Codes Management</h3>
                    <p style="margin-bottom: 25px; color: #6b7280;">
                        Class codes are unique codes that students use to enroll in your classes. 
                        You can regenerate a class code at any time.
                    </p>
                    
                    <?php if (empty($teacher_classes)): ?>
                        <div class="empty-state" style="padding: 30px;">
                            <i class="fas fa-key"></i>
                            <h3>No Classes Available</h3>
                            <p>Create a class first to generate class codes</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container <?php echo $table_scroll_class; ?>">
                            <!-- Item Count Badge -->
                            <?php if ($has_many_classes): ?>
                                <div class="item-count-badge">
                                    <i class="fas fa-key"></i> <?php echo count($teacher_classes); ?> Class Codes
                                </div>
                                <div class="scroll-indicator">
                                    <i class="fas fa-arrow-down"></i> Scroll to view more
                                </div>
                            <?php endif; ?>
                            
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Class Name</th>
                                        <th>Current Class Code</th>
                                        <th>Enrolled Students</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($teacher_classes as $class): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600; color: #1f2937;">
                                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    <?php echo htmlspecialchars($class['description'] ?? 'No description'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-family: 'Courier New', monospace; font-weight: bold; font-size: 20px; color: #8b5cf6; letter-spacing: 2px;">
                                                    <?php echo htmlspecialchars($class['class_code']); ?>
                                                </div>
                                                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                                    Students use this code to enroll
                                                </div>
                                            </td>
                                            <td>
                                                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f0f9ff; border-radius: 20px; font-weight: 600; font-size: 14px;">
                                                    <i class="fas fa-user-graduate"></i>
                                                    <?php echo $class['student_count'] ?? 0; ?> enrolled
                                                </span>
                                            </td>
                                            <td>
                                                <form method="POST" action="" style="display: inline;">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                                                    <button type="submit" name="regenerate_code" class="btn btn-sm btn-primary" 
                                                            onclick="return confirm('Are you sure? This will invalidate the old class code.');">
                                                        <i class="fas fa-sync-alt"></i> Regenerate Code
                                                    </button>
                                                </form>
                                                <button class="btn btn-sm btn-outline" onclick="copyToClipboard('<?php echo $class['class_code']; ?>')">
                                                    <i class="fas fa-copy"></i> Copy Code
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Class Modal -->
    <div class="modal" id="editClassModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="editModalTitle">Edit Class</h3>
                <button class="modal-close" onclick="closeModal('editClassModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="" id="editClassForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="class_id" id="edit_class_id">
                <input type="hidden" name="update_class" value="1">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_class_name">Class Name *</label>
                        <input type="text" id="edit_class_name" name="class_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_description">Description</label>
                        <textarea id="edit_description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_schedule">Class Schedule *</label>
                        <input type="datetime-local" id="edit_schedule" name="schedule" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_max_students">Maximum Students</label>
                        <input type="number" id="edit_max_students" name="max_students" class="form-control" min="1" max="100">
                    </div>
                    
                    <div class="form-group">
                        <label>Class Settings</label>
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="edit_emotion_tracking" name="emotion_tracking" value="1">
                                <span>Enable Emotion Tracking</span>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" id="edit_auto_attendance" name="auto_attendance" value="1">
                                <span>Enable Auto Attendance</span>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" id="edit_is_active" name="is_active" value="1">
                                <span>Active Class</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editClassModal')">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="" id="deleteForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="class_id" id="delete_class_id">
                <input type="hidden" name="delete_class" value="1">
                
                <div class="modal-body">
                    <div style="text-align: center; padding: 20px;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #ef4444; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 10px; color: #1f2937;">Delete Class</h3>
                        <p style="color: #6b7280; margin-bottom: 20px;">
                            Are you sure you want to delete <strong id="delete_class_name"></strong>?
                            This action cannot be undone.
                        </p>
                        <div style="background: #fef3c7; padding: 15px; border-radius: 8px; text-align: left; margin-bottom: 20px;">
                            <i class="fas fa-info-circle" style="color: #d97706;"></i>
                            <span style="color: #92400e; font-size: 14px;">
                                Note: This will permanently delete the class and all its data.
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('deleteModal')">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Class
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(button => {
            button.addEventListener('click', () => {
                const tabId = button.getAttribute('data-tab');
                switchTab(tabId);
            });
        });
        
        function switchTab(tabId) {
            // Update active tab button
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-tab="${tabId}"]`).classList.add('active');
            
            // Update active tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabId).classList.add('active');
            
            // Scroll to top of tab content
            document.getElementById(tabId).scrollIntoView({ behavior: 'smooth' });
        }
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        // Edit class function
        function editClass(classId, className, description, schedule, maxStudents, emotionTracking, autoAttendance, isActive) {
            // Update modal title
            document.getElementById('editModalTitle').textContent = 'Edit Class: ' + className;
            
            // Set form values
            document.getElementById('edit_class_id').value = classId;
            document.getElementById('edit_class_name').value = className;
            document.getElementById('edit_description').value = description || '';
            
            // Format date for datetime-local input
            if (schedule) {
                const date = new Date(schedule);
                const formattedDate = date.toISOString().slice(0, 16);
                document.getElementById('edit_schedule').value = formattedDate;
            } else {
                document.getElementById('edit_schedule').value = '';
            }
            
            document.getElementById('edit_max_students').value = maxStudents;
            document.getElementById('edit_emotion_tracking').checked = emotionTracking == 1;
            document.getElementById('edit_auto_attendance').checked = autoAttendance == 1;
            document.getElementById('edit_is_active').checked = isActive == 1;
            
            // Open modal
            openModal('editClassModal');
        }
        
        // Delete confirmation
        function confirmDelete(classId, className) {
            document.getElementById('delete_class_id').value = classId;
            document.getElementById('delete_class_name').textContent = className;
            openModal('deleteModal');
        }
        
        // Copy to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                // Show a nice notification instead of alert
                showNotification('Class code copied to clipboard: ' + text, 'success');
            }).catch(err => {
                console.error('Failed to copy: ', err);
                showNotification('Failed to copy class code', 'error');
            });
        }
        
        // Notification function
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type === 'success' ? 'success' : 'error'}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} alert-icon"></i>
                <div>${message}</div>
            `;
            
            document.querySelector('.content-wrapper').insertBefore(notification, document.querySelector('.content-wrapper').firstChild);
            
            setTimeout(() => {
                notification.style.transition = 'opacity 0.5s ease';
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 500);
            }, 3000);
        }
        
        // Sidebar toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
            });
        }
        
        // User menu dropdown functionality
        const userMenuBtn = document.getElementById('userMenuBtn');
        const userMenuDropdown = document.getElementById('userMenuDropdown');
        
        if (userMenuBtn) {
            userMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                userMenuDropdown.classList.toggle('show');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', () => {
                userMenuDropdown.classList.remove('show');
            });
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
        
        // Set default schedule to tomorrow for create form
        document.addEventListener('DOMContentLoaded', function() {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(9, 0, 0, 0);
            
            const scheduleInput = document.getElementById('schedule');
            if (scheduleInput) {
                const formattedDate = tomorrow.toISOString().slice(0, 16);
                scheduleInput.value = formattedDate;
                scheduleInput.min = new Date().toISOString().slice(0, 16);
            }
            
            // Auto-hide success messages after 5 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
            
            // Add smooth scrolling to tables with many items
            const scrollableTables = document.querySelectorAll('.table-container.scrollable-table');
            scrollableTables.forEach(table => {
                table.addEventListener('scroll', function() {
                    const indicator = this.querySelector('.scroll-indicator');
                    if (indicator) {
                        if (this.scrollTop > 10) {
                            indicator.innerHTML = '<i class="fas fa-arrow-up"></i> Scroll to top';
                            indicator.onclick = () => this.scrollTo({ top: 0, behavior: 'smooth' });
                        } else {
                            indicator.innerHTML = '<i class="fas fa-arrow-down"></i> Scroll to view more';
                            indicator.onclick = null;
                        }
                    }
                });
            });
        });
        
        // Form validation for edit modal
        document.getElementById('editClassForm').addEventListener('submit', function(e) {
            const className = document.getElementById('edit_class_name').value.trim();
            const schedule = document.getElementById('edit_schedule').value;
            const maxStudents = parseInt(document.getElementById('edit_max_students').value);
            
            if (!className) {
                e.preventDefault();
                showNotification('Class name is required', 'error');
                return;
            }
            
            if (!schedule) {
                e.preventDefault();
                showNotification('Class schedule is required', 'error');
                return;
            }
            
            if (maxStudents < 1 || maxStudents > 100) {
                e.preventDefault();
                showNotification('Maximum students must be between 1 and 100', 'error');
                return;
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + N for new class
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                switchTab('create-class');
            }
            // Ctrl + M for manage classes
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                switchTab('manage-classes');
            }
            // Ctrl + C for class codes
            if (e.ctrlKey && e.key === 'c') {
                e.preventDefault();
                switchTab('class-codes');
            }
            // Escape to close modals
            if (e.key === 'Escape') {
                closeModal('editClassModal');
                closeModal('deleteModal');
                userMenuDropdown.classList.remove('show');
            }
        });
        
        console.log('Keyboard shortcuts:\nCtrl+N: New Class\nCtrl+M: Manage Classes\nCtrl+C: Class Codes\nEsc: Close Modals');
    </script>
</body>
</html>