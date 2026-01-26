<?php
// ==================== STUDENT PROFILE PAGE ====================
// Start session and load configuration
require_once 'config.php';

// Require student role
requireStudent();

// Get current user data
$userData = getUserData();
$student_id = getStudentId(); // Get student ID from students table
$user_id = $_SESSION['user_id'];

// Initialize variables
$success_message = '';
$error_message = '';
$password_errors = [];

// Handle password change form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error_message = "Invalid security token. Please try again.";
    } else {
        // Get form data
        $current_password = sanitizeInput($_POST['current_password'] ?? '');
        $new_password = sanitizeInput($_POST['new_password'] ?? '');
        $confirm_password = sanitizeInput($_POST['confirm_password'] ?? '');
        
        // Validate inputs
        $errors = [];
        
        if (empty($current_password)) {
            $errors['current_password'] = "Current password is required";
        }
        
        if (empty($new_password)) {
            $errors['new_password'] = "New password is required";
        } elseif (strlen($new_password) < 8) {
            $errors['new_password'] = "Password must be at least 8 characters long";
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $errors['new_password'] = "Password must contain at least one uppercase letter";
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $errors['new_password'] = "Password must contain at least one lowercase letter";
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $errors['new_password'] = "Password must contain at least one number";
        }
        
        if (empty($confirm_password)) {
            $errors['confirm_password'] = "Please confirm your new password";
        } elseif ($new_password !== $confirm_password) {
            $errors['confirm_password'] = "Passwords do not match";
        }
        
        // Verify current password
        if (empty($errors) && $current_password) {
            try {
                $stmt = $pdo->prepare("SELECT password FROM " . TABLE_USERS . " WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if (!$user || !verifyPassword($current_password, $user['password'])) {
                    $errors['current_password'] = "Current password is incorrect";
                }
            } catch (PDOException $e) {
                error_log("Password verification error: " . $e->getMessage());
                $error_message = "Error verifying password. Please try again.";
            }
        }
        
        // If no errors, update password
        if (empty($errors)) {
            try {
                $hashed_password = hashPassword($new_password);
                
                $stmt = $pdo->prepare("UPDATE " . TABLE_USERS . " SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                // Log the password change
                logAuditTrail(
                    $user_id,
                    $_SESSION['role'],
                    $_SESSION['username'],
                    'update',
                    "Changed password",
                    TABLE_USERS,
                    $user_id,
                    ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']
                );
                
                $success_message = "Password changed successfully!";
                
                // Clear form
                $_POST['current_password'] = '';
                $_POST['new_password'] = '';
                $_POST['confirm_password'] = '';
                
                // Send success message
                setFlash('success', 'Password changed successfully!');
                header("Location: student_profile.php?success=password_changed");
                exit();
                
            } catch (PDOException $e) {
                error_log("Password update error: " . $e->getMessage());
                $error_message = "Error changing password. Please try again.";
            }
        } else {
            $password_errors = $errors;
        }
    }
}

// Check for success parameter
if (isset($_GET['success']) && $_GET['success'] == 'password_changed') {
    $success_message = "Password changed successfully!";
}

// Get student's enrolled classes
$enrolled_classes = [];
try {
    if ($student_id) {
        $stmt = $pdo->prepare("
            SELECT c.*, u.full_name as instructor_name 
            FROM " . TABLE_CLASSES . " c
            JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
            JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
            WHERE ce.student_id = ? AND c.is_active = 1
            ORDER BY c.class_name
        ");
        $stmt->execute([$student_id]);
        $enrolled_classes = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log("Enrolled classes query error: " . $e->getMessage());
}

// Get student engagement statistics
$engagementStats = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT sa.session_id) as attended_sessions,
            COUNT(DISTINCT ls.id) as total_sessions,
            AVG(ses.engagement_score) as avg_engagement,
            AVG(ses.happy_percent) as avg_happiness
        FROM " . TABLE_SESSION_ATTENDANCE . " sa
        JOIN " . TABLE_LIVE_SESSIONS . " ls ON sa.session_id = ls.id
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON sa.session_id = ses.session_id AND sa.student_id = ses.student_id
        WHERE sa.student_id = ?
        AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([$student_id]);
    $engagementStats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Engagement stats error: " . $e->getMessage());
}

