<?php
// ==================== TEACHER PROFILE PAGE ====================
require_once 'config.php';

// Require instructor or admin role
requireInstructor();

// Get current user data
$userData = getUserData();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Initialize variables
$success_message = '';
$error_message = '';
$form_errors = [];

// Only allow password change, NOT personal information updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error_message = "Security token validation failed. Please try again.";
    } else {
        // Get password fields
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate current password
        if (empty($current_password)) {
            $form_errors['current_password'] = 'Current password is required';
        } else {
            // Get current password hash from database
            $stmt = $pdo->prepare("SELECT password FROM " . TABLE_USERS . " WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || !verifyPassword($current_password, $user['password'])) {
                $form_errors['current_password'] = 'Current password is incorrect';
            }
        }
        
        // Validate new password
        if (empty($new_password)) {
            $form_errors['new_password'] = 'New password is required';
        } elseif (strlen($new_password) < 8) {
            $form_errors['new_password'] = 'Password must be at least 8 characters long';
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $form_errors['new_password'] = 'Password must contain at least one uppercase letter';
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $form_errors['new_password'] = 'Password must contain at least one lowercase letter';
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $form_errors['new_password'] = 'Password must contain at least one number';
        }
        
        // Validate confirm password
        if (empty($confirm_password)) {
            $form_errors['confirm_password'] = 'Please confirm your new password';
        } elseif ($new_password !== $confirm_password) {
            $form_errors['confirm_password'] = 'Passwords do not match';
        }
        
        // If no errors, update password
        if (empty($form_errors)) {
            try {
                $new_password_hash = hashPassword($new_password);
                
                $stmt = $pdo->prepare("
                    UPDATE " . TABLE_USERS . " 
                    SET password = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $result = $stmt->execute([$new_password_hash, $userId]);
                
                if ($result) {
                    // Log the password change
                    logAuditTrail(
                        $userId,
                        $userRole,
                        $_SESSION['username'],
                        'update',
                        'Changed account password',
                        TABLE_USERS,
                        $userId,
                        ['security_event' => 'password_change']
                    );
                    
                    $success_message = "Password changed successfully!";
                    setFlash('success', $success_message);
                    
                    // Clear password fields
                    $_POST['current_password'] = $_POST['new_password'] = $_POST['confirm_password'] = '';
                    
                    // Redirect to prevent form resubmission
                    header('Location: teacher_profile.php');
                    exit();
                } else {
                    $error_message = "Failed to change password. Please try again.";
                }
            } catch (PDOException $e) {
                error_log("Password Change Error: " . $e->getMessage());
                $error_message = "An error occurred while changing your password. Please try again.";
            }
        }
    }
}

// REMOVED: Profile update form handling - teachers cannot update personal info

// Get updated user data
$userData = getUserData();

// Set page title
$page_title = "My Profile - Emotion AI System";

