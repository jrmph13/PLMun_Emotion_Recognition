<?php
// Start session and database connection
require_once 'config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Get current user data
$userData = getUserData();
if (!$userData) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = false;
$password_errors = [];
$password_success = false;

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $errors[] = "Security token validation failed.";
    } else {
        // Sanitize input data
        $full_name = sanitizeInput($_POST['full_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $personal_email = sanitizeInput($_POST['personal_email'] ?? '');
        $contact_number = sanitizeInput($_POST['contact_number'] ?? '');
        $department = sanitizeInput($_POST['department'] ?? '');
        
        // Basic validation
        if (empty($full_name)) {
            $errors[] = "Full name is required.";
        }
        
        if (!empty($email) && !validateEmail($email)) {
            $errors[] = "Invalid email format.";
        }
        
        if (!empty($personal_email) && !validateEmail($personal_email)) {
            $errors[] = "Invalid personal email format.";
        }
        
        // If no errors, update the database
        if (empty($errors)) {
            try {
                $sql = "UPDATE users SET 
                        full_name = :full_name,
                        email = :email,
                        personal_email = :personal_email,
                        contact_number = :contact_number,
                        department = :department,
                        updated_at = NOW()
                        WHERE id = :id";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':full_name' => $full_name,
                    ':email' => $email,
                    ':personal_email' => $personal_email,
                    ':contact_number' => $contact_number,
                    ':department' => $department,
                    ':id' => $user_id
                ]);
                
                // Update session data
                $_SESSION['full_name'] = $full_name;
                $userData['full_name'] = $full_name;
                $userData['email'] = $email;
                $userData['personal_email'] = $personal_email;
                $userData['contact_number'] = $contact_number;
                $userData['department'] = $department;
                
                $success = true;
                setFlash('success', 'Profile updated successfully!');
                
            } catch (PDOException $e) {
                error_log("Profile Update Error: " . $e->getMessage());
                $errors[] = "An error occurred while updating your profile. Please try again.";
            }
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $password_errors[] = "Security token validation failed.";
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Get current password hash from database
        try {
            $sql = "SELECT password FROM users WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $password_errors[] = "User not found.";
            } else {
                // Verify current password
                if (!verifyPassword($current_password, $user['password'])) {
                    $password_errors[] = "Current password is incorrect.";
                }
                
                // Validate new password
                if (strlen($new_password) < 8) {
                    $password_errors[] = "New password must be at least 8 characters long.";
                }
                
                if ($new_password !== $confirm_password) {
                    $password_errors[] = "New password and confirmation password do not match.";
                }
                
                // Check if new password is different from current
                if (verifyPassword($new_password, $user['password'])) {
                    $password_errors[] = "New password cannot be the same as current password.";
                }
                
                // If no errors, update password
                if (empty($password_errors)) {
                    $new_password_hash = hashPassword($new_password);
                    
                    $sql = "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':password' => $new_password_hash,
                        ':id' => $user_id
                    ]);
                    
                    $password_success = true;
                    setFlash('success', 'Password changed successfully!');
                }
            }
            
        } catch (PDOException $e) {
            error_log("Password Change Error: " . $e->getMessage());
            $password_errors[] = "An error occurred while changing your password. Please try again.";
        }
    }
}

// Get updated user data
$userData = getUserData();

// Get admin-specific statistics
$admin_stats = [
    'total_users' => 0,
    'total_instructors' => 0,
    'total_students' => 0,
    'total_classes' => 0,
    'total_sessions' => 0,
    'active_users' => 0
];

