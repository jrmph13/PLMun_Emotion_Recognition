<?php
require_once 'config.php';

$session_id = $_GET['session_id'] ?? 0;
$student_id = $_GET['student_id'] ?? 0;

try {
    // Get student basic info
    $stmt = $pdo->prepare("
        SELECT u.full_name, s.student_number
        FROM users u
        JOIN students s ON u.id = s.user_id
        WHERE u.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    // Get latest emotion
    $stmt = $pdo->prepare("
        SELECT facial_emotion, confidence_score
        FROM emotion_data 
        WHERE session_id = ? AND student_id = ?
        ORDER BY captured_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$session_id, $student_id]);
    $latest_emotion = $stmt->fetch();
    
    // Get time in session
    $stmt = $pdo->prepare("
        SELECT TIMESTAMPDIFF(MINUTE, join_time, NOW()) as minutes_in_session
        FROM live_session_participants 
        WHERE session_id = ? AND user_id = ?
    ");
    $stmt->execute([$session_id, $student_id]);
    $time_data = $stmt->fetch();
    
    // Get engagement summary
    $stmt = $pdo->prepare("
        SELECT engagement_score
        FROM session_engagement_summary 
        WHERE session_id = ? AND student_id = ?
    ");
    $stmt->execute([$session_id, $student_id]);
    $engagement = $stmt->fetch();
    
    // Get emotion timeline (last 30 minutes)
    $stmt = $pdo->prepare("
        SELECT captured_at as time, facial_emotion as emotion, confidence_score as confidence
        FROM emotion_data 
        WHERE session_id = ? AND student_id = ? 
        AND captured_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY captured_at DESC
        LIMIT 10
    ");
    $stmt->execute([$session_id, $student_id]);
    $timeline = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'student_name' => $student['full_name'] ?? '',
        'student_number' => $student['student_number'] ?? '',
        'current_emotion' => $latest_emotion['facial_emotion'] ?? 'neutral',
        'confidence' => $latest_emotion['confidence_score'] ?? 0,
        'time_in_session' => $time_data['minutes_in_session'] ?? 0,
        'engagement_score' => $engagement['engagement_score'] ?? 0,
        'timeline' => $timeline
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>