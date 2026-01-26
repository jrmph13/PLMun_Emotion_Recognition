<?php
// ==================== TEACHER EMOTION MONITORING PAGE ====================
// Start session and load configuration
require_once 'config.php';

// Require instructor or admin role
requireInstructor();

// Get current user data
$userData = getUserData();
$userId = $_SESSION['user_id'];

// Get active live sessions
try {
    // Get active sessions for this instructor
    $stmt = $pdo->prepare("
        SELECT ls.*, c.class_name, c.class_code, 
               COUNT(DISTINCT lsp.user_id) as participant_count
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        LEFT JOIN " . TABLE_LIVE_SESSION_PARTICIPANTS . " lsp ON ls.id = lsp.session_id
        WHERE c.instructor_id = ? 
        AND ls.status = 'active'
        GROUP BY ls.id
        ORDER BY ls.start_time DESC
    ");
    $stmt->execute([$userId]);
    $active_sessions = $stmt->fetchAll();

    // Get recent emotion data for active sessions
    $recent_emotions = [];
    $session_participants = [];
    $session_stats = [];
    
    foreach ($active_sessions as $session) {
        // Get participants for this session WITH student_id
        $stmt = $pdo->prepare("
            SELECT 
                lsp.user_id,
                u.full_name,
                s.id as student_id,
                s.student_number,
                lsp.join_time,
                lsp.camera_active,
                lsp.mic_active
            FROM " . TABLE_LIVE_SESSION_PARTICIPANTS . " lsp
            JOIN " . TABLE_USERS . " u ON lsp.user_id = u.id
            LEFT JOIN " . TABLE_STUDENTS . " s ON u.id = s.user_id
            WHERE lsp.session_id = ? 
            AND lsp.user_role = 'student'
            AND lsp.is_active = 1
        ");
        $stmt->execute([$session['id']]);
        $session_participants[$session['id']] = $stmt->fetchAll();

        // Get recent emotion data for each participant
        foreach ($session_participants[$session['id']] as $participant) {
            if (!empty($participant['student_id'])) {
                $stmt = $pdo->prepare("
                    SELECT 
                        ed.facial_emotion,
                        ed.confidence_score,
                        ed.engagement_level,
                        ed.captured_at
                    FROM " . TABLE_EMOTION_DATA . " ed
                    WHERE ed.session_id = ? 
                    AND ed.student_id = ?
                    ORDER BY ed.captured_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$session['id'], $participant['student_id']]);
                $emotion_data = $stmt->fetch();
                
                if ($emotion_data) {
                    $recent_emotions[$session['id']][$participant['user_id']] = $emotion_data;
                }
            }
        }

        // Get session statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT ed.id) as total_readings,
                COALESCE(AVG(ed.engagement_level), 0) as avg_engagement,
                COUNT(CASE WHEN ed.facial_emotion = 'happy' THEN 1 END) as happy_count,
                COUNT(CASE WHEN ed.facial_emotion = 'bored' THEN 1 END) as bored_count,
                COUNT(CASE WHEN ed.facial_emotion = 'neutral' THEN 1 END) as neutral_count,
                COUNT(CASE WHEN ed.facial_emotion = 'sad' THEN 1 END) as sad_count,
                COUNT(CASE WHEN ed.facial_emotion = 'angry' THEN 1 END) as angry_count
            FROM " . TABLE_EMOTION_DATA . " ed
            WHERE ed.session_id = ?
            AND ed.captured_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([$session['id']]);
        $session_stats[$session['id']] = $stmt->fetch();

        // Ensure all stats have default values
        if ($session_stats[$session['id']]) {
            $session_stats[$session['id']]['total_readings'] = $session_stats[$session['id']]['total_readings'] ?? 0;
            $session_stats[$session['id']]['avg_engagement'] = $session_stats[$session['id']]['avg_engagement'] ?? 0;
            $session_stats[$session['id']]['happy_count'] = $session_stats[$session['id']]['happy_count'] ?? 0;
            $session_stats[$session['id']]['bored_count'] = $session_stats[$session['id']]['bored_count'] ?? 0;
            $session_stats[$session['id']]['neutral_count'] = $session_stats[$session['id']]['neutral_count'] ?? 0;
            $session_stats[$session['id']]['sad_count'] = $session_stats[$session['id']]['sad_count'] ?? 0;
            $session_stats[$session['id']]['angry_count'] = $session_stats[$session['id']]['angry_count'] ?? 0;
        }
    }

    // Get instructor's classes for session selection
    $stmt = $pdo->prepare("
        SELECT c.* 
        FROM " . TABLE_CLASSES . " c
        WHERE c.instructor_id = ? 
        AND c.is_active = 1
        ORDER BY c.class_name
    ");
    $stmt->execute([$userId]);
    $instructor_classes = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Emotion Monitoring Query Error: " . $e->getMessage());
    $error_message = "An error occurred while loading emotion monitoring data. Please try again later.";
    $active_sessions = [];
    $session_participants = [];
    $recent_emotions = [];
    $session_stats = [];
    $instructor_classes = [];
}

// Set page title
$page_title = "Emotion Monitoring - Emotion AI System";

// Log access for audit trail
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && isset($_SESSION['username'])) {
    logAuditTrail(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['username'],
        'view',
        'Accessed emotion monitoring page',
        null,
        null,
        ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']
    );
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
            background: rgba(255, 255, 255, 0.95);
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
        
        /* Emotion Monitoring Layout */
        .monitoring-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }
        
        .main-section {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }
        
        .monitoring-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
        }
        
        .monitoring-card h2 {
            color: #1f2937;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            font-weight: 700;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Session Selector */
        .session-selector {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .session-selector h3 {
            color: #1e293b;
            margin-bottom: 15px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* FIX 1: Added scrollbar for session grid when there are 3+ items */
        .session-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .session-grid.scrollable {
            max-height: 350px; /* Adjust this value as needed */
            overflow-y: auto;
            padding-right: 10px;
        }
        
        /* Custom scrollbar for session grid */
        .session-grid.scrollable::-webkit-scrollbar {
            width: 6px;
        }
        
        .session-grid.scrollable::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .session-grid.scrollable::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .session-grid.scrollable::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
        
        .session-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .session-card:hover {
            border-color: #8b5cf6;
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(139, 92, 246, 0.1);
        }
        
        .session-card.active {
            border-color: #8b5cf6;
            background: linear-gradient(135deg, #f5f3ff 0%, #eef2ff 100%);
        }
        
        .session-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .session-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .session-meta {
            flex: 1;
        }
        
        .session-meta h4 {
            color: #1f2937;
            font-size: 16px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .session-meta p {
            color: #6b7280;
            font-size: 12px;
        }
        
        .session-stats {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }
        
        .stat-badge {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            background: #f3f4f6;
            border-radius: 20px;
            font-size: 12px;
            color: #4b5563;
        }
        
        /* Student Grid */
        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .student-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .student-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .student-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
            color: white;
        }
        
        .student-info h4 {
            color: #1f2937;
            font-size: 16px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .student-info p {
            color: #6b7280;
            font-size: 12px;
        }
        
        /* Emotion Display */
        .emotion-display {
            margin: 15px 0;
        }
        
        .emotion-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .emotion-happy {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .emotion-bored {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        
        .emotion-neutral {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #374151;
        }
        
        .emotion-sad {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        
        .emotion-angry {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #7f1d1d;
        }
        
        /* Engagement Meter */
        .engagement-meter {
            margin-top: 15px;
        }
        
        .engagement-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .engagement-label span {
            font-size: 12px;
            color: #6b7280;
        }
        
        .engagement-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .engagement-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        /* Real-time Stats */
        .real-time-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-card-small {
            background: #f8fafc;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }
        
        .stat-value-small {
            font-size: 24px;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .stat-label-small {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        
        /* Chart Container */
        .chart-container {
            height: 250px;
            position: relative;
            margin-top: 20px;
        }
        
        /* Alert Panel */
        .alert-panel {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .alert-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .alert-warning {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }
        
        .alert-danger {
            border-left-color: #ef4444;
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
        }
        
        .alert-success {
            border-left-color: #10b981;
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
        }
        
        .alert-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }
        
        .alert-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        
        .alert-warning .alert-icon {
            background: #f59e0b;
            color: white;
        }
        
        .alert-content h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .alert-content p {
            font-size: 12px;
            color: #6b7280;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        /* FIX 2: Added margin to separate Quick Actions from Alerts */
        .quick-actions-card {
            margin-top: 40px; /* Increased from default spacing */
        }
        
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
            justify-content: center;
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
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #4b5563;
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
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        /* Error Message */
        .error-message {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 1px solid #ef4444;
            color: #7f1d1d;
            padding: 15px 20px;
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
        
        /* Responsive */
        @media (max-width: 1200px) {
            .monitoring-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .topbar {
                padding: 0 20px;
                height: 70px;
            }
            
            .content-wrapper {
                padding: 20px;
            }
            
            .student-grid {
                grid-template-columns: 1fr;
            }
            
            .session-grid {
                grid-template-columns: 1fr;
            }
            
            .real-time-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
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
            <a href="teacher_emotion_monitoring.php" class="menu-item active">
                <i class="menu-icon fas fa-eye"></i>
                <span>Emotion Monitoring</span>
            </a>
            
            <div class="menu-title">Analytics</div>
            <a href="teacher_reports.php" class="menu-item">
                <i class="menu-icon fas fa-chart-bar"></i>
                <span>Reports</span>
            </a>
            <a href="teacher_students.php" class="menu-item">
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
                <h2>Emotion Monitoring</h2>
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
            <?php if (isset($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Error:</strong> <?php echo $error_message; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Session Selector -->
            <div class="session-selector">
                <h3><i class="fas fa-video"></i> Active Live Sessions</h3>
                <?php if (empty($active_sessions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-video-slash"></i>
                        <h3>No Active Sessions</h3>
                        <p>Start a live session from your dashboard to begin emotion monitoring</p>
                        <a href="teacher_dashboard.php" class="btn btn-primary" style="display: inline-flex;">
                            <i class="fas fa-arrow-left"></i>
                            <span>Back to Dashboard</span>
                        </a>
                    </div>
                <?php else: ?>
                    <!-- FIX 3: Add scrollable class based on number of sessions -->
                    <div class="session-grid <?php echo count($active_sessions) >= 3 ? 'scrollable' : ''; ?>" id="sessionGrid">
                        <?php $is_first_session = true; ?>
                        <?php foreach ($active_sessions as $session): ?>
                            <div class="session-card <?php echo $is_first_session ? 'active' : ''; ?>" 
                                 data-session-id="<?php echo $session['id']; ?>"
                                 onclick="selectSession(<?php echo $session['id']; ?>, this)">
                                <div class="session-info">
                                    <div class="session-icon">
                                        <i class="fas fa-chalkboard"></i>
                                    </div>
                                    <div class="session-meta">
                                        <h4><?php echo htmlspecialchars($session['session_name'] ?? 'Live Session'); ?></h4>
                                        <p><?php echo htmlspecialchars($session['class_name']); ?> • <?php echo date('h:i A', strtotime($session['start_time'])); ?></p>
                                    </div>
                                </div>
                                <div class="session-stats">
                                    <span class="stat-badge">
                                        <i class="fas fa-users"></i>
                                        <?php echo isset($session_participants[$session['id']]) ? count($session_participants[$session['id']]) : 0; ?> Students
                                    </span>
                                    <span class="stat-badge">
                                        <i class="fas fa-clock"></i>
                                        <?php echo relativeTime($session['start_time']); ?>
                                    </span>
                                </div>
                            </div>
                            <?php $is_first_session = false; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($active_sessions)): ?>
            <div class="monitoring-grid">
                <!-- Main Section -->
                <div class="main-section">
                    <!-- Real-time Student Emotions -->
                    <div class="monitoring-card">
                        <h2><i class="fas fa-users"></i> Real-time Student Emotions</h2>
                        
                        <?php 
                        // Get first session ID for initial display
                        $first_session_id = reset($active_sessions)['id'];
                        $current_participants = $session_participants[$first_session_id] ?? [];
                        ?>
                        
                        <?php if (empty($current_participants)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-graduate"></i>
                                <h3>No Students Connected</h3>
                                <p>Students will appear here when they join your live session</p>
                            </div>
                        <?php else: ?>
                            <div class="student-grid" id="studentGrid">
                                <?php foreach ($current_participants as $student): ?>
                                    <div class="student-card" id="student-<?php echo $student['user_id']; ?>">
                                        <div class="student-header">
                                            <div class="student-avatar">
                                                <?php echo getInitials($student['full_name']); ?>
                                            </div>
                                            <div class="student-info">
                                                <h4><?php echo htmlspecialchars($student['full_name']); ?></h4>
                                                <p><?php echo htmlspecialchars($student['student_number'] ?? 'N/A'); ?></p>
                                                <p style="font-size: 11px; color: #9ca3af;">
                                                    Joined <?php echo relativeTime($student['join_time']); ?>
                                                </p>
                                            </div>
                                        </div>
                                        
                                        <div class="emotion-display">
                                            <?php 
                                            $emotion = $recent_emotions[$first_session_id][$student['user_id']] ?? null;
                                            if ($emotion): 
                                                $emotion_class = 'emotion-' . $emotion['facial_emotion'];
                                                $emotion_icon = getEmotionIcon($emotion['facial_emotion']);
                                            ?>
                                                <div class="emotion-badge <?php echo $emotion_class; ?>">
                                                    <i class="fas fa-<?php echo $emotion_icon; ?>"></i>
                                                    <?php echo ucfirst($emotion['facial_emotion']); ?>
                                                    <span style="font-size: 12px; opacity: 0.8;">
                                                        (<?php echo round($emotion['confidence_score'] ?? 0); ?>%)
                                                    </span>
                                                </div>
                                                
                                                <div class="engagement-meter">
                                                    <div class="engagement-label">
                                                        <span>Engagement Level</span>
                                                        <span><?php echo $emotion['engagement_level'] ?? 0; ?>%</span>
                                                    </div>
                                                    <div class="engagement-bar">
                                                        <div class="engagement-fill" style="width: <?php echo $emotion['engagement_level'] ?? 0; ?>%"></div>
                                                    </div>
                                                </div>
                                                
                                                <div style="font-size: 11px; color: #9ca3af; margin-top: 10px;">
                                                    <i class="far fa-clock"></i> 
                                                    Updated <?php echo relativeTime($emotion['captured_at'] ?? ''); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="emotion-badge emotion-neutral">
                                                    <i class="fas fa-question-circle"></i>
                                                    No Data Yet
                                                </div>
                                                <p style="font-size: 12px; color: #9ca3af; margin-top: 10px;">
                                                    Waiting for emotion data...
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                                            <button class="btn btn-secondary" onclick="focusStudent(<?php echo $student['user_id']; ?>)" style="flex: 1;">
                                                <i class="fas fa-eye"></i>
                                                <span>Focus</span>
                                            </button>
                                            <button class="btn" onclick="sendAlert(<?php echo $student['user_id']; ?>)" style="flex: 1; background: #fef3c7; color: #92400e;">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <span>Alert</span>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Session Statistics -->
                    <div class="monitoring-card">
                        <h2><i class="fas fa-chart-bar"></i> Session Statistics</h2>
                        
                        <div class="real-time-stats">
                            <div class="stat-card-small">
                                <div class="stat-value-small" id="avgEngagement">
                                    <?php echo round($session_stats[$first_session_id]['avg_engagement'] ?? 0, 1); ?>%
                                </div>
                                <div class="stat-label-small">Avg Engagement</div>
                            </div>
                            <div class="stat-card-small">
                                <div class="stat-value-small" id="totalReadings">
                                    <?php echo $session_stats[$first_session_id]['total_readings'] ?? 0; ?>
                                </div>
                                <div class="stat-label-small">Total Readings</div>
                            </div>
                            <div class="stat-card-small">
                                <div class="stat-value-small" id="happyCount">
                                    <?php echo $session_stats[$first_session_id]['happy_count'] ?? 0; ?>
                                </div>
                                <div class="stat-label-small">Happy</div>
                            </div>
                            <div class="stat-card-small">
                                <div class="stat-value-small" id="boredCount">
                                    <?php echo $session_stats[$first_session_id]['bored_count'] ?? 0; ?>
                                </div>
                                <div class="stat-label-small">Bored</div>
                            </div>
                        </div>
                        
                        <div class="chart-container">
                            <canvas id="emotionDistributionChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar Section -->
                <div class="sidebar-section">
                    <!-- Alert Panel -->
                    <div class="monitoring-card">
                        <h2><i class="fas fa-bell"></i> Alerts & Notifications</h2>
                        <div class="alert-panel" id="alertPanel">
                            <?php 
                            $alerts_found = false;
                            if (!empty($current_participants)): 
                                foreach ($current_participants as $student):
                                    $emotion = $recent_emotions[$first_session_id][$student['user_id']] ?? null;
                                    if ($emotion && $emotion['facial_emotion'] == 'bored' && ($emotion['engagement_level'] ?? 0) < 40): 
                                        $alerts_found = true;
                            ?>
                                        <div class="alert-card alert-warning">
                                            <div class="alert-header">
                                                <div class="alert-icon">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </div>
                                                <div class="alert-content">
                                                    <h4>Low Engagement Alert</h4>
                                                    <p><?php echo htmlspecialchars($student['full_name']); ?> appears bored</p>
                                                    <p style="font-size: 11px; margin-top: 5px;">Engagement: <?php echo $emotion['engagement_level'] ?? 0; ?>%</p>
                                                </div>
                                            </div>
                                        </div>
                            <?php 
                                    endif;
                                endforeach;
                            endif; 
                            
                            if (!$alerts_found): 
                            ?>
                                <div style="text-align: center; padding: 20px; color: #9ca3af;">
                                    <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 10px;"></i>
                                    <p>No alerts at the moment</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- FIX 4: Quick Actions with added class for more spacing -->
                    <div class="monitoring-card quick-actions-card">
                        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                        <div class="quick-actions">
                            <button class="btn btn-primary" onclick="refreshData()">
                                <i class="fas fa-sync-alt"></i>
                                <span>Refresh Data</span>
                            </button>
                            <button class="btn btn-success" onclick="startEmotionCapture()">
                                <i class="fas fa-play"></i>
                                <span>Start Capture</span>
                            </button>
                            <button class="btn btn-danger" onclick="stopEmotionCapture()">
                                <i class="fas fa-stop"></i>
                                <span>Stop Capture</span>
                            </button>
                            <button class="btn btn-secondary" onclick="exportEmotionData()">
                                <i class="fas fa-download"></i>
                                <span>Export Data</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Global variables
        let currentSessionId = <?php echo !empty($active_sessions) ? reset($active_sessions)['id'] : 'null'; ?>;
        let refreshInterval = null;
        let emotionChart = null;
        
        // Helper function to get emotion icon
        function getEmotionIcon(emotion) {
            const icons = {
                'happy': 'smile',
                'sad': 'frown',
                'angry': 'angry',
                'bored': 'meh',
                'neutral': 'meh-blank'
            };
            return icons[emotion] || 'question-circle';
        }
        
        // Select session
        function selectSession(sessionId, element) {
            currentSessionId = sessionId;
            
            // Update UI
            document.querySelectorAll('.session-card').forEach(card => {
                card.classList.remove('active');
            });
            element.classList.add('active');
            
            // Refresh data for selected session
            refreshData();
        }
        
        // FIX 5: Add function to check and apply scrollbar dynamically
        function checkSessionScrollbar() {
            const sessionGrid = document.getElementById('sessionGrid');
            if (!sessionGrid) return;
            
            const sessionCards = sessionGrid.querySelectorAll('.session-card');
            if (sessionCards.length >= 3) {
                sessionGrid.classList.add('scrollable');
            } else {
                sessionGrid.classList.remove('scrollable');
            }
        }
        
        // Refresh emotion data
        function refreshData() {
            if (!currentSessionId) {
                console.log('No session selected');
                return;
            }
            
            // Show loading state
            const studentGrid = document.getElementById('studentGrid');
            if (studentGrid) {
                studentGrid.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loading-spinner" style="width: 40px; height: 40px; border: 4px solid #f3f4f6; border-top: 4px solid #8b5cf6; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div><p style="margin-top: 10px; color: #6b7280;">Loading emotion data...</p></div>';
            }
            
            // Using fetch API to get updated data
            fetch('get_emotion_data.php?session_id=' + currentSessionId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        console.error('Error:', data.error);
                        showMessage('Error loading data: ' + data.error, 'error');
                        return;
                    }
                    
                    if (data.success) {
                        updateStudentGrid(data.students);
                        updateSessionStats(data.stats);
                        updateAlerts(data.alerts);
                        updateChart(data.stats);
                        showMessage('Data refreshed successfully', 'success');
                    }
                })
                .catch(error => {
                    console.error('Error refreshing data:', error);
                    showMessage('Error refreshing data. Please try again.', 'error');
                    
                    // Fallback to static data for testing
                    if (studentGrid) {
                        studentGrid.innerHTML = '<div class="empty-state"><i class="fas fa-wifi-slash"></i><h3>Connection Error</h3><p>Unable to fetch real-time data</p></div>';
                    }
                });
        }
        
        // Update student grid with new data
        function updateStudentGrid(students) {
            const studentGrid = document.getElementById('studentGrid');
            if (!studentGrid || !students) return;
            
            if (students.length === 0) {
                studentGrid.innerHTML = '<div class="empty-state"><i class="fas fa-user-graduate"></i><h3>No Students Connected</h3><p>Students will appear here when they join your live session</p></div>';
                return;
            }
            
            let html = '';
            students.forEach(student => {
                const emotion = student.emotion;
                const emotionClass = emotion ? `emotion-${emotion.facial_emotion}` : 'emotion-neutral';
                const emotionIcon = emotion ? getEmotionIcon(emotion.facial_emotion) : 'question-circle';
                
                html += `
                    <div class="student-card" id="student-${student.user_id}">
                        <div class="student-header">
                            <div class="student-avatar">
                                ${student.initials}
                            </div>
                            <div class="student-info">
                                <h4>${student.full_name}</h4>
                                <p>${student.student_number || 'N/A'}</p>
                                <p style="font-size: 11px; color: #9ca3af;">
                                    Joined ${student.join_time}
                                </p>
                            </div>
                        </div>
                        
                        <div class="emotion-display">
                            ${emotion ? `
                                <div class="emotion-badge ${emotionClass}">
                                    <i class="fas fa-${emotionIcon}"></i>
                                    ${emotion.facial_emotion.charAt(0).toUpperCase() + emotion.facial_emotion.slice(1)}
                                    <span style="font-size: 12px; opacity: 0.8;">
                                        (${Math.round(emotion.confidence_score || 0)}%)
                                    </span>
                                </div>
                                
                                <div class="engagement-meter">
                                    <div class="engagement-label">
                                        <span>Engagement Level</span>
                                        <span>${emotion.engagement_level || 0}%</span>
                                    </div>
                                    <div class="engagement-bar">
                                        <div class="engagement-fill" style="width: ${emotion.engagement_level || 0}%"></div>
                                    </div>
                                </div>
                                
                                <div style="font-size: 11px; color: #9ca3af; margin-top: 10px;">
                                    <i class="far fa-clock"></i> 
                                    Updated ${emotion.time_ago}
                                </div>
                            ` : `
                                <div class="emotion-badge emotion-neutral">
                                    <i class="fas fa-question-circle"></i>
                                    No Data Yet
                                </div>
                                <p style="font-size: 12px; color: #9ca3af; margin-top: 10px;">
                                    Waiting for emotion data...
                                </p>
                            `}
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <button class="btn btn-secondary" onclick="focusStudent(${student.user_id})" style="flex: 1;">
                                <i class="fas fa-eye"></i>
                                <span>Focus</span>
                            </button>
                            <button class="btn" onclick="sendAlert(${student.user_id})" style="flex: 1; background: #fef3c7; color: #92400e;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>Alert</span>
                            </button>
                        </div>
                    </div>
                `;
            });
            
            studentGrid.innerHTML = html;
        }
        
        // Update session statistics
        function updateSessionStats(stats) {
            if (!stats) return;
            
            // Update stats display
            const elements = {
                'avgEngagement': stats.avg_engagement ? stats.avg_engagement.toFixed(1) + '%' : '0%',
                'totalReadings': stats.total_readings || 0,
                'happyCount': stats.happy_count || 0,
                'boredCount': stats.bored_count || 0
            };
            
            Object.keys(elements).forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.textContent = elements[id];
                }
            });
        }
        
        // Update emotion distribution chart
        function updateChart(stats) {
            const ctx = document.getElementById('emotionDistributionChart');
            if (!ctx) return;
            
            // Destroy existing chart if it exists
            if (emotionChart) {
                emotionChart.destroy();
            }
            
            // Create new chart
            emotionChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Happy', 'Bored', 'Neutral', 'Sad', 'Angry'],
                    datasets: [{
                        data: [
                            stats.happy_count || 0,
                            stats.bored_count || 0,
                            stats.neutral_count || 0,
                            stats.sad_count || 0,
                            stats.angry_count || 0
                        ],
                        backgroundColor: [
                            '#10b981',
                            '#f59e0b',
                            '#6b7280',
                            '#3b82f6',
                            '#ef4444'
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 11
                                },
                                padding: 20
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
        }
        
        // Update alerts
        function updateAlerts(alerts) {
            const alertPanel = document.getElementById('alertPanel');
            if (!alertPanel) return;
            
            if (!alerts || alerts.length === 0) {
                alertPanel.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #9ca3af;">
                        <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 10px;"></i>
                        <p>No alerts at the moment</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            alerts.forEach(alert => {
                html += `
                    <div class="alert-card alert-${alert.type || 'warning'}">
                        <div class="alert-header">
                            <div class="alert-icon">
                                <i class="fas fa-${alert.icon || 'exclamation-triangle'}"></i>
                            </div>
                            <div class="alert-content">
                                <h4>${alert.title || 'Alert'}</h4>
                                <p>${alert.message || ''}</p>
                                ${alert.details ? `<p style="font-size: 11px; margin-top: 5px;">${alert.details}</p>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            alertPanel.innerHTML = html;
        }
        
        // Focus on specific student
        function focusStudent(studentId) {
            const studentCard = document.getElementById(`student-${studentId}`);
            if (studentCard) {
                studentCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                studentCard.style.boxShadow = '0 0 0 3px rgba(139, 92, 246, 0.3)';
                studentCard.style.transition = 'box-shadow 0.3s ease';
                
                setTimeout(() => {
                    studentCard.style.boxShadow = '';
                }, 2000);
                
                showMessage('Focused on student', 'success');
            }
        }
        
        // Send alert to student
        function sendAlert(studentId) {
            if (!confirm('Send an alert to this student?')) return;
            
            // For now, just show a message
            showMessage(`Alert sent to student (ID: ${studentId})`, 'success');
            
            // In a real implementation, you would make an API call here
            /*
            fetch('send_student_alert.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    student_id: studentId,
                    session_id: currentSessionId,
                    message: 'Please pay attention'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Alert sent to student successfully!', 'success');
                } else {
                    showMessage('Failed to send alert: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showMessage('Error sending alert', 'error');
            });
            */
        }
        
        // Show message
        function showMessage(message, type = 'info') {
            // Create message element
            const messageDiv = document.createElement('div');
            messageDiv.className = `alert-card alert-${type}`;
            messageDiv.style.position = 'fixed';
            messageDiv.style.top = '20px';
            messageDiv.style.right = '20px';
            messageDiv.style.zIndex = '9999';
            messageDiv.style.minWidth = '300px';
            messageDiv.innerHTML = `
                <div class="alert-header">
                    <div class="alert-icon">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    </div>
                    <div class="alert-content">
                        <p>${message}</p>
                    </div>
                </div>
            `;
            
            document.body.appendChild(messageDiv);
            
            // Remove after 3 seconds
            setTimeout(() => {
                messageDiv.style.opacity = '0';
                messageDiv.style.transition = 'opacity 0.3s ease';
                setTimeout(() => {
                    if (messageDiv.parentNode) {
                        messageDiv.parentNode.removeChild(messageDiv);
                    }
                }, 300);
            }, 3000);
        }
        
        // Start emotion capture
        function startEmotionCapture() {
            if (!currentSessionId) {
                showMessage('Please select a session first', 'error');
                return;
            }
            
            showMessage('Starting emotion capture...', 'info');
            
            // Start auto-refresh
            startAutoRefresh();
            
            // In a real implementation, you would make an API call here
            /*
            fetch('start_emotion_capture.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    session_id: currentSessionId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Emotion capture started!', 'success');
                    startAutoRefresh();
                } else {
                    showMessage('Failed to start capture: ' + data.message, 'error');
                }
            });
            */
        }
        
        // Stop emotion capture
        function stopEmotionCapture() {
            if (!currentSessionId) {
                showMessage('Please select a session first', 'error');
                return;
            }
            
            showMessage('Stopping emotion capture...', 'info');
            
            // Stop auto-refresh
            stopAutoRefresh();
            
            // In a real implementation, you would make an API call here
            /*
            fetch('stop_emotion_capture.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    session_id: currentSessionId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Emotion capture stopped!', 'success');
                    stopAutoRefresh();
                } else {
                    showMessage('Failed to stop capture: ' + data.message, 'error');
                }
            });
            */
        }
        
        // Start auto-refresh
        function startAutoRefresh() {
            if (refreshInterval) clearInterval(refreshInterval);
            refreshInterval = setInterval(refreshData, 5000); // Refresh every 5 seconds
            showMessage('Auto-refresh enabled (every 5 seconds)', 'success');
        }
        
        // Stop auto-refresh
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
                refreshInterval = null;
                showMessage('Auto-refresh disabled', 'info');
            }
        }
        
        // Export emotion data
        function exportEmotionData() {
            if (!currentSessionId) {
                showMessage('Please select a session first', 'error');
                return;
            }
            
            showMessage('Exporting emotion data...', 'info');
            
            // In a real implementation, this would download a file
            window.open(`export_emotion_data.php?session_id=${currentSessionId}`, '_blank');
        }
        
        // Initialize chart on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($active_sessions)): ?>
                // Initialize chart with initial data
                const stats = <?php echo json_encode($session_stats[reset($active_sessions)['id']] ?? []); ?>;
                updateChart(stats);
            <?php endif; ?>
            
            // FIX 6: Check for session scrollbar on load
            checkSessionScrollbar();
            
            // Add user menu functionality
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
            }
            
            // Add CSS for loading spinner animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
        });
        
        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            stopAutoRefresh();
        });
    </script>
</body>
</html>

<?php
// Helper function to get emotion icon (defined at end to avoid conflicts)
function getEmotionIcon($emotion) {
    $icons = [
        'happy' => 'smile',
        'sad' => 'frown',
        'angry' => 'angry',
        'bored' => 'meh',
        'neutral' => 'meh-blank'
    ];
    return $icons[$emotion] ?? 'question-circle';
}
?>