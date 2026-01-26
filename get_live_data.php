<?php
require_once 'config.php';

// Only allow AJAX requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit();
}

$sessionId = $_GET['session_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;

if (!$sessionId || !$userId) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

try {
    // Verify ownership
    $stmt = $pdo->prepare("
        SELECT c.instructor_id 
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        WHERE ls.id = ?
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    
    if (!$session || $session['instructor_id'] != $userId) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    // Get current stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT sa.student_id) as attendance,
            COALESCE(AVG(ses.engagement_score), 0) as engagement
        FROM " . TABLE_LIVE_SESSIONS . " ls
        LEFT JOIN " . TABLE_SESSION_ATTENDANCE . " sa ON ls.id = sa.session_id
        LEFT JOIN " . TABLE_SESSION_ENGAGEMENT_SUMMARY . " ses ON ls.id = ses.session_id
        WHERE ls.id = ?
    ");
    $stmt->execute([$sessionId]);
    $stats = $stmt->fetch();
    
    // Get emotion counts
    $stmt = $pdo->prepare("
        SELECT 
            facial_emotion,
            COUNT(*) as count
        FROM " . TABLE_EMOTION_DATA . "
        WHERE session_id = ? 
        AND captured_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        GROUP BY facial_emotion
    ");
    $stmt->execute([$sessionId]);
    $emotions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Default emotion counts
    $defaultEmotions = ['happy' => 0, 'neutral' => 0, 'bored' => 0, 'sad' => 0, 'angry' => 0];
    $emotions = array_merge($defaultEmotions, $emotions);
    
    echo json_encode([
        'success' => true,
        'attendance' => $stats['attendance'] ?? 0,
        'engagement' => round($stats['engagement'] ?? 0),
        'emotions' => $emotions
    ]);
    
} catch (PDOException $e) {
    error_log("Get Live Data Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>