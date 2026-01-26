<?php
require_once 'config.php';

header('Content-Type: application/json');

// Check if instructor is logged in
if (!isInstructor() && !isAdmin()) {
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$session_id = $_GET['session_id'] ?? null;

if (!$session_id) {
    echo json_encode(['error' => 'Session ID is required']);
    exit;
}

try {
    // Get session details
    $stmt = $pdo->prepare("
        SELECT ls.*, c.class_name 
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        WHERE ls.id = ? AND c.instructor_id = ?
    ");
    $stmt->execute([$session_id, $_SESSION['user_id']]);
    $session = $stmt->fetch();
    
    if (!$session) {
        echo json_encode(['error' => 'Session not found or access denied']);
        exit;
    }
    
    // Get participants
    $stmt = $pdo->prepare("
        SELECT 
            lsp.user_id,
            u.full_name,
            s.student_number,
            lsp.join_time
        FROM " . TABLE_LIVE_SESSION_PARTICIPANTS . " lsp
        JOIN " . TABLE_USERS . " u ON lsp.user_id = u.id
        LEFT JOIN " . TABLE_STUDENTS . " s ON u.id = s.user_id
        WHERE lsp.session_id = ? 
        AND lsp.user_role = 'student'
        AND lsp.is_active = 1
    ");
    $stmt->execute([$session_id]);
    $participants = $stmt->fetchAll();
    
    // Get recent emotion data for each participant
    $students = [];
    foreach ($participants as $participant) {
        $stmt = $pdo->prepare("
            SELECT 
                ed.facial_emotion,
                ed.confidence_score,
                ed.engagement_level,
                ed.captured_at
            FROM " . TABLE_EMOTION_DATA . " ed
            JOIN " . TABLE_STUDENTS . " s ON ed.student_id = s.id
            WHERE ed.session_id = ? 
            AND s.user_id = ?
            ORDER BY ed.captured_at DESC
            LIMIT 1
        ");
        $stmt->execute([$session_id, $participant['user_id']]);
        $emotion_data = $stmt->fetch();
        
        $students[] = [
            'user_id' => $participant['user_id'],
            'full_name' => $participant['full_name'],
            'student_number' => $participant['student_number'],
            'initials' => getInitials($participant['full_name']),
            'join_time' => relativeTime($participant['join_time']),
            'emotion' => $emotion_data ? [
                'facial_emotion' => $emotion_data['facial_emotion'],
                'confidence_score' => $emotion_data['confidence_score'],
                'engagement_level' => $emotion_data['engagement_level'],
                'time_ago' => relativeTime($emotion_data['captured_at'])
            ] : null
        ];
    }
    
    // Get session statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT ed.id) as total_readings,
            AVG(ed.engagement_level) as avg_engagement,
            COUNT(CASE WHEN ed.facial_emotion = 'happy' THEN 1 END) as happy_count,
            COUNT(CASE WHEN ed.facial_emotion = 'bored' THEN 1 END) as bored_count,
            COUNT(CASE WHEN ed.facial_emotion = 'neutral' THEN 1 END) as neutral_count,
            COUNT(CASE WHEN ed.facial_emotion = 'sad' THEN 1 END) as sad_count,
            COUNT(CASE WHEN ed.facial_emotion = 'angry' THEN 1 END) as angry_count
        FROM " . TABLE_EMOTION_DATA . " ed
        WHERE ed.session_id = ?
        AND ed.captured_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute([$session_id]);
    $stats = $stmt->fetch();
    
    // Generate alerts
    $alerts = [];
    foreach ($students as $student) {
        if ($student['emotion'] && 
            $student['emotion']['facial_emotion'] == 'bored' && 
            $student['emotion']['engagement_level'] < 40) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'exclamation-triangle',
                'title' => 'Low Engagement Alert',
                'message' => $student['full_name'] . ' appears bored',
                'details' => 'Engagement: ' . $student['emotion']['engagement_level'] . '%'
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'students' => $students,
        'stats' => $stats,
        'alerts' => $alerts,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    error_log("Get Emotion Data Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error occurred']);
}
?>