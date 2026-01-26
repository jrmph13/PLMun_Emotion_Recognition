<?php
// ==================== STUDENT MY ENGAGEMENT ====================
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
$error_message = '';
$success_message = '';

// Time period filter
$time_period = $_GET['period'] ?? 'month';
$allowed_periods = ['week', 'month', 'quarter', 'year', 'all'];
if (!in_array($time_period, $allowed_periods)) {
    $time_period = 'month';
}

// Calculate date ranges
$date_ranges = [
    'week' => ['start' => date('Y-m-d', strtotime('-7 days')), 'label' => 'Last 7 Days'],
    'month' => ['start' => date('Y-m-d', strtotime('-30 days')), 'label' => 'Last 30 Days'],
    'quarter' => ['start' => date('Y-m-d', strtotime('-90 days')), 'label' => 'Last 90 Days'],
    'year' => ['start' => date('Y-m-d', strtotime('-365 days')), 'label' => 'Last 365 Days'],
    'all' => ['start' => '2000-01-01', 'label' => 'All Time']
];

$start_date = $date_ranges[$time_period]['start'];
$period_label = $date_ranges[$time_period]['label'];

// Initialize arrays to prevent undefined variable errors
$engagementStats = [];
$emotionStats = [];
$sessionEngagement = [];
$weeklyTrends = [];
$classEngagement = [];
$topSessions = [];
$recommendations = [];

