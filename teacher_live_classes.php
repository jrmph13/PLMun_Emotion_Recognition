<?php
// ==================== TEACHER LIVE CLASSES PAGE ====================
// Start session and load configuration
require_once 'config.php';

// Require instructor or admin role
requireInstructor();

// Get current user data
$userData = getUserData();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Handle actions
$action = $_GET['action'] ?? '';
$sessionId = $_GET['id'] ?? 0;
$classId = $_GET['class_id'] ?? 0;

// Handle session actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (validateCSRFToken($csrf_token)) {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'start_session') {
            // Start a new live session
            $class_id = $_POST['class_id'] ?? 0;
            $session_name = $_POST['session_name'] ?? 'Live Session';
            
            if ($class_id) {
                try {
                    // Get class details
                    $stmt = $pdo->prepare("SELECT class_name, class_code FROM " . TABLE_CLASSES . " WHERE id = ? AND instructor_id = ?");
                    $stmt->execute([$class_id, $userId]);
                    $class = $stmt->fetch();
                    
                    if ($class) {
                        // Create session name if not provided
                        if (empty($session_name) || $session_name === 'Live Session') {
                            $session_name = $class['class_name'] . ' - ' . date('M d, Y H:i');
                        }
                        
                        // Start new live session
                        $stmt = $pdo->prepare("INSERT INTO " . TABLE_LIVE_SESSIONS . " (class_id, session_name, start_time, status) VALUES (?, ?, NOW(), 'active')");
                        $stmt->execute([$class_id, $session_name]);
                        $newSessionId = $pdo->lastInsertId();
                        
                        // Log instructor as participant
                        $stmt = $pdo->prepare("INSERT INTO " . TABLE_LIVE_SESSION_PARTICIPANTS . " (session_id, user_id, user_role, join_time, camera_active, mic_active, is_active) VALUES (?, ?, 'instructor', NOW(), 1, 1, 1)");
                        $stmt->execute([$newSessionId, $userId]);
                        
                        // Log audit trail
                        logAuditTrail(
                            $userId,
                            $userRole,
                            $userData['username'],
                            'create',
                            "Started live session: {$session_name}",
                            TABLE_LIVE_SESSIONS,
                            $newSessionId,
                            ['class_id' => $class_id, 'session_name' => $session_name]
                        );
                        
                        setFlash('success', "Live session started successfully!");
                        header("Location: teacher_start_session.php?session_id={$newSessionId}&class_id={$class_id}");
                        exit();
                    } else {
                        setFlash('error', "Class not found or access denied.");
                    }
                } catch (PDOException $e) {
                    error_log("Start Session Error: " . $e->getMessage());
                    setFlash('error', "Failed to start session. Please try again.");
                }
            }
        } elseif ($action === 'end_session') {
            // End a live session
            $session_id = $_POST['session_id'] ?? 0;
            
            if ($session_id) {
                try {
                    // Verify session belongs to instructor
                    $stmt = $pdo->prepare("
                        SELECT ls.*, c.class_name 
                        FROM " . TABLE_LIVE_SESSIONS . " ls
                        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
                        WHERE ls.id = ? AND c.instructor_id = ?
                    ");
                    $stmt->execute([$session_id, $userId]);
                    $session = $stmt->fetch();
                    
                    if ($session && $session['status'] === 'active') {
                        // End the session
                        $stmt = $pdo->prepare("UPDATE " . TABLE_LIVE_SESSIONS . " SET status = 'ended', end_time = NOW() WHERE id = ?");
                        $stmt->execute([$session_id]);
                        
                        // Update all active participants
                        $stmt = $pdo->prepare("UPDATE " . TABLE_LIVE_SESSION_PARTICIPANTS . " SET is_active = 0, leave_time = NOW() WHERE session_id = ? AND is_active = 1");
                        $stmt->execute([$session_id]);
                        
                        // Log audit trail
                        logAuditTrail(
                            $userId,
                            $userRole,
                            $userData['username'],
                            'update',
                            "Ended live session: {$session['session_name']}",
                            TABLE_LIVE_SESSIONS,
                            $session_id,
                            ['duration' => 'Session ended']
                        );
                        
                        setFlash('success', "Live session ended successfully!");
                        header("Location: teacher_live_classes.php");
                        exit();
                    } else {
                        setFlash('error', "Session not found or already ended.");
                    }
                } catch (PDOException $e) {
                    error_log("End Session Error: " . $e->getMessage());
                    setFlash('error', "Failed to end session. Please try again.");
                }
            }
        }
    } else {
        setFlash('error', "Invalid security token. Please try again.");
    }
}

