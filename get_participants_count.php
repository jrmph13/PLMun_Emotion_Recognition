<?php
require_once 'config.php';

$session_id = $_GET['session_id'] ?? 0;

try {
    // Get active participants count
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT user_id) as count 
        FROM live_session_participants 
        WHERE session_id = ? AND is_active = 1
    ");
    $stmt->execute([$session_id]);
    $result = $stmt->fetch();
    $count = $result['count'] ?? 0;
    
    // Get emotion data count
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM emotion_data WHERE session_id = ?");
    $stmt->execute([$session_id]);
    $emotion_result = $stmt->fetch();
    $emotion_count = $emotion_result['count'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'count' => $count,
        'emotion_count' => $emotion_count
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>