// Get recent announcements
$recentAnnouncements = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, c.class_name, u.full_name as instructor_name
        FROM " . TABLE_ANNOUNCEMENTS . " a
        JOIN " . TABLE_CLASSES . " c ON a.class_id = c.id
        JOIN " . TABLE_USERS . " u ON a.created_by = u.id
        WHERE a.class_id IN (SELECT class_id FROM " . TABLE_CLASS_ENROLLMENTS . " WHERE student_id = ?)
        AND a.is_active = 1
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$student_id]);
    $recentAnnouncements = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Recent announcements error: " . $e->getMessage());
}

// Check consent status for the sidebar badge
$consentStatus = checkConsentStatus($user_id);

// Set page title
$page_title = "Student Profile - Emotion AI System";

// Log page access for audit trail
logAuditTrail(
    $user_id,
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    'Accessed student profile page',
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
        
        /* ==================== STUDENT PROFILE SPECIFIC STYLES ==================== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-header h1 {
            color: #1f2937;
            font-size: 28px;
            font-weight: 700;
        }
        
        .page-description {
            color: #6b7280;
            font-size: 16px;
            margin-bottom: 30px;
            max-width: 800px;
        }
        
        /* Two Column Layout */
        .profile-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1024px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }
        
        /* Profile Info Card */
        .profile-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            height: fit-content;
        }
        
        .profile-card h3 {
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
        
        .profile-card h3 i {
            color: #8b5cf6;
        }
        
        .profile-card h3 small {
            font-size: 14px;
            color: #6b7280;
            font-weight: 400;
        }
        
        /* Profile Information Grid */
        .profile-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .info-item {
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #8b5cf6;
        }
        
        .info-item h4 {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .info-item p {
            font-size: 16px;
            color: #1f2937;
            font-weight: 500;
        }
        
        .info-item .info-icon {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .info-item .info-icon i {
            color: #8b5cf6;
            font-size: 16px;
        }
        
        /* Enrolled Classes */
        .classes-section {
            margin-top: 30px;
        }
        
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .class-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .class-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border-color: #d1d5db;
        }
        
        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .class-name {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .class-code {
            font-size: 14px;
            color: #6b7280;
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .class-details {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .class-details i {
            width: 20px;
            color: #8b5cf6;
        }
        
        /* Password Change Form */
        .password-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 100px;
        }
        
        .password-card h3 {
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
        
        .password-card h3 i {
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
        
        .form-control.error {
            border-color: #ef4444;
        }
        
        .error-message {
            color: #ef4444;
            font-size: 13px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
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
        
        .password-requirements {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-size: 13px;
            color: #6b7280;
        }
        
        .password-requirements h5 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
        }
        
        .password-requirements ul {
            list-style: none;
            padding-left: 0;
        }
        
        .password-requirements li {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .password-requirements li i {
            font-size: 12px;
        }
        
        .password-requirements li.valid {
            color: #10b981;
        }
        
        .password-requirements li.invalid {
            color: #ef4444;
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
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px dashed #e5e7eb;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 15px;
        }
        
        .empty-state h4 {
            color: #6b7280;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .empty-state p {
            color: #9ca3af;
            font-size: 14px;
            max-width: 300px;
            margin: 0 auto 15px;
        }
        
        /* Info Note */
        .info-note {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #0ea5e9;
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .info-note i {
            color: #0ea5e9;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .info-note-content h4 {
            color: #0369a1;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .info-note-content p {
            color: #0c4a6e;
            font-size: 14px;
            line-height: 1.5;
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
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .profile-info-grid {
                grid-template-columns: 1fr;
            }
            
            .classes-grid {
                grid-template-columns: 1fr;
            }
            
            .topbar-right {
                gap: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .profile-container {
                gap: 20px;
            }
            
            .profile-card,
            .password-card {
                padding: 20px;
            }
            
            .user-menu-btn span {
                display: none;
            }
            
            .user-menu-btn i.fa-chevron-down {
                display: none;
            }
            
            .class-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
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
                <?php if (!empty($enrolled_classes)): ?>
                    <span class="menu-badge"><?php echo count($enrolled_classes); ?></span>
                <?php endif; ?>
            </a>
        
            
            <a href="student_my_engagement.php" class="menu-item">
                <i class="menu-icon fas fa-chart-line"></i>
                <span>My Engagement</span>
                <?php if ($engagementStats['avg_engagement'] ?? 0 > 0): ?>
                    <span class="menu-badge"><?php echo round($engagementStats['avg_engagement'], 1); ?>%</span>
                <?php endif; ?>
            </a>
            
            <a href="student_announcement.php" class="menu-item">
                <i class="menu-icon fas fa-bullhorn"></i>
                <span>Announcement</span>
                <?php if (!empty($recentAnnouncements)): ?>
                    <span class="menu-badge"><?php echo count($recentAnnouncements); ?></span>
                <?php endif; ?>
            </a>
            
            <div class="menu-title">Account Settings</div>
            
            <a href="student_profile.php" class="menu-item active">
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
                <h2>Student Profile</h2>
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
                            <?php echo getInitials($userData['full_name'] ?? 'ST'); ?>
                        </div>
                        <span><?php echo htmlspecialchars($userData['full_name'] ?? 'Student'); ?></span>
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
            
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Student Profile</h1>
                    <p class="page-description">
                        View your personal information and manage your account settings. 
                        <span style="color: #ef4444;">Note: Personal information can only be updated by administrators.</span>
                    </p>
                </div>
            </div>
            
            <!-- Two Column Layout -->
            <div class="profile-container">
                <!-- Profile Information -->
                <div class="profile-card">
                    <h3>
                        <i class="fas fa-user-circle"></i> 
                        Personal Information
                        <small>Last updated: <?php echo formatDate($userData['updated_at'] ?? $userData['created_at'] ?? '', 'F j, Y'); ?></small>
                    </h3>
                    
                    <!-- Profile Info Grid -->
                    <div class="profile-info-grid">
                        <!-- Basic Information -->
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-id-card"></i>
                                <h4>Student Number</h4>
                            </div>
                            <p><?php echo htmlspecialchars($userData['student_number'] ?? 'Not set'); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-user"></i>
                                <h4>Full Name</h4>
                            </div>
                            <p><?php echo htmlspecialchars($userData['full_name'] ?? 'Not set'); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-graduation-cap"></i>
                                <h4>Course</h4>
                            </div>
                            <p><?php echo htmlspecialchars($userData['course'] ?? 'Not set'); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-calendar-alt"></i>
                                <h4>Year Level</h4>
                            </div>
                            <p><?php echo htmlspecialchars($userData['year_level'] ?? 'Not set'); ?></p>
                        </div>
                        
                        <!-- Contact Information -->
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-envelope"></i>
                                <h4>School Email</h4>
                            </div>
                            <p><?php echo htmlspecialchars($userData['email'] ?? 'Not set'); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-envelope-open"></i>
                                <h4>Personal Email</h4>
                            </div>
                            <p><?php echo htmlspecialchars($userData['personal_email'] ?? 'Not set'); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-phone"></i>
                                <h4>Contact Number</h4>
                            </div>
                            <p><?php echo htmlspecialchars($userData['contact_number'] ?? 'Not set'); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-building"></i>
                                <h4>Department</h4>
                            </div>
                            <p><?php echo htmlspecialchars($userData['department'] ?? 'Not set'); ?></p>
                        </div>
                        
                        <!-- Account Information -->
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-user-tag"></i>
                                <h4>Username</h4>
                            </div>
                            <p><?php echo htmlspecialchars($userData['username'] ?? 'Not set'); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-shield-alt"></i>
                                <h4>Account Status</h4>
                            </div>
                            <p>
                                <?php if ($userData['is_active'] ?? 0): ?>
                                    <span style="color: #10b981; font-weight: 600;">Active</span>
                                <?php else: ?>
                                    <span style="color: #ef4444; font-weight: 600;">Inactive</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i class="fas fa-calendar-plus"></i>
                                <h4>Account Created</h4>
                            </div>
                            <p><?php echo formatDate($userData['created_at'] ?? '', 'F j, Y'); ?></p>
                        </div>
                    </div>
                    
                    <!-- Information Note -->
                    <div class="info-note">
                        <i class="fas fa-info-circle"></i>
                        <div class="info-note-content">
                            <h4>Information Update Policy</h4>
                            <p>For security and verification purposes, personal information updates must be processed by the system administrator. 
                               If you need to update any personal information, please contact your department administrator.</p>
                        </div>
                    </div>
                    
                    <!-- Enrolled Classes Section -->
                    <div class="classes-section">
                        <h3 style="margin-top: 30px;">
                            <i class="fas fa-chalkboard-teacher"></i> 
                            Enrolled Classes
                            <span style="font-size: 14px; color: #6b7280; font-weight: 400;">
                                (<?php echo count($enrolled_classes); ?> classes)
                            </span>
                        </h3>
                        
                        <?php if (empty($enrolled_classes)): ?>
                            <div class="empty-state">
                                <i class="fas fa-chalkboard"></i>
                                <h4>Not Enrolled in Any Classes</h4>
                                <p>You are not currently enrolled in any active classes.</p>
                            </div>
                        <?php else: ?>
                            <div class="classes-grid">
                                <?php foreach ($enrolled_classes as $class): ?>
                                    <div class="class-card">
                                        <div class="class-header">
                                            <div>
                                                <div class="class-name">
                                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                                </div>
                                                <span class="class-code">
                                                    <?php echo htmlspecialchars($class['class_code']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="class-details">
                                            <p style="margin-bottom: 10px;">
                                                <i class="fas fa-user-tie"></i>
                                                <strong>Instructor:</strong> <?php echo htmlspecialchars($class['instructor_name']); ?>
                                            </p>
                                            
                                            <?php if ($class['description']): ?>
                                                <p style="margin-bottom: 10px;">
                                                    <i class="fas fa-align-left"></i>
                                                    <strong>Description:</strong> 
                                                    <?php echo htmlspecialchars(substr($class['description'], 0, 100)); ?>
                                                    <?php if (strlen($class['description']) > 100): ?>...<?php endif; ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <?php if ($class['schedule']): ?>
                                                <p style="margin-bottom: 10px;">
                                                    <i class="fas fa-clock"></i>
                                                    <strong>Schedule:</strong> <?php echo formatDate($class['schedule'], 'F j, Y g:i A'); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <p>
                                                <i class="fas fa-users"></i>
                                                <strong>Class Type:</strong> 
                                                <?php if ($class['emotion_tracking']): ?>
                                                    <span style="color: #8b5cf6;">Emotion Tracking Enabled</span>
                                                <?php else: ?>
                                                    <span>Regular Class</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Password Change Form -->
                <div class="password-card">
                    <h3>
                        <i class="fas fa-key"></i> 
                        Change Password
                    </h3>
                    
                    <form method="POST" action="" id="passwordForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="form-group">
                            <label class="form-label" for="current_password">
                                <i class="fas fa-lock"></i> Current Password
                            </label>
                            <input type="password" 
                                   class="form-control <?php echo isset($password_errors['current_password']) ? 'error' : ''; ?>" 
                                   id="current_password" 
                                   name="current_password" 
                                   placeholder="Enter your current password"
                                   value="<?php echo isset($_POST['current_password']) ? htmlspecialchars($_POST['current_password']) : ''; ?>"
                                   required>
                            <?php if (isset($password_errors['current_password'])): ?>
                                <div class="error-message">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?php echo $password_errors['current_password']; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="new_password">
                                <i class="fas fa-key"></i> New Password
                            </label>
                            <input type="password" 
                                   class="form-control <?php echo isset($password_errors['new_password']) ? 'error' : ''; ?>" 
                                   id="new_password" 
                                   name="new_password" 
                                   placeholder="Enter new password"
                                   value="<?php echo isset($_POST['new_password']) ? htmlspecialchars($_POST['new_password']) : ''; ?>"
                                   required>
                            <?php if (isset($password_errors['new_password'])): ?>
                                <div class="error-message">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?php echo $password_errors['new_password']; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="confirm_password">
                                <i class="fas fa-key"></i> Confirm New Password
                            </label>
                            <input type="password" 
                                   class="form-control <?php echo isset($password_errors['confirm_password']) ? 'error' : ''; ?>" 
                                   id="confirm_password" 
                                   name="confirm_password" 
                                   placeholder="Confirm new password"
                                   value="<?php echo isset($_POST['confirm_password']) ? htmlspecialchars($_POST['confirm_password']) : ''; ?>"
                                   required>
                            <?php if (isset($password_errors['confirm_password'])): ?>
                                <div class="error-message">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?php echo $password_errors['confirm_password']; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="password-requirements">
                            <h5>Password Requirements:</h5>
                            <ul>
                                <li id="req-length" class="invalid">
                                    <i class="fas fa-circle"></i>
                                    At least 8 characters
                                </li>
                                <li id="req-uppercase" class="invalid">
                                    <i class="fas fa-circle"></i>
                                    Contains uppercase letter
                                </li>
                                <li id="req-lowercase" class="invalid">
                                    <i class="fas fa-circle"></i>
                                    Contains lowercase letter
                                </li>
                                <li id="req-number" class="invalid">
                                    <i class="fas fa-circle"></i>
                                    Contains number
                                </li>
                                <li id="req-match" class="invalid">
                                    <i class="fas fa-circle"></i>
                                    Passwords match
                                </li>
                            </ul>
                        </div>
                        
                        <div class="form-actions">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i>
                                <span>Clear</span>
                            </button>
                            <button type="submit" class="btn btn-success" id="submitBtn">
                                <i class="fas fa-save"></i>
                                <span>Change Password</span>
                            </button>
                        </div>
                    </form>
                    
                    <!-- Security Note -->
                    <div class="info-note" style="margin-top: 30px;">
                        <i class="fas fa-shield-alt"></i>
                        <div class="info-note-content">
                            <h4>Security Tips</h4>
                            <p>• Use a strong, unique password<br>
                               • Don't reuse passwords from other sites<br>
                               • Update your password regularly<br>
                               • Never share your password with anyone</p>
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
        
        // Notification button
        const notificationBtn = document.getElementById('notificationBtn');
        notificationBtn.addEventListener('click', () => {
            alert('You have <?php echo count($recentAnnouncements); ?> unread announcements.');
        });
        
        // Password validation
        const passwordForm = document.getElementById('passwordForm');
        const currentPassword = document.getElementById('current_password');
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submitBtn');
        
        // Password requirement elements
        const reqLength = document.getElementById('req-length');
        const reqUppercase = document.getElementById('req-uppercase');
        const reqLowercase = document.getElementById('req-lowercase');
        const reqNumber = document.getElementById('req-number');
        const reqMatch = document.getElementById('req-match');
        
        // Validate password on input
        newPassword.addEventListener('input', validatePassword);
        confirmPassword.addEventListener('input', validatePassword);
        
        function validatePassword() {
            const password = newPassword.value;
            const confirm = confirmPassword.value;
            
            // Validate length
            if (password.length >= 8) {
                reqLength.classList.remove('invalid');
                reqLength.classList.add('valid');
                reqLength.innerHTML = '<i class="fas fa-check-circle"></i> At least 8 characters';
            } else {
                reqLength.classList.remove('valid');
                reqLength.classList.add('invalid');
                reqLength.innerHTML = '<i class="fas fa-circle"></i> At least 8 characters';
            }
            
            // Validate uppercase
            if (/[A-Z]/.test(password)) {
                reqUppercase.classList.remove('invalid');
                reqUppercase.classList.add('valid');
                reqUppercase.innerHTML = '<i class="fas fa-check-circle"></i> Contains uppercase letter';
            } else {
                reqUppercase.classList.remove('valid');
                reqUppercase.classList.add('invalid');
                reqUppercase.innerHTML = '<i class="fas fa-circle"></i> Contains uppercase letter';
            }
            
            // Validate lowercase
            if (/[a-z]/.test(password)) {
                reqLowercase.classList.remove('invalid');
                reqLowercase.classList.add('valid');
                reqLowercase.innerHTML = '<i class="fas fa-check-circle"></i> Contains lowercase letter';
            } else {
                reqLowercase.classList.remove('valid');
                reqLowercase.classList.add('invalid');
                reqLowercase.innerHTML = '<i class="fas fa-circle"></i> Contains lowercase letter';
            }
            
            // Validate number
            if (/[0-9]/.test(password)) {
                reqNumber.classList.remove('invalid');
                reqNumber.classList.add('valid');
                reqNumber.innerHTML = '<i class="fas fa-check-circle"></i> Contains number';
            } else {
                reqNumber.classList.remove('valid');
                reqNumber.classList.add('invalid');
                reqNumber.innerHTML = '<i class="fas fa-circle"></i> Contains number';
            }
            
            // Validate match
            if (password && confirm && password === confirm) {
                reqMatch.classList.remove('invalid');
                reqMatch.classList.add('valid');
                reqMatch.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
            } else if (confirm) {
                reqMatch.classList.remove('valid');
                reqMatch.classList.add('invalid');
                reqMatch.innerHTML = '<i class="fas fa-circle"></i> Passwords match';
            } else {
                reqMatch.classList.remove('valid');
                reqMatch.classList.add('invalid');
                reqMatch.innerHTML = '<i class="fas fa-circle"></i> Passwords match';
            }
            
            // Enable/disable submit button based on all requirements
            const allValid = password.length >= 8 && 
                           /[A-Z]/.test(password) && 
                           /[a-z]/.test(password) && 
                           /[0-9]/.test(password) && 
                           password === confirm;
            
            submitBtn.disabled = !allValid;
        }
        
        // Form submission validation
        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                // Clear previous error styles
                [currentPassword, newPassword, confirmPassword].forEach(input => {
                    input.classList.remove('error');
                });
                
                // Validate current password
                if (!currentPassword.value.trim()) {
                    e.preventDefault();
                    currentPassword.classList.add('error');
                    currentPassword.focus();
                    alert('Please enter your current password.');
                    return;
                }
                
                // Validate new password
                const password = newPassword.value;
                if (!password) {
                    e.preventDefault();
                    newPassword.classList.add('error');
                    newPassword.focus();
                    alert('Please enter a new password.');
                    return;
                }
                
                if (password.length < 8) {
                    e.preventDefault();
                    newPassword.classList.add('error');
                    newPassword.focus();
                    alert('Password must be at least 8 characters long.');
                    return;
                }
                
                if (!/[A-Z]/.test(password)) {
                    e.preventDefault();
                    newPassword.classList.add('error');
                    newPassword.focus();
                    alert('Password must contain at least one uppercase letter.');
                    return;
                }
                
                if (!/[a-z]/.test(password)) {
                    e.preventDefault();
                    newPassword.classList.add('error');
                    newPassword.focus();
                    alert('Password must contain at least one lowercase letter.');
                    return;
                }
                
                if (!/[0-9]/.test(password)) {
                    e.preventDefault();
                    newPassword.classList.add('error');
                    newPassword.focus();
                    alert('Password must contain at least one number.');
                    return;
                }
                
                // Validate password match
                if (password !== confirmPassword.value) {
                    e.preventDefault();
                    confirmPassword.classList.add('error');
                    confirmPassword.focus();
                    alert('Passwords do not match.');
                    return;
                }
                
                // Confirm password change
                if (!confirm('Are you sure you want to change your password?')) {
                    e.preventDefault();
                    return;
                }
            });
        }
        
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
        const clearButton = passwordForm?.querySelector('button[type="reset"]');
        if (clearButton) {
            clearButton.addEventListener('click', function(e) {
                if (currentPassword.value.trim() || newPassword.value.trim() || confirmPassword.value.trim()) {
                    if (!confirm('Are you sure you want to clear the form? All entered data will be lost.')) {
                        e.preventDefault();
                        return;
                    }
                    
                    // Reset validation UI
                    [reqLength, reqUppercase, reqLowercase, reqNumber, reqMatch].forEach(req => {
                        req.classList.remove('valid');
                        req.classList.add('invalid');
                        req.innerHTML = req.innerHTML.replace('fa-check-circle', 'fa-circle');
                    });
                    
                    submitBtn.disabled = false;
                }
            });
        }
        
        // Toggle password visibility (optional enhancement)
        function togglePasswordVisibility(inputId) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
            } else {
                input.type = 'password';
            }
        }
        
        // Add show/hide password buttons (optional)
        [currentPassword, newPassword, confirmPassword].forEach(input => {
            const wrapper = document.createElement('div');
            wrapper.style.position = 'relative';
            
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
            toggleBtn.style.position = 'absolute';
            toggleBtn.style.right = '10px';
            toggleBtn.style.top = '50%';
            toggleBtn.style.transform = 'translateY(-50%)';
            toggleBtn.style.background = 'none';
            toggleBtn.style.border = 'none';
            toggleBtn.style.color = '#6b7280';
            toggleBtn.style.cursor = 'pointer';
            
            toggleBtn.addEventListener('click', () => {
                if (input.type === 'password') {
                    input.type = 'text';
                    toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    input.type = 'password';
                    toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });
            
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            wrapper.appendChild(toggleBtn);
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + P to focus current password field
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                if (currentPassword) {
                    currentPassword.focus();
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
        });
        
        // Initialize password validation on page load
        document.addEventListener('DOMContentLoaded', function() {
            validatePassword();
            
            // If there's a success message, scroll to top
            if (successAlert) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    </script>
</body>
</html>