<?php
require_once '../config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$session_id = $data['session_id'] ?? 0;
$class_id = $data['class_id'] ?? 0;
$teacher_id = $data['teacher_id'] ?? 0;
$message = $data['message'] ?? '';

try {
    // Save announcement
    $stmt = $pdo->prepare("
        INSERT INTO announcements (class_id, teacher_id, message)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$class_id, $teacher_id, $message]);
    
    // Also add as chat message
    $stmt = $pdo->prepare("
        INSERT INTO chat_messages (session_id, sender_id, sender_role, message)
        VALUES (?, ?, 'instructor', ?)
    ");
    $stmt->execute([$session_id, $teacher_id, "ANNOUNCEMENT: " . $message]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>