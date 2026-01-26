<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$session_id = $data['session_id'] ?? 0;
$student_id = $data['student_id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        UPDATE live_session_participants 
        SET is_active = 0, leave_time = NOW()
        WHERE session_id = ? AND user_id = ?
    ");
    $stmt->execute([$session_id, $student_id]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>