try {
    if ($studentId) {
        // Get overall engagement statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT ses.session_id) as sessions_analyzed,
                AVG(ses.engagement_score) as avg_engagement,
                MIN(ses.engagement_score) as min_engagement,
                MAX(ses.engagement_score) as max_engagement,
                AVG(ses.happy_percent) as avg_happy,
                AVG(ses.bored_percent) as avg_bored,
                AVG(ses.neutral_percent) as avg_neutral,
                (SELECT COUNT(DISTINCT session_id) FROM " . TABLE_SESSION_ATTENDANCE . " WHERE student_id = ?) as total_attended
            FROM " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses
            JOIN " . TABLE_LIVE_SESSIONS . " ls ON ses.session_id = ls.id
            WHERE ses.student_id = ?
            AND ls.start_time >= ?
        ");
        $stmt->execute([$studentId, $studentId, $start_date]);
        $engagementStats = $stmt->fetch();
        
        // Get emotion distribution for the period
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_samples,
                SUM(CASE WHEN ed.facial_emotion = 'happy' THEN 1 ELSE 0 END) as happy_count,
                SUM(CASE WHEN ed.facial_emotion = 'bored' THEN 1 ELSE 0 END) as bored_count,
                SUM(CASE WHEN ed.facial_emotion = 'neutral' THEN 1 ELSE 0 END) as neutral_count,
                SUM(CASE WHEN ed.facial_emotion = 'sad' THEN 1 ELSE 0 END) as sad_count,
                SUM(CASE WHEN ed.facial_emotion = 'angry' THEN 1 ELSE 0 END) as angry_count
            FROM " . TABLE_EMOTION_DATA . " ed
            JOIN " . TABLE_LIVE_SESSIONS . " ls ON ed.session_id = ls.id
            WHERE ed.student_id = ?
            AND ls.start_time >= ?
        ");
        $stmt->execute([$studentId, $start_date]);
        $emotionStats = $stmt->fetch();
        
        // Calculate percentages
        if ($emotionStats && $emotionStats['total_samples'] > 0) {
            $emotionStats['happy_percent'] = round(($emotionStats['happy_count'] / $emotionStats['total_samples']) * 100, 1);
            $emotionStats['bored_percent'] = round(($emotionStats['bored_count'] / $emotionStats['total_samples']) * 100, 1);
            $emotionStats['neutral_percent'] = round(($emotionStats['neutral_count'] / $emotionStats['total_samples']) * 100, 1);
            $emotionStats['sad_percent'] = round(($emotionStats['sad_count'] / $emotionStats['total_samples']) * 100, 1);
            $emotionStats['angry_percent'] = round(($emotionStats['angry_count'] / $emotionStats['total_samples']) * 100, 1);
        } else {
            // Initialize default values if no data
            $emotionStats = [
                'total_samples' => 0,
                'happy_percent' => 0,
                'bored_percent' => 0,
                'neutral_percent' => 0,
                'sad_percent' => 0,
                'angry_percent' => 0
            ];
        }
        
        // Get session-by-session engagement data
        $stmt = $pdo->prepare("
            SELECT 
                ls.id,
                ls.session_name,
                ls.start_time,
                c.class_name,
                c.class_code,
                ses.engagement_score,
                ses.happy_percent,
                ses.bored_percent,
                ses.neutral_percent,
                ses.average_emotion
            FROM " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses
            JOIN " . TABLE_LIVE_SESSIONS . " ls ON ses.session_id = ls.id
            JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
            WHERE ses.student_id = ?
            AND ls.start_time >= ?
            ORDER BY ls.start_time DESC
            LIMIT 20
        ");
        $stmt->execute([$studentId, $start_date]);
        $sessionEngagement = $stmt->fetchAll();
        
        // Get engagement trends over time (weekly)
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(ls.start_time, '%Y-%u') as week,
                DATE_FORMAT(MIN(ls.start_time), '%b %d') as week_label,
                AVG(ses.engagement_score) as avg_engagement,
                AVG(ses.happy_percent) as avg_happy,
                AVG(ses.bored_percent) as avg_bored,
                COUNT(DISTINCT ses.session_id) as sessions_count
            FROM " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses
            JOIN " . TABLE_LIVE_SESSIONS . " ls ON ses.session_id = ls.id
            WHERE ses.student_id = ?
            AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
            GROUP BY DATE_FORMAT(ls.start_time, '%Y-%u')
            ORDER BY week DESC
            LIMIT 12
        ");
        $stmt->execute([$studentId]);
        $weeklyTrends = $stmt->fetchAll();
        $weeklyTrends = array_reverse($weeklyTrends); // Reverse for chronological order
        
        // Get class-wise engagement
        $stmt = $pdo->prepare("
            SELECT 
                c.class_name,
                c.class_code,
                COUNT(DISTINCT ses.session_id) as sessions_count,
                AVG(ses.engagement_score) as avg_engagement,
                AVG(ses.happy_percent) as avg_happy,
                AVG(ses.bored_percent) as avg_bored
            FROM " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses
            JOIN " . TABLE_LIVE_SESSIONS . " ls ON ses.session_id = ls.id
            JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
            WHERE ses.student_id = ?
            AND ls.start_time >= ?
            GROUP BY c.id
            ORDER BY avg_engagement DESC
        ");
        $stmt->execute([$studentId, $start_date]);
        $classEngagement = $stmt->fetchAll();
        
        // Get top performing sessions
        $stmt = $pdo->prepare("
            SELECT 
                ls.session_name,
                ls.start_time,
                c.class_name,
                ses.engagement_score,
                ses.happy_percent,
                ses.bored_percent
            FROM " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses
            JOIN " . TABLE_LIVE_SESSIONS . " ls ON ses.session_id = ls.id
            JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
            WHERE ses.student_id = ?
            AND ls.start_time >= ?
            ORDER BY ses.engagement_score DESC
            LIMIT 5
        ");
        $stmt->execute([$studentId, $start_date]);
        $topSessions = $stmt->fetchAll();
        
        // Generate recommendations based on engagement data
        $recommendations = generateEngagementRecommendations($engagementStats, $emotionStats);
        
    } else {
        $error_message = "Student profile not found.";
    }
    
} catch (PDOException $e) {
    error_log("Engagement Query Error: " . $e->getMessage());
    $error_message = "An error occurred while loading engagement data.";
}

// Helper function to generate recommendations
function generateEngagementRecommendations($stats, $emotionStats) {
    $recommendations = [];
    
    if (!$stats || $stats['sessions_analyzed'] == 0) {
        $recommendations[] = [
            'type' => 'info',
            'title' => 'No Data Yet',
            'message' => 'Join more live sessions to generate engagement data.',
            'icon' => 'info-circle'
        ];
        return $recommendations;
    }
    
    // Engagement level recommendations
    if ($stats['avg_engagement'] < 50) {
        $recommendations[] = [
            'type' => 'warning',
            'title' => 'Low Engagement Detected',
            'message' => 'Your engagement level is below average. Try participating more actively in sessions.',
            'icon' => 'exclamation-triangle'
        ];
    } elseif ($stats['avg_engagement'] >= 80) {
        $recommendations[] = [
            'type' => 'success',
            'title' => 'Excellent Engagement',
            'message' => 'Great job! Your engagement level is above average.',
            'icon' => 'trophy'
        ];
    }
    
    // Emotion-based recommendations
    if ($emotionStats['bored_percent'] > 30) {
        $recommendations[] = [
            'type' => 'warning',
            'title' => 'High Boredom Level',
            'message' => 'You show signs of boredom in sessions. Try taking notes or asking questions.',
            'icon' => 'frown'
        ];
    }
    
    if ($emotionStats['happy_percent'] > 40) {
        $recommendations[] = [
            'type' => 'success',
            'title' => 'Positive Emotion Pattern',
            'message' => 'You maintain positive emotions during learning. Keep it up!',
            'icon' => 'smile'
        ];
    }
    
    // Session attendance recommendation
    if ($stats['total_attended'] > 0 && $stats['sessions_analyzed'] / $stats['total_attended'] < 0.7) {
        $recommendations[] = [
            'type' => 'info',
            'title' => 'Improve Session Participation',
            'message' => 'Some sessions lack engagement data. Ensure camera is properly positioned.',
            'icon' => 'camera'
        ];
    }
    
    return $recommendations;
}

// Function to get engagement level label
function getEngagementLabel($score) {
    if ($score >= 80) return ['Excellent', '#10b981', 'trophy'];
    if ($score >= 70) return ['Good', '#3b82f6', 'check-circle'];
    if ($score >= 50) return ['Average', '#f59e0b', 'minus-circle'];
    return ['Needs Improvement', '#ef4444', 'exclamation-circle'];
}

// Function to get emotion color
function getEmotionColor($emotion) {
    $colors = [
        'happy' => '#10b981',
        'neutral' => '#6b7280',
        'bored' => '#f59e0b',
        'sad' => '#3b82f6',
        'angry' => '#ef4444'
    ];
    return $colors[$emotion] ?? '#6b7280';
}

// Function to get emotion icon
function getEmotionIcon($emotion) {
    $icons = [
        'happy' => 'smile',
        'neutral' => 'meh',
        'bored' => 'tired',
        'sad' => 'sad-tear',
        'angry' => 'angry'
    ];
    return $icons[$emotion] ?? 'question-circle';
}

// Check consent status for the sidebar badge
$consentStatus = checkConsentStatus($userId);

// Set page title
$page_title = "My Engagement - " . $site_name;

// Log page access
logAuditTrail(
    $userId,
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    "Accessed my engagement page",
    null,
    null,
    ['page' => 'student_my_engagement', 'period' => $time_period]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Reuse styles from student_dashboard.php */
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
        
        /* Sidebar Styles (same as dashboard) */
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
        
        /* Time Period Filter */
        .time-filter {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            align-items: center;
            flex-wrap: wrap;
        }
        
        .time-filter-btn {
            padding: 8px 16px;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            color: #374151;
        }
        
        .time-filter-btn:hover {
            background: #f3f4f6;
            text-decoration: none;
        }
        
        .time-filter-btn.active {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            border-color: transparent;
        }
        
        /* Engagement Overview Cards */
        .engagement-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .overview-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
        }
        
        .overview-card h3 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .engagement-score {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 10px;
            line-height: 1;
        }
        
        .engagement-label {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 15px;
        }
        
        .score-range {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 12px;
            color: #6b7280;
        }
        
        /* Progress Bars */
        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease;
        }
        
        /* Emotion Distribution */
        .emotion-distribution {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .emotion-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
            border-left: 4px solid;
        }
        
        .emotion-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .emotion-content {
            flex: 1;
        }
        
        .emotion-label {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .emotion-percent {
            font-size: 24px;
            font-weight: 700;
        }
        
        /* Charts Container */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
        }
        
        .chart-card h3 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        /* Recommendations */
        .recommendations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .recommendation-card {
            padding: 20px;
            border-radius: 10px;
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }
        
        .recommendation-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .recommendation-content h4 {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .recommendation-content p {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
        }
        
        /* Session Table */
        .session-table-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .session-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .session-table th {
            background: #f8fafc;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            font-size: 14px;
        }
        
        .session-table td {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            color: #4b5563;
        }
        
        .session-table tr:hover {
            background: #f8fafc;
        }
        
        /* Emotion Indicator */
        .emotion-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: 10px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #e5e7eb;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            font-size: 20px;
        }
        
        /* Alert Messages */
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
        
        .alert-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fca5a5 100%);
            border-color: #ef4444;
            color: #7f1d1d;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .charts-container {
                grid-template-columns: 1fr;
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
            
            .engagement-overview {
                grid-template-columns: 1fr;
            }
            
            .charts-container {
                grid-template-columns: 1fr;
            }
            
            .chart-card {
                padding: 15px;
            }
            
            .chart-container {
                height: 250px;
            }
            
            .session-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 480px) {
            .time-filter {
                flex-wrap: wrap;
            }
            
            .engagement-score {
                font-size: 36px;
            }
            
            .emotion-distribution {
                grid-template-columns: 1fr;
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
            </a>
            
            <a href="student_my_engagement.php" class="menu-item active">
                <i class="menu-icon fas fa-chart-line"></i>
                <span>My Engagement</span>
                <?php if (($engagementStats['avg_engagement'] ?? 0) > 0): ?>
                    <span class="menu-badge"><?php echo round($engagementStats['avg_engagement'], 1); ?>%</span>
                <?php endif; ?>
            </a>
            
            <a href="student_announcement.php" class="menu-item">
                <i class="menu-icon fas fa-bullhorn"></i>
                <span>Announcement</span>
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
                <h2>My Engagement</h2>
            </div>
            <div class="topbar-right">
                <button class="notification-btn" id="notificationBtn">
                    <i class="fas fa-bell"></i>
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
            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Error:</strong> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Time Period Filter -->
            <div class="time-filter">
                <span style="font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-calendar-alt"></i>
                    Viewing: <?php echo $period_label; ?>
                </span>
                <div style="display: flex; gap: 10px; margin-left: auto;">
                    <?php foreach ($allowed_periods as $period): ?>
                        <a href="?period=<?php echo $period; ?>" 
                           class="time-filter-btn <?php echo $time_period === $period ? 'active' : ''; ?>">
                            <?php echo ucfirst($period); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Engagement Overview -->
            <div class="engagement-overview">
                <!-- Overall Engagement Score -->
                <div class="overview-card">
                    <h3><i class="fas fa-chart-line"></i> Overall Engagement</h3>
                    <?php if ($engagementStats && ($engagementStats['sessions_analyzed'] ?? 0) > 0): ?>
                        <?php 
                        $avgScore = round($engagementStats['avg_engagement'] ?? 0, 1);
                        list($label, $color, $icon) = getEngagementLabel($avgScore);
                        ?>
                        <div class="engagement-score" style="color: <?php echo $color; ?>;">
                            <?php echo $avgScore; ?>%
                        </div>
                        <span class="engagement-label" style="background: <?php echo $color; ?>; color: white;">
                            <i class="fas fa-<?php echo $icon; ?>"></i> <?php echo $label; ?>
                        </span>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $avgScore; ?>%; background: <?php echo $color; ?>;"></div>
                        </div>
                        <div class="score-range">
                            <span>0%</span>
                            <span>Range: <?php echo round($engagementStats['min_engagement'] ?? 0, 1); ?>% - <?php echo round($engagementStats['max_engagement'] ?? 0, 1); ?>%</span>
                            <span>100%</span>
                        </div>
                        <div style="margin-top: 15px; font-size: 14px; color: #6b7280;">
                            <i class="fas fa-chart-bar"></i> Based on <?php echo $engagementStats['sessions_analyzed'] ?? 0; ?> session(s)
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 20px 0;">
                            <i class="fas fa-chart-line"></i>
                            <p>No engagement data available</p>
                            <p style="font-size: 12px; margin-top: 10px;">Join live sessions to generate engagement data.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Emotion Summary -->
                <div class="overview-card">
                    <h3><i class="fas fa-smile"></i> Emotion Summary</h3>
                    <?php if ($emotionStats && ($emotionStats['total_samples'] ?? 0) > 0): ?>
                        <div class="emotion-distribution">
                            <?php 
                            $emotions = [
                                'happy' => ['Happy', $emotionStats['happy_percent'] ?? 0],
                                'neutral' => ['Neutral', $emotionStats['neutral_percent'] ?? 0],
                                'bored' => ['Bored', $emotionStats['bored_percent'] ?? 0],
                                'sad' => ['Sad', $emotionStats['sad_percent'] ?? 0],
                                'angry' => ['Angry', $emotionStats['angry_percent'] ?? 0]
                            ];
                            
                            foreach ($emotions as $emotion => $data):
                                $color = getEmotionColor($emotion);
                                $icon = getEmotionIcon($emotion);
                            ?>
                                <div class="emotion-item" style="border-left-color: <?php echo $color; ?>;">
                                    <div class="emotion-icon" style="background: <?php echo $color; ?>;">
                                        <i class="fas fa-<?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="emotion-content">
                                        <div class="emotion-label"><?php echo $data[0]; ?></div>
                                        <div class="emotion-percent"><?php echo $data[1]; ?>%</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 15px; font-size: 14px; color: #6b7280; text-align: center;">
                            <i class="fas fa-database"></i> <?php echo number_format($emotionStats['total_samples'] ?? 0); ?> facial expression samples analyzed
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 20px 0;">
                            <i class="fas fa-smile"></i>
                            <p>No emotion data available</p>
                            <p style="font-size: 12px; margin-top: 10px;">Enable emotion tracking in sessions to collect data.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Charts -->
            <?php if ($engagementStats && ($engagementStats['sessions_analyzed'] ?? 0) > 0): ?>
                <div class="charts-container">
                    <!-- Engagement Trends Chart -->
                    <?php if (!empty($weeklyTrends)): ?>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-line"></i> Engagement Trends</h3>
                        <div class="chart-container">
                            <canvas id="engagementTrendsChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Emotion Distribution Chart -->
                    <?php if (($emotionStats['total_samples'] ?? 0) > 0): ?>
                    <div class="chart-card">
                        <h3><i class="fas fa-chart-pie"></i> Emotion Distribution</h3>
                        <div class="chart-container">
                            <canvas id="emotionDistributionChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Key Statistics -->
                <div class="session-table-container">
                    <h3><i class="fas fa-chart-bar"></i> Key Statistics</h3>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $engagementStats['sessions_analyzed'] ?? 0; ?></div>
                            <div class="stat-label">Sessions Analyzed</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo round($engagementStats['avg_happy'] ?? 0, 1); ?>%</div>
                            <div class="stat-label">Average Happiness</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo round($engagementStats['avg_bored'] ?? 0, 1); ?>%</div>
                            <div class="stat-label">Average Boredom</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $engagementStats['total_attended'] ?? 0; ?></div>
                            <div class="stat-label">Total Sessions Attended</div>
                        </div>
                    </div>
                </div>
                
                <!-- Recommendations -->
                <?php if (!empty($recommendations)): ?>
                    <div class="session-table-container">
                        <h3><i class="fas fa-lightbulb"></i> Personalized Recommendations</h3>
                        <div class="recommendations-grid">
                            <?php foreach ($recommendations as $rec): ?>
                                <div class="recommendation-card" 
                                     style="background: <?php echo $rec['type'] === 'success' ? '#d1fae5' : ($rec['type'] === 'warning' ? '#fef3c7' : '#dbeafe'); ?>;">
                                    <div class="recommendation-icon" 
                                         style="background: <?php echo $rec['type'] === 'success' ? '#10b981' : ($rec['type'] === 'warning' ? '#f59e0b' : '#3b82f6'); ?>; color: white;">
                                        <i class="fas fa-<?php echo $rec['icon']; ?>"></i>
                                    </div>
                                    <div class="recommendation-content">
                                        <h4><?php echo $rec['title']; ?></h4>
                                        <p><?php echo $rec['message']; ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Session Engagement Details -->
                <div class="session-table-container">
                    <h3><i class="fas fa-history"></i> Recent Session Engagement</h3>
                    <?php if (!empty($sessionEngagement)): ?>
                        <div style="overflow-x: auto;">
                            <table class="session-table">
                                <thead>
                                    <tr>
                                        <th>Session</th>
                                        <th>Class</th>
                                        <th>Date</th>
                                        <th>Engagement</th>
                                        <th>Happiness</th>
                                        <th>Boredom</th>
                                        <th>Avg Emotion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessionEngagement as $session): ?>
                                        <?php 
                                        $engagementLabel = getEngagementLabel($session['engagement_score'] ?? 0);
                                        $emotionColor = getEmotionColor($session['average_emotion'] ?? 'neutral');
                                        $emotionIcon = getEmotionIcon($session['average_emotion'] ?? 'neutral');
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($session['session_name'] ?: 'Session'); ?></td>
                                            <td><?php echo htmlspecialchars($session['class_name']); ?></td>
                                            <td><?php echo formatDate($session['start_time'], 'M d, Y'); ?></td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <span style="font-weight: 600; color: <?php echo $engagementLabel[1]; ?>;">
                                                        <?php echo $session['engagement_score'] ?? 0; ?>%
                                                    </span>
                                                    <div class="progress-bar" style="flex: 1; max-width: 100px;">
                                                        <div class="progress-fill" style="width: <?php echo $session['engagement_score'] ?? 0; ?>%; background: <?php echo $engagementLabel[1]; ?>;"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="color: #10b981; font-weight: 600;">
                                                    <?php echo round($session['happy_percent'] ?? 0, 1); ?>%
                                                </span>
                                            </td>
                                            <td>
                                                <span style="color: #f59e0b; font-weight: 600;">
                                                    <?php echo round($session['bored_percent'] ?? 0, 1); ?>%
                                                </span>
                                            </td>
                                            <td>
                                                <div class="emotion-indicator" style="background: <?php echo $emotionColor; ?>20; color: <?php echo $emotionColor; ?>;">
                                                    <i class="fas fa-<?php echo $emotionIcon; ?>"></i>
                                                    <?php echo ucfirst($session['average_emotion'] ?? 'neutral'); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 20px 0;">
                            <i class="fas fa-chart-bar"></i>
                            <p>No session engagement data available for this period</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- No Data State -->
                <div class="session-table-container">
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <h3>No Engagement Data Available</h3>
                        <p>You haven't participated in any sessions with emotion tracking enabled.</p>
                        <div style="margin-top: 20px;">
                            <a href="student_my_classes.php" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">
                                <i class="fas fa-chalkboard-teacher"></i>
                                Join Classes
                            </a>
                            <a href="live_sessions.php" class="btn btn-success" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; margin-left: 10px;">
                                <i class="fas fa-video"></i>
                                View Live Sessions
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
        
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($weeklyTrends) && count($weeklyTrends) > 0): ?>
            // Engagement Trends Chart
            const trendsCtx = document.getElementById('engagementTrendsChart')?.getContext('2d');
            if (trendsCtx) {
                const engagementTrendsChart = new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_column($weeklyTrends, 'week_label')); ?>,
                        datasets: [
                            {
                                label: 'Engagement Score',
                                data: <?php echo json_encode(array_column($weeklyTrends, 'avg_engagement')); ?>,
                                borderColor: '#8b5cf6',
                                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'Happiness',
                                data: <?php echo json_encode(array_column($weeklyTrends, 'avg_happy')); ?>,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                fill: true,
                                tension: 0.4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '%';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                title: {
                                    display: true,
                                    text: 'Percentage (%)'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            <?php endif; ?>
            
            <?php if ($emotionStats && ($emotionStats['total_samples'] ?? 0) > 0): ?>
            // Emotion Distribution Chart
            const emotionCtx = document.getElementById('emotionDistributionChart')?.getContext('2d');
            if (emotionCtx) {
                const emotionDistributionChart = new Chart(emotionCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Happy', 'Neutral', 'Bored', 'Sad', 'Angry'],
                        datasets: [{
                            data: [
                                <?php echo $emotionStats['happy_percent'] ?? 0; ?>,
                                <?php echo $emotionStats['neutral_percent'] ?? 0; ?>,
                                <?php echo $emotionStats['bored_percent'] ?? 0; ?>,
                                <?php echo $emotionStats['sad_percent'] ?? 0; ?>,
                                <?php echo $emotionStats['angry_percent'] ?? 0; ?>
                            ],
                            backgroundColor: [
                                '#10b981',
                                '#6b7280',
                                '#f59e0b',
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
                                position: 'right',
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.label + ': ' + context.raw.toFixed(1) + '%';
                                    }
                                }
                            }
                        },
                        cutout: '60%'
                    }
                });
            }
            <?php endif; ?>
        });
        
        // Animate progress bars
        document.querySelectorAll('.progress-fill').forEach(progress => {
            const width = progress.style.width;
            progress.style.width = '0';
            setTimeout(() => {
                progress.style.width = width;
            }, 500);
        });
        
        // Print engagement report
        function printEngagementReport() {
            window.print();
        }
        
        // Export data as CSV
        function exportEngagementData() {
            alert('Export feature would download engagement data as CSV file.');
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + M to toggle sidebar
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sidebar.classList.toggle('active');
            }
            
            // Ctrl + P to print report
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printEngagementReport();
            }
            
            // Ctrl + E to export data
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportEngagementData();
            }
        });
        
        // Auto-refresh data every 5 minutes
        setInterval(() => {
            // Only refresh if on engagement page and data is loaded
            if (window.location.href.includes('student_my_engagement')) {
                window.location.reload();
            }
        }, 300000); // 5 minutes
    </script>
</body>
</html>