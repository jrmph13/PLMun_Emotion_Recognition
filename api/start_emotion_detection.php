<?php
require_once '../config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$session_id = $data['session_id'] ?? 0;
$class_id = $data['class_id'] ?? 0;

try {
    // Update class emotion tracking
    $stmt = $pdo->prepare("UPDATE classes SET emotion_tracking = 1 WHERE id = ?");
    $stmt->execute([$class_id]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>