// Log profile access
logAuditTrail(
    $userId,
    $userRole,
    $_SESSION['username'],
    'view',
    'Accessed profile page',
    null,
    null,
    ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']
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
        /* ==================== PROFILE PAGE STYLES ==================== */
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
        
        /* Sidebar Styles (Same as Dashboard) */
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
        
        /* User Menu Styles */
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
            font-size: 14px;
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
        
        /* Notification Button Styles */
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
        
        .content-wrapper {
            padding: 30px;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Profile Container */
        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .profile-header {
            margin-bottom: 40px;
        }
        
        .profile-header h1 {
            color: #1f2937;
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 10px;
        }
        
        .profile-header p {
            color: #6b7280;
            font-size: 16px;
        }
        
        /* Profile Grid */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        @media (max-width: 992px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: all 0.3s;
        }
        
        .profile-card:hover {
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .profile-card-header {
            padding: 25px 30px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .profile-card-header h3 {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .profile-card-content {
            padding: 30px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .form-control:disabled {
            background: #f9fafb;
            color: #6b7280;
            cursor: not-allowed;
        }
        
        .readonly-field {
            background: #f9fafb;
            color: #6b7280;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            min-height: 52px;
            display: flex;
            align-items: center;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        
        .input-with-icon .form-control {
            padding-left: 45px;
        }
        
        /* Info Notice */
        .info-notice {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .info-notice i {
            font-size: 24px;
            color: #f59e0b;
        }
        
        /* Error Messages */
        .error-message {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 1px solid #ef4444;
            color: #7f1d1d;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .error-message i {
            font-size: 24px;
            color: #ef4444;
        }
        
        .success-message {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border: 1px solid #10b981;
            color: #065f46;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .success-message i {
            font-size: 24px;
            color: #10b981;
        }
        
        .field-error {
            color: #ef4444;
            font-size: 12px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
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
            gap: 10px;
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
        
        /* Profile Info Display */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .info-item {
            padding: 20px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }
        
        .info-label {
            font-size: 12px;
            text-transform: uppercase;
            color: #6b7280;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            color: #1f2937;
            font-weight: 600;
        }
        
        /* Password Requirements */
        .password-requirements {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .password-requirements h4 {
            font-size: 14px;
            color: #374151;
            margin-bottom: 10px;
        }
        
        .password-requirements ul {
            list-style: none;
            padding: 0;
        }
        
        .password-requirements li {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .password-requirements li.valid {
            color: #10b981;
        }
        
        .password-requirements li.invalid {
            color: #ef4444;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
                transform: translateX(-100%);
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
            
            .content-wrapper {
                padding: 20px;
            }
            
            .profile-header h1 {
                font-size: 24px;
            }
            
            .topbar {
                padding: 0 20px;
                height: 70px;
            }
            
            .user-menu-btn span {
                display: none;
            }
            
            .user-menu-btn i.fa-chevron-down {
                display: none;
            }
        }
        
        /* Loading State */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }
        
        /* Password Toggle */
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            font-size: 18px;
        }
        
        .password-toggle:hover {
            color: #6b7280;
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

            <a href="teacher_announcement.php" class="menu-item">
                <i class="menu-icon fas fa-bullhorn"></i>
                <span>Announcement</span>
            </a>
            
            <div class="menu-title">Account</div>
            <a href="teacher_profile.php" class="menu-item active">
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
                <h2>My Profile</h2>
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
            <div class="profile-container">
                <!-- Flash Messages -->
                <?php 
                $flash = getFlash();
                if ($flash): ?>
                    <div class="<?php echo $flash['type'] === 'success' ? 'success-message' : 'error-message'; ?>">
                        <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                        <div><?php echo htmlspecialchars($flash['message']); ?></div>
                    </div>
                <?php endif; ?>
                
                <!-- Error Message -->
                <?php if ($error_message): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i>
                        <div>
                            <strong>Error:</strong> <?php echo $error_message; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="profile-header">
                    <h1>My Profile Information</h1>
                    <p>View your account details and change your password to maintain account security.</p>
                </div>
                
                <div class="profile-grid">
                    <!-- Profile Information Card -->
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <h3>
                                <i class="fas fa-user-circle"></i>
                                Profile Information
                            </h3>
                            <i class="fas fa-id-card" style="font-size: 24px; opacity: 0.8;"></i>
                        </div>
                        <div class="profile-card-content">
                            <!-- Information Notice -->
                            <div class="info-notice">
                                <i class="fas fa-info-circle"></i>
                                <div>
                                    <strong>Note:</strong> Personal information can only be updated by an administrator. 
                                    Please contact the system administrator if you need to update your details.
                                </div>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Username</div>
                                    <div class="info-value"><?php echo htmlspecialchars($userData['username'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">User ID</div>
                                    <div class="info-value">#<?php echo htmlspecialchars($userData['id'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Role</div>
                                    <div class="info-value">
                                        <span style="background: rgba(139, 92, 246, 0.1); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; color: #8b5cf6;">
                                            <?php echo ucfirst($userData['role'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Account Status</div>
                                    <div class="info-value">
                                        <?php if ($userData['is_active'] ?? 0): ?>
                                            <span style="background: rgba(16, 185, 129, 0.1); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; color: #10b981;">
                                                <i class="fas fa-check-circle"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span style="background: rgba(239, 68, 68, 0.1); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; color: #ef4444;">
                                                <i class="fas fa-times-circle"></i> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Display-only form fields -->
                            <div class="form-group">
                                <label for="full_name_display">Full Name</label>
                                <div class="readonly-field">
                                    <?php echo htmlspecialchars($userData['full_name'] ?? 'N/A'); ?>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="email_display">Institutional Email</label>
                                <div class="readonly-field">
                                    <?php echo htmlspecialchars($userData['email'] ?? 'N/A'); ?>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="personal_email_display">Personal Email</label>
                                <div class="readonly-field">
                                    <?php echo !empty($userData['personal_email']) ? htmlspecialchars($userData['personal_email']) : 'Not provided'; ?>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="contact_number_display">Contact Number</label>
                                <div class="readonly-field">
                                    <?php echo !empty($userData['contact_number']) ? htmlspecialchars($userData['contact_number']) : 'Not provided'; ?>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="department_display">Department</label>
                                <div class="readonly-field">
                                    <?php echo !empty($userData['department']) ? htmlspecialchars($userData['department']) : 'Not assigned'; ?>
                                </div>
                            </div>
                            
                            <div class="info-grid" style="margin-top: 25px;">
                                <div class="info-item">
                                    <div class="info-label">Account Created</div>
                                    <div class="info-value">
                                        <?php 
                                        if (isset($userData['created_at'])) {
                                            echo date('F j, Y', strtotime($userData['created_at']));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Last Profile Update</div>
                                    <div class="info-value">
                                        <?php 
                                        if (isset($userData['updated_at']) && $userData['updated_at'] != '0000-00-00 00:00:00') {
                                            echo date('F j, Y', strtotime($userData['updated_at']));
                                        } else {
                                            echo 'Never updated';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contact Admin Button -->
                            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <p style="color: #6b7280; margin-bottom: 15px;">
                                    Need to update your personal information? Contact the system administrator.
                                </p>
                                <a href="contact_admin.php" class="btn btn-primary">
                                    <i class="fas fa-envelope"></i>
                                    Contact Administrator
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Change Password Card -->
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <h3>
                                <i class="fas fa-lock"></i>
                                Change Password
                            </h3>
                            <i class="fas fa-shield-alt" style="font-size: 24px; opacity: 0.8;"></i>
                        </div>
                        <div class="profile-card-content">
                            <form method="POST" action="" id="passwordForm">
                                <?php echo csrfField(); ?>
                                
                                <div class="form-group">
                                    <label for="current_password">Current Password *</label>
                                    <div class="input-with-icon">
                                        <i class="input-icon fas fa-key"></i>
                                        <input type="password" 
                                               id="current_password" 
                                               name="current_password" 
                                               class="form-control" 
                                               required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <?php if (isset($form_errors['current_password'])): ?>
                                        <div class="field-error">
                                            <i class="fas fa-exclamation-circle"></i>
                                            <?php echo $form_errors['current_password']; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_password">New Password *</label>
                                    <div class="input-with-icon">
                                        <i class="input-icon fas fa-lock"></i>
                                        <input type="password" 
                                               id="new_password" 
                                               name="new_password" 
                                               class="form-control" 
                                               required
                                               oninput="validatePassword()">
                                        <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <?php if (isset($form_errors['new_password'])): ?>
                                        <div class="field-error">
                                            <i class="fas fa-exclamation-circle"></i>
                                            <?php echo $form_errors['new_password']; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="password-requirements">
                                        <h4>Password Requirements:</h4>
                                        <ul>
                                            <li id="req-length" class="invalid">
                                                <i class="fas fa-circle"></i> At least 8 characters
                                            </li>
                                            <li id="req-uppercase" class="invalid">
                                                <i class="fas fa-circle"></i> At least one uppercase letter
                                            </li>
                                            <li id="req-lowercase" class="invalid">
                                                <i class="fas fa-circle"></i> At least one lowercase letter
                                            </li>
                                            <li id="req-number" class="invalid">
                                                <i class="fas fa-circle"></i> At least one number
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password *</label>
                                    <div class="input-with-icon">
                                        <i class="input-icon fas fa-lock"></i>
                                        <input type="password" 
                                               id="confirm_password" 
                                               name="confirm_password" 
                                               class="form-control" 
                                               required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <?php if (isset($form_errors['confirm_password'])): ?>
                                        <div class="field-error">
                                            <i class="fas fa-exclamation-circle"></i>
                                            <?php echo $form_errors['confirm_password']; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div style="margin-top: 30px;">
                                    <button type="submit" name="change_password" class="btn btn-success">
                                        <i class="fas fa-key"></i>
                                        Change Password
                                    </button>
                                    <button type="reset" class="btn btn-secondary">
                                        <i class="fas fa-times"></i>
                                        Cancel
                                    </button>
                                </div>
                            </form>
                            
                            <!-- Security Tips -->
                            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <h4 style="color: #374151; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                                    <i class="fas fa-shield-alt" style="color: #8b5cf6;"></i>
                                    Security Tips
                                </h4>
                                <div style="font-size: 14px; color: #6b7280; line-height: 1.6;">
                                    <p>✓ Use a strong, unique password that you don't use elsewhere</p>
                                    <p>✓ Never share your password with anyone</p>
                                    <p>✓ Consider using a password manager</p>
                                    <p>✓ Change your password regularly</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Account Statistics -->
                <div class="profile-card" style="margin-top: 30px;">
                    <div class="profile-card-header">
                        <h3>
                            <i class="fas fa-chart-line"></i>
                            Account Statistics
                        </h3>
                        <i class="fas fa-chart-bar" style="font-size: 24px; opacity: 0.8;"></i>
                    </div>
                    <div class="profile-card-content">
                        <div class="info-grid">
                            <?php
                            // Get account statistics
                            try {
                                // Get total classes taught
                                $stmt = $pdo->prepare("
                                    SELECT COUNT(*) as total_classes 
                                    FROM " . TABLE_CLASSES . " 
                                    WHERE instructor_id = ? AND is_active = 1
                                ");
                                $stmt->execute([$userId]);
                                $classes = $stmt->fetch();
                                
                                // Get total sessions conducted
                                $stmt = $pdo->prepare("
                                    SELECT COUNT(*) as total_sessions 
                                    FROM " . TABLE_LIVE_SESSIONS . " ls
                                    JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
                                    WHERE c.instructor_id = ?
                                ");
                                $stmt->execute([$userId]);
                                $sessions = $stmt->fetch();
                                
                                // Get total students
                                $stmt = $pdo->prepare("
                                    SELECT COUNT(DISTINCT ce.student_id) as total_students 
                                    FROM " . TABLE_CLASS_ENROLLMENTS . " ce
                                    JOIN " . TABLE_CLASSES . " c ON ce.class_id = c.id
                                    WHERE c.instructor_id = ? AND c.is_active = 1
                                ");
                                $stmt->execute([$userId]);
                                $students = $stmt->fetch();
                                
                                // Get last login (simplified - you might want to add login tracking)
                                $last_login = date('Y-m-d H:i:s');
                            } catch (PDOException $e) {
                                error_log("Statistics Query Error: " . $e->getMessage());
                                $classes = ['total_classes' => 0];
                                $sessions = ['total_sessions' => 0];
                                $students = ['total_students' => 0];
                            }
                            ?>
                            
                            <div class="info-item">
                                <div class="info-label">Classes Created</div>
                                <div class="info-value" style="font-size: 24px; color: #8b5cf6;">
                                    <?php echo $classes['total_classes'] ?? 0; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Live Sessions</div>
                                <div class="info-value" style="font-size: 24px; color: #3b82f6;">
                                    <?php echo $sessions['total_sessions'] ?? 0; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Total Students</div>
                                <div class="info-value" style="font-size: 24px; color: #10b981;">
                                    <?php echo $students['total_students'] ?? 0; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Account Age</div>
                                <div class="info-value">
                                    <?php 
                                    if (isset($userData['created_at'])) {
                                        $created = new DateTime($userData['created_at']);
                                        $now = new DateTime();
                                        $interval = $now->diff($created);
                                        echo $interval->format('%y years, %m months, %d days');
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </div>
                            </div>
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
        
        if (userMenuBtn && userMenuDropdown) {
            userMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                userMenuDropdown.classList.toggle('show');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', () => {
                userMenuDropdown.classList.remove('show');
            });
            
            // Prevent dropdown from closing when clicking inside it
            userMenuDropdown.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }
        
        // Password toggle functionality
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggleBtn = field.nextElementSibling;
            const icon = toggleBtn.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Password validation
        function validatePassword() {
            const password = document.getElementById('new_password').value;
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password)
            };
            
            // Update UI for each requirement
            updateRequirement('req-length', requirements.length);
            updateRequirement('req-uppercase', requirements.uppercase);
            updateRequirement('req-lowercase', requirements.lowercase);
            updateRequirement('req-number', requirements.number);
            
            return Object.values(requirements).every(req => req);
        }
        
        function updateRequirement(elementId, isValid) {
            const element = document.getElementById(elementId);
            const icon = element.querySelector('i');
            
            if (isValid) {
                element.classList.remove('invalid');
                element.classList.add('valid');
                icon.classList.remove('fa-circle');
                icon.classList.add('fa-check-circle');
            } else {
                element.classList.remove('valid');
                element.classList.add('invalid');
                icon.classList.remove('fa-check-circle');
                icon.classList.add('fa-circle');
            }
        }
        
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (!currentPassword) {
                e.preventDefault();
                alert('Please enter your current password');
                document.getElementById('current_password').focus();
                return;
            }
            
            if (!newPassword) {
                e.preventDefault();
                alert('Please enter a new password');
                document.getElementById('new_password').focus();
                return;
            }
            
            if (!validatePassword()) {
                e.preventDefault();
                alert('Please make sure your new password meets all requirements');
                document.getElementById('new_password').focus();
                return;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New password and confirmation do not match');
                document.getElementById('confirm_password').focus();
                return;
            }
            
            // Confirm password change
            if (!confirm('Are you sure you want to change your password?')) {
                e.preventDefault();
                return;
            }
        });
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
        
        // Initialize password validation
        validatePassword();
        
        // Display keyboard shortcut hint
        console.log('Keyboard shortcuts available:\nCtrl+P: Focus on password change\nEsc: Close sidebar (mobile)');
    </script>
</body>
</html>