// Get instructor's active classes for starting sessions
try {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               COUNT(DISTINCT ce.student_id) as student_count,
               (SELECT COUNT(*) FROM " . TABLE_LIVE_SESSIONS . " WHERE class_id = c.id AND status = 'active') as active_sessions
        FROM " . TABLE_CLASSES . " c
        LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
        WHERE c.instructor_id = ? AND c.is_active = 1
        GROUP BY c.id
        ORDER BY c.class_name
    ");
    $stmt->execute([$userId]);
    $instructor_classes = $stmt->fetchAll();

    // FIXED: Get session history with proper duration calculation
    $stmt = $pdo->prepare("
        SELECT ls.*, 
               c.class_name, 
               c.class_code,
               COUNT(DISTINCT sa.student_id) as student_count,
               -- FIXED: Calculate duration properly for both ended and active sessions
               CASE 
                   WHEN ls.status = 'ended' AND ls.end_time IS NOT NULL 
                   THEN TIMESTAMPDIFF(SECOND, ls.start_time, ls.end_time)
                   WHEN ls.status = 'active' 
                   THEN TIMESTAMPDIFF(SECOND, ls.start_time, NOW())
                   ELSE 0 
               END as duration_seconds
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id
        WHERE c.instructor_id = ? AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY ls.id
        ORDER BY ls.start_time DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $session_history = $stmt->fetchAll();

    // Get quick stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT ls.id) as total_sessions_30d,
            COUNT(DISTINCT CASE WHEN ls.status = 'active' THEN ls.id END) as active_sessions,
            COUNT(DISTINCT CASE WHEN ls.status = 'ended' AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN ls.id END) as sessions_last_week
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        WHERE c.instructor_id = ? AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([$userId]);
    $session_stats = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Live Classes Query Error: " . $e->getMessage());
    $error_message = "An error occurred while loading live classes data. Please try again later.";
    // Initialize empty arrays
    $instructor_classes = [];
    $session_history = [];
    $session_stats = ['total_sessions_30d' => 0, 'active_sessions' => 0, 'sessions_last_week' => 0];
}

// Set page title
$page_title = "Live Classes - Emotion AI System";

// Log page access for audit trail
logAuditTrail(
    $_SESSION['user_id'],
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    'Accessed live classes page',
    null,
    null,
    ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']
);

// Helper function for getting initials
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

// NEW: Helper function to format duration
function formatDuration($seconds) {
    if (!$seconds || $seconds <= 0) return '00:00';
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $remainingSeconds = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    } else {
        return sprintf('%02d:%02d', $minutes, $remainingSeconds);
    }
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
        /* Reuse the dashboard styles */
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
        
        /* User Menu Styles */
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
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Section Cards */
        .section-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            margin-bottom: 30px;
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
            font-weight: 700;
            font-size: 22px;
        }
        
        /* Classes Grid for Starting Sessions */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        /* SCROLLABLE CLASSES GRID */
        .classes-grid.scrollable {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 15px;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
            margin-top: 20px;
        }
        
        .class-card {
            background: #f8fafc;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            height: 100%;
        }
        
        .class-card:hover {
            border-color: #8b5cf6;
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.1);
        }
        
        .class-card.active {
            border-color: #10b981;
        }
        
        .class-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-bottom: 12px;
        }
        
        .class-name {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 6px;
            line-height: 1.3;
        }
        
        .class-code {
            font-family: monospace;
            font-size: 12px;
            color: #8b5cf6;
            font-weight: 600;
            margin-bottom: 12px;
            background: #f3f4f6;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        
        .class-details {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
        }
        
        .detail-item {
            text-align: center;
            flex: 1;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
        }
        
        .detail-label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }
        
        /* Session Cards */
        .session-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 12px;
            transition: all 0.3s;
        }
        
        .session-card:hover {
            border-color: #3b82f6;
            box-shadow: 0 5px 20px rgba(59, 130, 246, 0.1);
        }
        
        .session-card.active {
            border-color: #10b981;
            background: #f0fdf4;
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        
        .session-title {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        
        .session-class {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.4;
        }
        
        .session-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            margin-left: 10px;
        }
        
        .status-active {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .status-ended {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #6b7280;
        }
        
        .session-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f3f4f6;
        }
        
        .session-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .session-info i {
            color: #8b5cf6;
            font-size: 14px;
            width: 18px;
            flex-shrink: 0;
        }
        
        .session-info-content {
            flex: 1;
        }
        
        .session-info-label {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 2px;
            font-weight: 500;
        }
        
        .session-info-value {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .session-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        
        /* Buttons */
        .btn {
            padding: 8px 15px;
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
            height: 36px;
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
            border: 2px solid #e5e7eb;
            color: #4b5563;
        }
        
        .btn-outline:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
        }
        
        /* SCROLLABLE CONTAINERS */
        .session-history-container {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 20px;
        }
        
        /* Custom scrollbar styles */
        .classes-grid.scrollable::-webkit-scrollbar,
        .session-history-container::-webkit-scrollbar {
            width: 8px;
        }
        
        .classes-grid.scrollable::-webkit-scrollbar-track,
        .session-history-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .classes-grid.scrollable::-webkit-scrollbar-thumb,
        .session-history-container::-webkit-scrollbar-thumb {
            background: #c7d2fe;
            border-radius: 4px;
        }
        
        .classes-grid.scrollable::-webkit-scrollbar-thumb:hover,
        .session-history-container::-webkit-scrollbar-thumb:hover {
            background: #a5b4fc;
        }
        
        /* Firefox scrollbar */
        .classes-grid.scrollable,
        .session-history-container {
            scrollbar-width: thin;
            scrollbar-color: #c7d2fe #f1f1f1;
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
            animation: modalSlide 0.3s ease;
        }
        
        @keyframes modalSlide {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            padding: 25px 30px;
            border-bottom: 2px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            font-size: 20px;
            color: #1f2937;
            font-weight: 700;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #6b7280;
            cursor: pointer;
            padding: 5px;
            border-radius: 6px;
        }
        
        .modal-close:hover {
            background: #f3f4f6;
            color: #374151;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
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
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #e5e7eb;
            margin-bottom: 15px;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: #4b5563;
            font-size: 18px;
        }
        
        .empty-state p {
            font-size: 14px;
            color: #9ca3af;
            max-width: 300px;
            margin: 0 auto;
        }
        
        /* Flash Messages */
        .flash-message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .flash-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        
        .flash-error {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 1px solid #fca5a5;
            color: #7f1d1d;
        }
        
        /* Session Duration */
        .session-duration {
            font-family: monospace;
            font-size: 13px;
            color: #374151;
            font-weight: 600;
            background: #f8fafc;
            padding: 6px 10px;
            border-radius: 6px;
            display: inline-block;
            border: 1px solid #e5e7eb;
        }
        
        /* Table Styles for Session History */
        .session-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .session-table th {
            background: #f8fafc;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 10;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .session-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e5e7eb;
            transition: background 0.3s;
            font-size: 14px;
        }
        
        .session-table tr:hover {
            background: #f8fafc;
        }
        
        /* Compact table cells */
        .compact-cell {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }
        
        /* Student Count Badge */
        .student-count {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #f0f9ff;
            border-radius: 20px;
            font-weight: 600;
            color: #0369a1;
            font-size: 13px;
        }
        
        .student-count i {
            font-size: 12px;
        }
        
        /* Session Actions - Reduced width */
        .session-actions-compact {
            display: flex;
            gap: 8px;
        }
        
        .session-actions-compact .btn {
            padding: 6px 10px;
            font-size: 12px;
            height: 32px;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .classes-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
        }
        
        @media (max-width: 992px) {
            .sidebar {
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
            
            .section-card {
                padding: 20px;
            }
            
            .classes-grid.scrollable {
                max-height: 400px;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
            
            .classes-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
            
            .session-history-container {
                max-height: 350px;
            }
            
            .session-actions-compact {
                flex-direction: column;
                gap: 6px;
            }
            
            .session-actions-compact .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .classes-grid.scrollable,
            .classes-grid {
                grid-template-columns: 1fr;
                max-height: 400px;
            }
            
            .session-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .session-status {
                margin-left: 0;
                align-self: flex-start;
            }
            
            .session-history-container {
                max-height: 300px;
            }
            
            .session-table th,
            .session-table td {
                padding: 10px;
                font-size: 12px;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .btn {
                font-size: 12px;
                padding: 8px 12px;
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
            <a href="teacher_live_classes.php" class="menu-item active">
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
        <!-- Topbar with User Menu -->
        <div class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Live Classes</h2>
            </div>
            
            <div class="topbar-right">
                <!-- User Menu -->
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
            <!-- Flash Messages -->
            <?php 
            $flash = getFlash();
            if ($flash): ?>
                <div class="flash-message flash-<?php echo $flash['type']; ?>">
                    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <span><?php echo htmlspecialchars($flash['message']); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Start New Session Section -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Start New Session</h2>
                    <div>
                        <span class="session-status status-active" style="margin-right: 15px;">
                            <?php echo $session_stats['active_sessions'] ?? 0; ?> Active
                        </span>
                        <button class="btn btn-primary" onclick="refreshClasses()">
                            <i class="fas fa-sync-alt"></i>
                            Refresh
                        </button>
                    </div>
                </div>
                
                <?php if (empty($instructor_classes)): ?>
                    <div class="empty-state">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <h3>No Active Classes</h3>
                        <p>Create a class first to start live sessions</p>
                        <a href="create_class.php" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-plus"></i>
                            Create Class
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Apply scrollable class if more than 6 classes -->
                    <div class="classes-grid <?php echo count($instructor_classes) > 6 ? 'scrollable' : ''; ?>">
                        <?php foreach ($instructor_classes as $class): ?>
                            <div class="class-card <?php echo ($class['active_sessions'] > 0) ? 'active' : ''; ?>">
                                <div class="class-icon">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </div>
                                
                                <div class="class-name"><?php echo htmlspecialchars($class['class_name']); ?></div>
                                <div class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></div>
                                
                                <?php if ($class['description']): ?>
                                    <p style="color: #6b7280; font-size: 12px; margin-bottom: 12px; line-height: 1.4;">
                                        <?php echo htmlspecialchars(substr($class['description'], 0, 80)); ?>
                                        <?php if (strlen($class['description']) > 80): ?>...<?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                
                                <div class="class-details">
                                    <div class="detail-item">
                                        <div class="detail-value"><?php echo $class['student_count'] ?? 0; ?></div>
                                        <div class="detail-label">Students</div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-value"><?php echo $class['active_sessions'] ?? 0; ?></div>
                                        <div class="detail-label">Active</div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-value">
                                            <?php if ($class['emotion_tracking']): ?>
                                                <i class="fas fa-check" style="color: #10b981;"></i>
                                            <?php else: ?>
                                                <i class="fas fa-times" style="color: #ef4444;"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="detail-label">Tracking</div>
                                    </div>
                                </div>
                                
                                <!-- Buttons section with Details button -->
                                <div style="margin-top: 15px; display: flex; flex-direction: column; gap: 8px;">
                                    <?php if ($class['active_sessions'] > 0): ?>
                                        <?php
                                        $activeSessionId = getActiveSessionId($class['id']);
                                        ?>
                                        <a href="teacher_start_session.php?session_id=<?php echo $activeSessionId; ?>&class_id=<?php echo $class['id']; ?>" class="btn btn-success" style="width: 100%; text-align: center;">
                                            <i class="fas fa-play"></i>
                                            Join Session
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-primary" style="width: 100%;" onclick="openStartSessionModal(<?php echo $class['id']; ?>, '<?php echo htmlspecialchars($class['class_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($class['class_code'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-play-circle"></i>
                                            Start Session
                                        </button>
                                    <?php endif; ?>
                                    
                                    <!-- DETAILS BUTTON -->
                                    <button class="btn btn-outline" style="width: 100%;" onclick="openClassDetailsModal(<?php echo $class['id']; ?>, '<?php echo htmlspecialchars($class['class_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($class['class_code'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-info-circle"></i>
                                        Details
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Session History Section -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Session History (Last 30 Days)</h2>
                    <div>
                        <span style="margin-right: 15px; color: #6b7280; font-size: 14px;">
                            <i class="fas fa-history"></i>
                            <?php echo $session_stats['total_sessions_30d'] ?? 0; ?> sessions
                        </span>
                        <a href="reports.php" class="btn btn-outline">
                            <i class="fas fa-chart-line"></i>
                            View All Reports
                        </a>
                    </div>
                </div>
                
                <?php if (empty($session_history)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No Session History</h3>
                        <p>Your completed sessions will appear here</p>
                    </div>
                <?php else: ?>
                    <!-- Apply scrollable container if more than 5 history items -->
                    <div class="session-history-container <?php echo count($session_history) > 5 ? 'scrollable' : ''; ?>">
                        <table class="session-table">
                            <thead>
                                <tr>
                                    <th>Session</th>
                                    <th>Class</th>
                                    <th>Date & Time</th>
                                    <th>Duration</th>
                                    <th>Students</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($session_history as $session): ?>
                                    <tr>
                                        <td style="min-width: 150px;">
                                            <div style="font-weight: 600; color: #1f2937; font-size: 13px;">
                                                <?php echo htmlspecialchars($session['session_name']); ?>
                                            </div>
                                        </td>
                                        <td style="min-width: 120px;">
                                            <div style="color: #6b7280; font-size: 13px;">
                                                <?php echo htmlspecialchars($session['class_name']); ?>
                                            </div>
                                            <div style="font-size: 11px; color: #8b5cf6;">
                                                <?php echo htmlspecialchars($session['class_code']); ?>
                                            </div>
                                        </td>
                                        <td style="min-width: 120px;">
                                            <div style="color: #1f2937; font-weight: 500; font-size: 13px;">
                                                <?php echo formatDate($session['start_time'], 'M d'); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #6b7280;">
                                                <?php echo formatDate($session['start_time'], 'h:i A'); ?>
                                            </div>
                                        </td>
                                        <td style="min-width: 80px;">
                                            <!-- FIXED: Use the new formatDuration function -->
                                            <div class="session-duration">
                                                <?php echo formatDuration($session['duration_seconds']); ?>
                                            </div>
                                        </td>
                                        <td style="min-width: 100px;">
                                            <div class="student-count">
                                                <i class="fas fa-user-check"></i>
                                                <span style="font-weight: 600;">
                                                    <?php echo $session['student_count']; ?>
                                                </span>
                                                <span style="font-size: 11px;">joined</span>
                                            </div>
                                        </td>
                                        <td style="min-width: 80px;">
                                            <?php if ($session['status'] === 'active'): ?>
                                                <span class="session-status status-active">Active</span>
                                            <?php else: ?>
                                                <span class="session-status status-ended">Ended</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (count($session_history) >= 50): ?>
                        <div style="text-align: center; margin-top: 20px;">
                            <a href="reports.php" class="btn btn-primary">
                                <i class="fas fa-history"></i>
                                View Complete History
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Start Session Modal -->
    <div class="modal" id="startSessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Start Live Session</h3>
                <button class="modal-close" onclick="closeStartSessionModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="startSessionForm" method="POST" action="teacher_live_classes.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="start_session">
                    <input type="hidden" id="modalClassId" name="class_id">
                    
                    <div class="form-group">
                        <label for="sessionName">Session Name</label>
                        <input type="text" id="sessionName" name="session_name" class="form-control" 
                               placeholder="Enter session name (optional)">
                        <small style="color: #6b7280; font-size: 12px; margin-top: 5px; display: block;">
                            Leave blank to use default: [Class Name] - [Date Time]
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label>Class Information</label>
                        <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 5px;">
                            <div style="font-weight: 600; color: #1f2937;" id="modalClassName"></div>
                            <div style="font-size: 14px; color: #6b7280; margin-top: 5px;" id="modalClassCode"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Session Settings</label>
                        <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 5px;">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                <i class="fas fa-camera" style="color: #8b5cf6;"></i>
                                <span>Emotion tracking will be enabled</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-user-check" style="color: #8b5cf6;"></i>
                                <span>Automatic attendance will be recorded</span>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 30px;">
                        <button type="button" class="btn btn-outline" style="flex: 1;" onclick="closeStartSessionModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-play-circle"></i>
                            Start Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- End Session Confirmation Modal -->
    <div class="modal" id="endSessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>End Live Session</h3>
                <button class="modal-close" onclick="closeEndSessionModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="endSessionForm" method="POST" action="teacher_live_classes.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="end_session">
                    <input type="hidden" id="endSessionId" name="session_id">
                    
                    <div style="text-align: center; margin-bottom: 30px;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f59e0b; margin-bottom: 15px;"></i>
                        <h3 style="color: #1f2937; margin-bottom: 10px;">Are you sure?</h3>
                        <p style="color: #6b7280;" id="endSessionMessage">
                            You're about to end the live session. This will stop emotion tracking and attendance recording.
                        </p>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn btn-outline" style="flex: 1;" onclick="closeEndSessionModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-danger" style="flex: 1;">
                            <i class="fas fa-stop"></i>
                            End Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Class Details Modal -->
    <div class="modal" id="classDetailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Class Details</h3>
                <button class="modal-close" onclick="closeClassDetailsModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #1f2937; margin-bottom: 10px;" id="detailsClassName"></h4>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                        <span style="background: #f3f4f6; padding: 4px 10px; border-radius: 6px; font-family: monospace; font-weight: 600;" id="detailsClassCode"></span>
                        <span style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600;">
                            <i class="fas fa-users"></i>
                            <span id="detailsStudentCount">0</span> students
                        </span>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <h5 style="color: #374151; margin-bottom: 15px; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px;">
                        <i class="fas fa-user-graduate"></i> Enrolled Students
                    </h5>
                    <div id="studentsList" style="max-height: 300px; overflow-y: auto; padding-right: 10px;">
                        <div class="empty-state" id="loadingStudents">
                            <i class="fas fa-spinner fa-spin"></i>
                            <p>Loading students...</p>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn btn-outline" style="flex: 1;" onclick="closeClassDetailsModal()">
                        Close
                    </button>
                    <a href="teacher_my_classes.php?class_id=" id="manageClassBtn" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-cog"></i>
                        Manage Class
                    </a>
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
        
        // Close dropdown when clicking outside
        document.addEventListener('click', () => {
            userMenuDropdown.classList.remove('show');
        });
        
        // Start Session Modal
        function openStartSessionModal(classId, className, classCode) {
            document.getElementById('modalClassId').value = classId;
            document.getElementById('modalClassName').textContent = className;
            document.getElementById('modalClassCode').textContent = `Class Code: ${classCode}`;
            document.getElementById('sessionName').value = `${className} - ${new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}`;
            
            document.getElementById('startSessionModal').classList.add('active');
        }
        
        function closeStartSessionModal() {
            document.getElementById('startSessionModal').classList.remove('active');
        }
        
        // End Session Modal
        function confirmEndSession(sessionId, sessionName) {
            document.getElementById('endSessionId').value = sessionId;
            document.getElementById('endSessionMessage').innerHTML = `
                You're about to end the live session: <strong>${sessionName}</strong>.<br><br>
                This will stop emotion tracking and attendance recording.
            `;
            document.getElementById('endSessionModal').classList.add('active');
        }
        
        function closeEndSessionModal() {
            document.getElementById('endSessionModal').classList.remove('active');
        }
        
        // Class Details Modal Functions
        function openClassDetailsModal(classId, className, classCode) {
            document.getElementById('detailsClassName').textContent = className;
            document.getElementById('detailsClassCode').textContent = classCode;
            
            // Update manage class link
            document.getElementById('manageClassBtn').href = `teacher_my_classes.php?class_id=${classId}`;
            
            // Show loading state
            document.getElementById('loadingStudents').style.display = 'block';
            document.getElementById('studentsList').innerHTML = `
                <div class="empty-state" id="loadingStudents">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading students...</p>
                </div>
            `;
            
            // Fetch students data via AJAX
            fetchClassStudents(classId);
            
            // Show modal
            document.getElementById('classDetailsModal').classList.add('active');
        }
        
        function closeClassDetailsModal() {
            document.getElementById('classDetailsModal').classList.remove('active');
        }
        
        function fetchClassStudents(classId) {
            // Create AJAX request to get enrolled students
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'ajax_get_class_students.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const response = JSON.parse(this.responseText);
                        
                        if (response.success) {
                            // Update student count
                            document.getElementById('detailsStudentCount').textContent = response.student_count || 0;
                            
                            // Display students list
                            const studentsList = document.getElementById('studentsList');
                            
                            if (response.students && response.students.length > 0) {
                                let studentsHtml = '<div style="display: grid; gap: 10px;">';
                                
                                response.students.forEach(student => {
                                    studentsHtml += `
                                        <div style="background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 16px;">
                                                ${student.initials}
                                            </div>
                                            <div style="flex: 1;">
                                                <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">
                                                    ${student.full_name}
                                                </div>
                                                <div style="display: flex; gap: 10px; font-size: 12px; color: #6b7280;">
                                                    <span><i class="fas fa-id-card"></i> ${student.student_number}</span>
                                                    <span><i class="fas fa-graduation-cap"></i> ${student.course}</span>
                                                    <span><i class="fas fa-calendar"></i> ${student.year_level}</span>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                });
                                
                                studentsHtml += '</div>';
                                studentsList.innerHTML = studentsHtml;
                            } else {
                                studentsList.innerHTML = `
                                    <div class="empty-state">
                                        <i class="fas fa-user-slash"></i>
                                        <h4>No Students Enrolled</h4>
                                        <p>No students have joined this class yet.</p>
                                    </div>
                                `;
                            }
                        } else {
                            studentsList.innerHTML = `
                                <div class="empty-state">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <h4>Error Loading Students</h4>
                                    <p>${response.message || 'Failed to load student list'}</p>
                                </div>
                            `;
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                        document.getElementById('studentsList').innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-exclamation-circle"></i>
                                <h4>Error Loading Students</h4>
                                <p>Please try again later.</p>
                            </div>
                        `;
                    }
                } else {
                    document.getElementById('studentsList').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-circle"></i>
                            <h4>Network Error</h4>
                            <p>Failed to load student data. Please check your connection.</p>
                        </div>
                    `;
                }
            };
            
            xhr.onerror = function() {
                document.getElementById('studentsList').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <h4>Network Error</h4>
                        <p>Failed to load student data. Please check your connection.</p>
                    </div>
                `;
            };
            
            // Send the request with class ID
            xhr.send(`class_id=${classId}&action=get_students`);
        }
        
        // Refresh classes
        function refreshClasses() {
            window.location.reload();
        }
        
        // Close modals on outside click
        window.addEventListener('click', (event) => {
            const startModal = document.getElementById('startSessionModal');
            const endModal = document.getElementById('endSessionModal');
            const detailsModal = document.getElementById('classDetailsModal');
            
            if (event.target === startModal) {
                closeStartSessionModal();
            }
            if (event.target === endModal) {
                closeEndSessionModal();
            }
            if (event.target === detailsModal) {
                closeClassDetailsModal();
            }
            
            // Close user menu dropdown when clicking outside
            if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) {
                userMenuDropdown.classList.remove('show');
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Escape to close modals
            if (e.key === 'Escape') {
                closeStartSessionModal();
                closeEndSessionModal();
                closeClassDetailsModal();
                userMenuDropdown.classList.remove('show');
            }
            
            // Ctrl+R to refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                refreshClasses();
            }
            
            // Ctrl+N to start new session (if classes available)
            if (e.ctrlKey && e.key === 'n' && <?php echo !empty($instructor_classes) ? 'true' : 'false'; ?>) {
                e.preventDefault();
                const firstClass = document.querySelector('.class-card');
                if (firstClass) {
                    const classId = firstClass.querySelector('button')?.getAttribute('onclick')?.match(/\d+/)?.[0];
                    const className = firstClass.querySelector('.class-name')?.textContent;
                    const classCode = firstClass.querySelector('.class-code')?.textContent;
                    if (classId && className && classCode) {
                        openStartSessionModal(parseInt(classId), className, classCode);
                    }
                }
            }
            
            // Ctrl+U to toggle user menu
            if (e.ctrlKey && e.key === 'u') {
                e.preventDefault();
                userMenuDropdown.classList.toggle('show');
            }
            
            // Ctrl+D to open details for first class
            if (e.ctrlKey && e.key === 'd' && <?php echo !empty($instructor_classes) ? 'true' : 'false'; ?>) {
                e.preventDefault();
                const firstClass = document.querySelector('.class-card');
                if (firstClass) {
                    const classId = firstClass.querySelector('.btn-outline')?.getAttribute('onclick')?.match(/\d+/)?.[0];
                    const className = firstClass.querySelector('.class-name')?.textContent;
                    const classCode = firstClass.querySelector('.class-code')?.textContent;
                    if (classId && className && classCode) {
                        openClassDetailsModal(parseInt(classId), className, classCode);
                    }
                }
            }
        });
        
        // NEW: Auto-update active sessions duration every 10 seconds
        function updateActiveSessionDurations() {
            const durationCells = document.querySelectorAll('.session-duration');
            durationCells.forEach(cell => {
                const row = cell.closest('tr');
                if (row) {
                    const statusCell = row.querySelector('.session-status.status-active');
                    if (statusCell) {
                        // This is an active session, update duration
                        const durationText = cell.textContent;
                        if (durationText) {
                            const parts = durationText.split(':');
                            if (parts.length === 2) {
                                let minutes = parseInt(parts[0]);
                                let seconds = parseInt(parts[1]);
                                
                                // Add 10 seconds
                                seconds += 10;
                                if (seconds >= 60) {
                                    minutes += 1;
                                    seconds -= 60;
                                }
                                
                                // Update the cell
                                cell.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                            }
                        }
                    }
                }
            });
        }
        
        // Run duration update every 10 seconds
        setInterval(updateActiveSessionDurations, 10000);
        
        // Auto-refresh every 30 seconds to check for new active sessions
        setInterval(() => {
            const activeClasses = document.querySelectorAll('.class-card.active');
            if (activeClasses.length > 0) {
                // Refresh the page to get updated session data
                location.reload();
            }
        }, 30000); // 30 seconds
        
        // Display keyboard shortcut hints
        console.log('Keyboard shortcuts available:\n' +
                   'Ctrl+M: Toggle sidebar\n' +
                   'Ctrl+R: Refresh classes\n' +
                   'Ctrl+N: Start new session\n' +
                   'Ctrl+D: Open class details\n' +
                   'Ctrl+U: Toggle user menu\n' +
                   'Esc: Close modals');
    </script>
</body>
</html>

<?php
// Helper function to get active session ID for a class
function getActiveSessionId($classId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id FROM " . TABLE_LIVE_SESSIONS . " WHERE class_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$classId]);
        $session = $stmt->fetch();
        return $session ? $session['id'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}
?>