try {
    // Get total users count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $admin_stats['total_users'] = $stmt->fetch()['count'] ?? 0;
    
    // Get total instructors count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'instructor'");
    $admin_stats['total_instructors'] = $stmt->fetch()['count'] ?? 0;
    
    // Get total students count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'");
    $admin_stats['total_students'] = $stmt->fetch()['count'] ?? 0;
    
    // Get total classes count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM classes");
    $admin_stats['total_classes'] = $stmt->fetch()['count'] ?? 0;
    
    // Get total sessions count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM live_sessions");
    $admin_stats['total_sessions'] = $stmt->fetch()['count'] ?? 0;
    
    // Get active users count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
    $admin_stats['active_users'] = $stmt->fetch()['count'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Admin Stats Error: " . $e->getMessage());
    // Use default values if there's an error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Emotion System</title>
    <style>
        /* Reuse styles from analytics page with profile-specific additions */
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
        
        .user-profile-sidebar {
            padding: 25px 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: rgba(30, 41, 59, 0.5);
            margin: 10px;
            border-radius: 10px;
        }
        
        .user-avatar-sidebar {
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
        
        .user-info-sidebar h3 {
            font-size: 16px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .user-info-sidebar p {
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

        /* Profile Header */
        .profile-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .profile-title h1 {
            color: #1f2937;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .profile-title p {
            color: #6b7280;
            font-size: 14px;
        }
        
        /* Profile Container */
        .profile-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
        }
        
        @media (max-width: 992px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }
        
        /* Profile Card */
        .profile-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .profile-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .profile-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
        }
        
        /* Profile Info Section */
        .profile-info {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: bold;
            color: white;
            margin: 0 auto 20px;
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3);
        }
        
        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .profile-role {
            display: inline-block;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(59, 130, 246, 0.1) 100%);
            color: #8b5cf6;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        /* Admin Stats */
        .admin-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
            transition: all 0.3s;
        }
        
        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            color: #4b5563;
            background: white;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .form-control:disabled {
            background: #f9fafb;
            color: #9ca3af;
            cursor: not-allowed;
        }
        
        /* Button Styles */
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
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
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
        
        .alert-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #f59e0b;
            color: #92400e;
        }
        
        .alert-info {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: 1px solid #3b82f6;
            color: #1e40af;
        }
        
        .alert-icon {
            font-size: 20px;
        }
        
        /* Password Strength Indicator */
        .password-strength {
            margin-top: 8px;
        }
        
        .strength-bar {
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 3px;
        }
        
        .strength-text {
            font-size: 12px;
            color: #6b7280;
        }
        
        .strength-weak {
            background: linear-gradient(90deg, #ef4444 0%, #f87171 100%);
        }
        
        .strength-fair {
            background: linear-gradient(90deg, #f59e0b 0%, #fbbf24 100%);
        }
        
        .strength-good {
            background: linear-gradient(90deg, #10b981 0%, #34d399 100%);
        }
        
        .strength-strong {
            background: linear-gradient(90deg, #8b5cf6 0%, #a78bfa 100%);
        }
        
        /* Account Info */
        .account-info {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #f3f4f6;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
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
            .user-info-sidebar,
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
            .sidebar:hover .user-info-sidebar,
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
            
            .profile-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            
            .admin-stats {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 36px;
            }
            
            .profile-name {
                font-size: 20px;
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
        
        /* Flash Message */
        .flash-message {
            animation: slideDown 0.5s ease;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        /* Admin Badge */
        .admin-badge {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
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
            
            <div class="menu-title">Analytics & Reposrts</div>
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
            <a href="admin_profile.php" class="menu-item active">
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
                <h2>Admin Profile</h2>
            </div>
            <div class="topbar-right">
                <div class="user-menu">
                    <button class="user-menu-btn" id="userMenuBtn">
                        <div class="user-avatar-small">
                            <?php echo getInitials($userData['full_name'] ?? 'AD'); ?>
                        </div>
                        <span><?php echo htmlspecialchars($userData['full_name'] ?? 'Administrator'); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <a href="profile.php" class="user-menu-item">
                            <i class="fas fa-user-circle"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="settings.php" class="user-menu-item">
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
            <!-- Flash Messages -->
            <?php 
            $flash = getFlash();
            if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type']; ?> flash-message">
                    <i class="alert-icon fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                    <span><?php echo htmlspecialchars($flash['message']); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-title">
                    <h1>Admin Profile Settings</h1>
                    <p>Manage your administrator account and system access</p>
                </div>
                <div class="profile-actions">
                    <button class="btn btn-secondary" onclick="window.print()">
                        <i class="fas fa-print"></i>
                        <span>Print Profile</span>
                    </button>
                </div>
            </div>
            
            <!-- Profile Container -->
            <div class="profile-container">
                <!-- Left Column: Profile Info -->
                <div>
                    <!-- Profile Card -->
                    <div class="profile-card">
                        <div class="profile-info">
                            <div class="profile-avatar">
                                <?php echo getInitials($userData['full_name'] ?? 'AD'); ?>
                            </div>
                            <div class="profile-name">
                                <?php echo htmlspecialchars($userData['full_name'] ?? 'Administrator'); ?>
                            </div>
                            <div class="profile-role">
                                <i class="fas fa-crown" style="margin-right: 5px;"></i>
                                System Administrator
                            </div>
                            <p style="color: #6b7280; font-size: 14px; margin-bottom: 20px;">
                                <i class="fas fa-calendar-alt"></i>
                                Admin since <?php echo formatDate($userData['created_at'] ?? '', 'F Y'); ?>
                            </p>
                        </div>
                        
                        <!-- Admin Statistics -->
                        <div class="admin-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $admin_stats['total_users']; ?></div>
                                <div class="stat-label">Total Users</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $admin_stats['active_users']; ?></div>
                                <div class="stat-label">Active Users</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $admin_stats['total_instructors']; ?></div>
                                <div class="stat-label">Instructors</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $admin_stats['total_students']; ?></div>
                                <div class="stat-label">Students</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $admin_stats['total_classes']; ?></div>
                                <div class="stat-label">Total Classes</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $admin_stats['total_sessions']; ?></div>
                                <div class="stat-label">Live Sessions</div>
                            </div>
                        </div>
                        
                        <!-- Account Information -->
                        <div class="account-info">
                            <div class="info-item">
                                <span class="info-label">Account Status</span>
                                <span class="info-value" style="color: #10b981;">
                                    <i class="fas fa-circle" style="font-size: 8px;"></i>
                                    Active
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Last Login</span>
                                <span class="info-value"><?php echo date('M d, Y H:i'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Admin ID</span>
                                <span class="info-value">ADM-<?php echo str_pad($userData['id'] ?? 0, 6, '0', STR_PAD_LEFT); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Access Level</span>
                                <span class="info-value">Full System Access</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Forms -->
                <div>
                    <!-- Update Profile Form -->
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <div class="profile-card-title">
                                <i class="fas fa-user-edit" style="margin-right: 10px;"></i>
                                Administrator Information
                            </div>
                        </div>
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-error">
                                <i class="alert-icon fas fa-exclamation-circle"></i>
                                <div>
                                    <strong>Please fix the following errors:</strong>
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <?php echo csrfField(); ?>
                            
                            <div class="form-group">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($userData['username'] ?? ''); ?>" disabled>
                                <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">Admin username cannot be changed</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="full_name">Full Name *</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="email">Official Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>">
                                <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">For official communications</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="personal_email">Personal Email</label>
                                <input type="email" class="form-control" id="personal_email" name="personal_email" 
                                       value="<?php echo htmlspecialchars($userData['personal_email'] ?? ''); ?>">
                                <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">For personal notifications</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="contact_number">Contact Number</label>
                                <input type="tel" class="form-control" id="contact_number" name="contact_number" 
                                       value="<?php echo htmlspecialchars($userData['contact_number'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="department">Department</label>
                                <input type="text" class="form-control" id="department" name="department" 
                                       value="<?php echo htmlspecialchars($userData['department'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Administrator Role</label>
                                <input type="text" class="form-control" value="System Administrator" disabled>
                                <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">Full system access and privileges</small>
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                <span>Update Admin Profile</span>
                            </button>
                        </form>
                    </div>
                    
                    <!-- Change Password Form -->
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <div class="profile-card-title">
                                <i class="fas fa-key" style="margin-right: 10px;"></i>
                                Admin Security Settings
                            </div>
                        </div>
                        
                        <?php if (!empty($password_errors)): ?>
                            <div class="alert alert-error">
                                <i class="alert-icon fas fa-exclamation-circle"></i>
                                <div>
                                    <strong>Please fix the following errors:</strong>
                                    <ul style="margin-top: 5px; padding-left: 20px;">
                                        <?php foreach ($password_errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($password_success): ?>
                            <div class="alert alert-success">
                                <i class="alert-icon fas fa-check-circle"></i>
                                <span>Admin password changed successfully!</span>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <?php echo csrfField(); ?>
                            
                            <div class="form-group">
                                <label class="form-label" for="current_password">Current Admin Password *</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">Enter your current administrator password</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="new_password">New Admin Password *</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required 
                                       oninput="checkPasswordStrength(this.value)">
                                <div class="password-strength">
                                    <div class="strength-bar">
                                        <div class="strength-fill" id="strengthFill"></div>
                                    </div>
                                    <div class="strength-text" id="strengthText">Password strength</div>
                                </div>
                                <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                                    <i class="fas fa-shield-alt"></i> Administrator password must be extra secure
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="confirm_password">Confirm New Password *</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="alert-icon fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>Security Notice:</strong> As an administrator, your password protects sensitive system data. 
                                    Choose a strong password and change it regularly.
                                </div>
                            </div>
                            
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-lock"></i>
                                <span>Update Admin Password</span>
                            </button>
                        </form>
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
        
        // Password strength indicator
        function checkPasswordStrength(password) {
            const strengthFill = document.getElementById('strengthFill');
            const strengthText = document.getElementById('strengthText');
            
            let strength = 0;
            let text = 'Password strength';
            let colorClass = '';
            
            if (password.length >= 12) strength++; // Higher requirement for admin
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            switch (strength) {
                case 0:
                case 1:
                    width = 20;
                    text = 'Very Weak - Not suitable for admin';
                    colorClass = 'strength-weak';
                    break;
                case 2:
                    width = 40;
                    text = 'Weak - Not recommended';
                    colorClass = 'strength-weak';
                    break;
                case 3:
                    width = 60;
                    text = 'Fair - Should be stronger';
                    colorClass = 'strength-fair';
                    break;
                case 4:
                    width = 80;
                    text = 'Good - Acceptable for admin';
                    colorClass = 'strength-good';
                    break;
                case 5:
                    width = 100;
                    text = 'Strong - Excellent for admin';
                    colorClass = 'strength-strong';
                    break;
                default:
                    width = 0;
            }
            
            strengthFill.style.width = width + '%';
            strengthFill.className = 'strength-fill ' + colorClass;
            strengthText.textContent = text;
            strengthText.style.color = getComputedStyle(strengthFill).backgroundColor;
        }
        
        // Confirm password validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        
        function validatePasswords() {
            if (newPassword.value && confirmPassword.value) {
                if (newPassword.value !== confirmPassword.value) {
                    confirmPassword.style.borderColor = '#ef4444';
                    confirmPassword.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                } else {
                    confirmPassword.style.borderColor = '#10b981';
                    confirmPassword.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.1)';
                }
            }
        }
        
        newPassword.addEventListener('input', validatePasswords);
        confirmPassword.addEventListener('input', validatePasswords);
        
        // Form validation
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                const requiredFields = form.querySelectorAll('[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.style.borderColor = '#ef4444';
                        field.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                        
                        // Reset border on input
                        field.addEventListener('input', function() {
                            this.style.borderColor = '#e5e7eb';
                            this.style.boxShadow = 'none';
                        });
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    showNotification('Please fill in all required fields.', 'error');
                }
            });
        });
        
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
        
        // Add CSS for notifications
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
        `;
        document.head.appendChild(style);
        
        // Auto-hide flash messages after 5 seconds
        setTimeout(() => {
            const flashMessages = document.querySelectorAll('.flash-message');
            flashMessages.forEach(msg => {
                msg.style.transition = 'opacity 0.5s ease';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + S to save profile
            if (e.ctrlKey && e.key === 's' && document.activeElement.tagName !== 'INPUT') {
                e.preventDefault();
                const updateBtn = document.querySelector('button[name="update_profile"]');
                if (updateBtn) updateBtn.click();
            }
            
            // Ctrl + P to change password
            if (e.ctrlKey && e.key === 'p' && document.activeElement.tagName !== 'INPUT') {
                e.preventDefault();
                const changePassBtn = document.querySelector('button[name="change_password"]');
                if (changePassBtn) changePassBtn.click();
            }
        });
        
        console.log('Admin Profile Page Ready');
        console.log('Shortcuts: Ctrl+S (Save Profile), Ctrl+P (Change Password)');
    </script>
</body>
</html>     